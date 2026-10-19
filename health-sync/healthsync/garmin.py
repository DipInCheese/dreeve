"""The only module that talks to Garmin Connect, through python-garminconnect.

Everything else depends on the small GarminAccount interface, so the sync logic can be tested
without a network connection.
"""

from __future__ import annotations

import io
import zipfile
from dataclasses import dataclass
from datetime import date
from pathlib import Path
from typing import Any, Protocol


class LoginFailed(Exception):
    """Wrong credentials, a wrong MFA code, or Garmin refusing the login."""


class SessionExpired(Exception):
    """The stored session no longer works: the user has to log in again."""


class GarminUnavailable(Exception):
    """Garmin is down, rate limiting or unreachable. Worth retrying later."""


@dataclass(frozen=True)
class Activity:
    id: int
    start: str  # local start time, "YYYY-MM-DD HH:MM:SS"
    name: str


@dataclass
class PendingLogin:
    """A login waiting for its MFA code. Lives in memory only."""

    client: Any
    client_state: Any


class GarminAccount(Protocol):
    def activities_between(self, start: date, end: date) -> list[Activity]: ...

    def download_fit(self, activity_id: int) -> bytes | None: ...

    def display_name(self) -> str | None: ...


class GarminConnect:
    """Creates sessions. Garmin's own client is imported lazily so tests never need it."""

    def start_login(self, email: str, password: str, tokens_dir: Path) -> PendingLogin | None:
        """Logs in. Returns a PendingLogin when Garmin asks for an MFA code, None when logged in."""
        from garminconnect import Garmin

        client = Garmin(email=email, password=password, return_on_mfa=True)
        with _translated_errors(login=True):
            status, client_state = client.login()
            if status == "needs_mfa":
                return PendingLogin(client=client, client_state=client_state)
            client.client.dump(str(tokens_dir))
        return None

    def finish_login(self, pending: PendingLogin, code: str, tokens_dir: Path) -> None:
        with _translated_errors(login=True):
            pending.client.resume_login(pending.client_state, code)
            pending.client.client.dump(str(tokens_dir))

    def resume(self, tokens_dir: Path) -> GarminAccount:
        """Opens the stored session. Raises SessionExpired when it no longer works."""
        from garminconnect import Garmin

        if not (tokens_dir / "garmin_tokens.json").is_file():
            raise SessionExpired("No stored Garmin session.")
        client = Garmin()
        with _translated_errors(login=False):
            client.login(str(tokens_dir))
        return _Account(client)


class _Account:
    def __init__(self, client: Any) -> None:
        self.client = client

    def activities_between(self, start: date, end: date) -> list[Activity]:
        with _translated_errors(login=False):
            raw = self.client.get_activities_by_date(start.isoformat(), end.isoformat(), sortorder="asc")
        return [
            Activity(
                id=int(item["activityId"]),
                start=str(item.get("startTimeLocal") or ""),
                name=str(item.get("activityName") or ""),
            )
            for item in raw or []
            if item.get("activityId")
        ]

    def download_fit(self, activity_id: int) -> bytes | None:
        from garminconnect import Garmin

        with _translated_errors(login=False):
            original = self.client.download_activity(str(activity_id), dl_fmt=Garmin.ActivityDownloadFormat.ORIGINAL)
        return extract_fit(original)

    def display_name(self) -> str | None:
        with _translated_errors(login=False):
            return self.client.get_full_name()

    def day_health(self, day: date) -> dict[str, Any]:
        """Garmin's own answers for one day, untouched: the app parses and archives them."""
        cdate = day.isoformat()
        return {
            "summary": self._optional(lambda: self.client.get_user_summary(cdate)),
            "sleep": self._optional(lambda: self.client.get_sleep_data(cdate)),
            "hrv": self._optional(lambda: self.client.get_hrv_data(cdate)),
            "readiness": self._optional(lambda: self.client.get_training_readiness(cdate)),
        }

    def range_health(self, start: date, end: date) -> dict[str, Any]:
        """What Garmin serves per date range in one request: VO2max and weigh-ins."""
        return {
            "maxMetrics": self._optional(lambda: self.client.get_max_metrics_range(start.isoformat(), end.isoformat())),
            "bodyComposition": self._optional(lambda: self.client.get_body_composition(start.isoformat(), end.isoformat())),
        }

    def weigh_ins(self, start: date, end: date) -> dict[str, Any]:
        """Only the weigh-ins of a date range, in one request."""
        return {
            "bodyComposition": self._optional(lambda: self.client.get_body_composition(start.isoformat(), end.isoformat())),
        }

    def _optional(self, request: Any) -> Any:
        """Not every watch records everything: data Garmin does not have for a day is simply absent."""
        from garminconnect import GarminConnectNotFoundError

        try:
            with _translated_errors(login=False):
                return request()
        except GarminConnectNotFoundError:
            return None


def extract_fit(original: bytes) -> bytes | None:
    """Garmin serves the original upload zipped. Activities recorded on a watch contain one .fit file;
    those uploaded as .gpx or .tcx have no .fit file and are skipped."""
    if original[:4] != b"PK\x03\x04":
        return original if original[8:12] == b".FIT" else None
    with zipfile.ZipFile(io.BytesIO(original)) as archive:
        for entry in archive.infolist():
            if entry.filename.lower().endswith(".fit") and entry.file_size < 64 * 1024 * 1024:
                return archive.read(entry)
    return None


class _translated_errors:
    """Maps the library's exceptions onto the three kinds of failure the sync cares about."""

    def __init__(self, login: bool) -> None:
        self.login = login

    def __enter__(self) -> None:
        return None

    def __exit__(self, exc_type, exc, traceback) -> bool:
        if exc is None:
            return False
        from garminconnect import (
            GarminConnectAuthenticationError,
            GarminConnectConnectionError,
            GarminConnectNotFoundError,
            GarminConnectTooManyRequestsError,
        )

        if isinstance(exc, GarminConnectNotFoundError):
            return False
        if isinstance(exc, GarminConnectTooManyRequestsError):
            raise GarminUnavailable("Garmin is rate limiting, it will be retried later.") from exc
        if isinstance(exc, GarminConnectAuthenticationError):
            if self.login:
                raise LoginFailed(_first_line(exc)) from exc
            raise SessionExpired("Garmin no longer accepts the stored session.") from exc
        if isinstance(exc, GarminConnectConnectionError | OSError):
            raise GarminUnavailable(_first_line(exc)) from exc
        return False


def _first_line(exc: BaseException) -> str:
    return (str(exc).strip().splitlines() or ["Unknown error"])[0][:300]
