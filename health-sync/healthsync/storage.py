"""Where health-sync keeps its files: inside each user's data folder, next to the app's own data.

    <data>/<user>/garmin/tokens/   Garmin session, readable by its owner only
    <data>/<user>/garmin/state.json  sync progress and the last error
    <data>/<user>/watch/           where synced activities are dropped for the app to import
"""

from __future__ import annotations

import json
import os
import re
import shutil
import tempfile
from dataclasses import asdict, dataclass, field
from pathlib import Path

USER_NAME = re.compile(r"^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$")
SECRET_FILE_NAME = ".health-sync-secret"
# Activity ids already synced. Old ones only matter for a re-sync from the very start, which
# re-downloads at most a few days anyway, so the list stays bounded.
MAX_REMEMBERED_ACTIVITY_IDS = 5000


class UnknownUser(Exception):
    pass


@dataclass
class SyncState:
    since: str | None = None
    display_name: str | None = None
    last_sync_at: str | None = None
    last_attempt_at: str | None = None
    last_activity_date: str | None = None
    last_error: str | None = None
    needs_login: bool = False
    backfilling: bool = False
    # The oldest day of health data fetched so far. The history is fetched backwards from today.
    health_oldest_date: str | None = None
    consecutive_failures: int = 0
    # Set from the app: fetch every weigh-in again on the next sync (e.g. after an old scale history reached Garmin).
    refetch_weigh_ins: bool = False
    activities_synced: int = 0
    synced_activity_ids: list[int] = field(default_factory=list)

    @classmethod
    def from_dict(cls, data: dict) -> SyncState:
        known = {name for name in cls.__dataclass_fields__}
        return cls(**{key: value for key, value in data.items() if key in known})

    def remember(self, activity_id: int) -> None:
        if activity_id in self.synced_activity_ids:
            return
        self.synced_activity_ids.append(activity_id)
        del self.synced_activity_ids[:-MAX_REMEMBERED_ACTIVITY_IDS]
        self.activities_synced += 1


class Storage:
    def __init__(self, data_dir: Path) -> None:
        self.data_dir = data_dir

    def read_secret(self) -> str | None:
        try:
            secret = (self.data_dir / SECRET_FILE_NAME).read_text().strip()
        except OSError:
            return None
        return secret or None

    def user_dir(self, user: str) -> Path:
        """The data folder of an existing user. Rejects anything that is not a plain user name."""
        if not USER_NAME.match(user):
            raise UnknownUser(user)
        directory = self.data_dir / user
        if directory.is_symlink() or not directory.is_dir():
            raise UnknownUser(user)
        return directory

    def users_with_a_garmin_session(self) -> list[str]:
        users = []
        for entry in sorted(self.data_dir.iterdir()):
            if USER_NAME.match(entry.name) and (entry / "garmin" / "tokens").is_dir():
                users.append(entry.name)
        return users

    def tokens_dir(self, user: str, create: bool = False) -> Path:
        directory = self.user_dir(user) / "garmin" / "tokens"
        if create:
            self._make_private_dir(directory.parent)
            self._make_private_dir(directory)
        return directory

    def has_session(self, user: str) -> bool:
        directory = self.tokens_dir(user)
        return directory.is_dir() and any(directory.iterdir())

    def pending_tokens_dir(self, user: str) -> Path:
        """Where a login in progress keeps its tokens, until it succeeded."""
        directory = self.user_dir(user) / "garmin" / ".tokens-pending"
        shutil.rmtree(directory, ignore_errors=True)
        self._make_private_dir(directory.parent)
        self._make_private_dir(directory)
        return directory

    def commit_session(self, user: str) -> None:
        pending = self.user_dir(user) / "garmin" / ".tokens-pending"
        shutil.rmtree(self.tokens_dir(user), ignore_errors=True)
        os.replace(pending, self.tokens_dir(user))
        self.protect(user)

    def forget_session(self, user: str) -> None:
        shutil.rmtree(self.tokens_dir(user), ignore_errors=True)
        shutil.rmtree(self.user_dir(user) / "garmin" / ".tokens-pending", ignore_errors=True)

    def read_state(self, user: str) -> SyncState:
        try:
            return SyncState.from_dict(json.loads((self.user_dir(user) / "garmin" / "state.json").read_text()))
        except (OSError, ValueError):
            return SyncState()

    def write_state(self, user: str, state: SyncState) -> None:
        directory = self.user_dir(user) / "garmin"
        self._make_private_dir(directory)
        self._write_atomically(directory / "state.json", json.dumps(asdict(state), indent=2).encode())
        self.protect(user)

    def drop_activity(self, user: str, file_name: str, contents: bytes) -> None:
        """Hands an activity file to the app: staged first, so the importer never sees half a file."""
        watch = self.user_dir(user) / "watch"
        staging = watch / ".uploads"
        staging.mkdir(parents=True, exist_ok=True)
        staged = staging / file_name
        staged.write_bytes(contents)
        self._match_owner(staged, self.user_dir(user))
        os.replace(staged, watch / file_name)

    def drop_health(self, user: str, file_name: str, payload: dict) -> None:
        """Hands health data to the app, through the user's inbox. Written aside first, then moved in."""
        inbox = self.user_dir(user) / "inbox" / "health"
        inbox.mkdir(parents=True, exist_ok=True)
        self._match_owner(inbox.parent, self.user_dir(user))
        self._match_owner(inbox, self.user_dir(user))
        staged = inbox / f".{file_name}.part"
        staged.write_text(json.dumps(payload))
        self._match_owner(staged, self.user_dir(user))
        os.replace(staged, inbox / file_name)

    def protect(self, user: str) -> None:
        """Garmin tokens are as good as a password: owner-only, owned like the rest of the user's data."""
        user_dir = self.user_dir(user)
        garmin = user_dir / "garmin"
        for path in [garmin, *garmin.rglob("*")]:
            os.chmod(path, 0o700 if path.is_dir() else 0o600)
            self._match_owner(path, user_dir)

    def _make_private_dir(self, directory: Path) -> None:
        directory.mkdir(mode=0o700, exist_ok=True)
        os.chmod(directory, 0o700)

    def _write_atomically(self, path: Path, contents: bytes) -> None:
        handle, temporary = tempfile.mkstemp(dir=path.parent, prefix=".tmp-")
        with os.fdopen(handle, "wb") as file:
            file.write(contents)
        os.chmod(temporary, 0o600)
        os.replace(temporary, path)

    def _match_owner(self, path: Path, reference: Path) -> None:
        if os.geteuid() != 0:
            return
        owner = reference.stat()
        os.chown(path, owner.st_uid, owner.st_gid)
