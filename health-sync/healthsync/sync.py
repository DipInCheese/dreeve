"""Fetches new activities from Garmin Connect into each user's watch folder."""

from __future__ import annotations

import logging
import random
import threading
import time
from collections.abc import Callable
from datetime import date, datetime, timedelta, timezone

from .garmin import GarminConnect, GarminUnavailable, SessionExpired
from .storage import Storage, SyncState, UnknownUser

log = logging.getLogger("health-sync")

SYNC_INTERVAL = timedelta(hours=1)
# Downloading a long history at once is the surest way to get rate limited by Garmin, so a
# backfill goes in small batches, one every few minutes, until it caught up.
MAX_DOWNLOADS_PER_SYNC = 30
BACKFILL_INTERVAL = timedelta(minutes=10)
DOWNLOAD_PAUSE_IN_SECONDS = 2.0
MAX_BACKOFF = timedelta(hours=12)
# Garmin rewrites activities for a while after they are recorded (e.g. late uploads from the watch).
OVERLAP = timedelta(days=2)
EVERYTHING = date(2000, 1, 1)
SINCE_OPTIONS = {"90d": 90, "365d": 365, "all": None}


def since_date(option: str, today: date) -> date:
    days = SINCE_OPTIONS[option]
    return EVERYTHING if days is None else today - timedelta(days=days)


class Syncer:
    def __init__(
        self,
        storage: Storage,
        garmin: GarminConnect,
        now: Callable[[], datetime] = lambda: datetime.now(timezone.utc),
        pause: Callable[[float], None] = time.sleep,
    ) -> None:
        self.storage = storage
        self.garmin = garmin
        self.now = now
        self.pause = pause
        self._running: set[str] = set()
        self._lock = threading.Lock()

    def is_syncing(self, user: str) -> bool:
        with self._lock:
            return user in self._running

    def sync(self, user: str) -> None:
        """Syncs one user. Never raises: failures end up in the user's state, for the app to show."""
        with self._lock:
            if user in self._running:
                return
            self._running.add(user)
        try:
            self._sync(user)
        except UnknownUser:
            log.warning("[%s] user no longer exists, skipped", user)
        finally:
            with self._lock:
                self._running.discard(user)

    def _sync(self, user: str) -> None:
        state = self.storage.read_state(user)
        if not self.storage.has_session(user) or state.needs_login:
            return

        started = self.now()
        state.last_attempt_at = started.isoformat(timespec="seconds")
        try:
            account = self.garmin.resume(self.storage.tokens_dir(user))
            state.display_name = account.display_name() or state.display_name
            today = started.date()
            start = self._start_date(state, today)
            new = 0
            downloads = 0
            state.backfilling = False
            for activity in account.activities_between(start, today):
                if activity.id in state.synced_activity_ids:
                    continue
                if downloads >= MAX_DOWNLOADS_PER_SYNC:
                    state.backfilling = True
                    break
                downloads += 1
                contents = account.download_fit(activity.id)
                if contents is not None:
                    self.storage.drop_activity(user, f"garmin-{activity.id}.fit", contents)
                    new += 1
                state.remember(activity.id)
                if activity.start:
                    state.last_activity_date = max(state.last_activity_date or "", activity.start[:10])
                # Saved after every activity: an interrupted first sync continues where it stopped.
                self.storage.write_state(user, state)
                self.pause(DOWNLOAD_PAUSE_IN_SECONDS)
            state.last_sync_at = started.isoformat(timespec="seconds")
            state.last_error = None
            state.consecutive_failures = 0
            log.info("[%s] synced, %d new activities", user, new)
        except SessionExpired as error:
            state.needs_login = True
            state.last_error = str(error)
            log.warning("[%s] %s", user, error)
        except GarminUnavailable as error:
            state.consecutive_failures += 1
            state.last_error = str(error)
            log.warning("[%s] sync failed, will retry: %s", user, error)
        except Exception as error:  # noqa: BLE001 - a bug for one user must not stop the others
            state.consecutive_failures += 1
            state.last_error = "Unexpected error while syncing."
            log.exception("[%s] unexpected error: %s", user, error)
        self.storage.write_state(user, state)

    def _start_date(self, state: SyncState, today: date) -> date:
        since = date.fromisoformat(state.since) if state.since else today - timedelta(days=90)
        if not state.last_activity_date:
            return since
        return max(since, date.fromisoformat(state.last_activity_date) - OVERLAP)

    def is_due(self, user: str) -> bool:
        state = self.storage.read_state(user)
        if state.needs_login:
            return False
        if not state.last_attempt_at:
            return True
        last = datetime.fromisoformat(state.last_attempt_at)
        wait = BACKFILL_INTERVAL if state.backfilling else SYNC_INTERVAL
        if state.consecutive_failures:
            wait = min(SYNC_INTERVAL * 2 ** (state.consecutive_failures - 1), MAX_BACKOFF)
        return self.now() >= last + wait

    def run_forever(self, stop: threading.Event) -> None:
        """Checks every minute who is due. Users are synced one after the other, to stay gentle on Garmin."""
        while not stop.is_set():
            for user in self.storage.users_with_a_garmin_session():
                if stop.is_set():
                    break
                if self.is_due(user):
                    self.sync(user)
                    # Spreads requests out, so many users never hit Garmin at the same moment.
                    stop.wait(random.uniform(5, 30))
            stop.wait(60)
