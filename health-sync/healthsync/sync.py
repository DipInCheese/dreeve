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
# Health data: the last days on every sync, the history in batches (four requests per day).
RECENT_HEALTH_DAYS = 2
HEALTH_DAYS_PER_SYNC = 14
HEALTH_PAUSE_IN_SECONDS = 1.0
# Older health data is rarely useful and costs four requests a day to fetch.
MAX_HEALTH_HISTORY = timedelta(days=730)
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
            # The user's own day, not the UTC one: in Europe the new day would otherwise only start at 01:00 or 02:00.
            today = started.astimezone().date()
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
            if self._sync_health(user, account, state, today):
                state.backfilling = True
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

    def _sync_health(self, user: str, account, state: SyncState, today: date) -> bool:
        """Fetches the last two days on every sync (today keeps changing, last night's sleep lands on it),
        then one batch of history, newest first. Returns whether history is still left to fetch."""
        since = max(self._since(state, today), today - MAX_HEALTH_HISTORY)
        recent_start = max(since, today - timedelta(days=RECENT_HEALTH_DAYS - 1))
        self._fetch_health(user, account, recent_start, today)
        if state.health_oldest_date is None or date.fromisoformat(state.health_oldest_date) > recent_start:
            state.health_oldest_date = recent_start.isoformat()

        oldest = date.fromisoformat(state.health_oldest_date)
        if oldest > since:
            batch_end = oldest - timedelta(days=1)
            batch_start = max(since, batch_end - timedelta(days=HEALTH_DAYS_PER_SYNC - 1))
            had_data = self._fetch_health(user, account, batch_start, batch_end)
            # A batch without any data reaches back before the first watch: nothing older to fetch.
            state.health_oldest_date = (batch_start if had_data else since).isoformat()
            self.storage.write_state(user, state)

        return date.fromisoformat(state.health_oldest_date) > since

    def _fetch_health(self, user: str, account, start: date, end: date) -> bool:
        fetched_at = self.now().isoformat(timespec="seconds")
        had_data = False
        day = start
        while day <= end:
            health = account.day_health(day)
            had_data = had_data or _has_data(health)
            self.storage.drop_health(user, f"garmin-day-{day.isoformat()}.json", {
                "source": "garmin",
                "kind": "day",
                "date": day.isoformat(),
                "fetchedAt": fetched_at,
                **health,
            })
            self.pause(HEALTH_PAUSE_IN_SECONDS)
            day += timedelta(days=1)
        self.storage.drop_health(user, f"garmin-range-{start.isoformat()}-{end.isoformat()}.json", {
            "source": "garmin",
            "kind": "range",
            "start": start.isoformat(),
            "end": end.isoformat(),
            "fetchedAt": fetched_at,
            **account.range_health(start, end),
        })
        return had_data

    def _since(self, state: SyncState, today: date) -> date:
        return date.fromisoformat(state.since) if state.since else today - timedelta(days=90)

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
            try:
                self._sync_who_is_due(stop)
            except Exception as error:  # noqa: BLE001 - the loop must survive anything, or syncing stops for good
                log.exception("sync loop error, trying again in a minute: %s", error)
            stop.wait(60)

    def _sync_who_is_due(self, stop: threading.Event) -> None:
        for user in self.storage.users_with_a_garmin_session():
            if stop.is_set():
                return
            try:
                due = self.is_due(user)
            except Exception as error:  # noqa: BLE001 - e.g. a damaged state file: skip this user, keep the others going
                log.exception("[%s] could not check when to sync: %s", user, error)
                continue
            if due:
                self.sync(user)
                # Spreads requests out, so many users never hit Garmin at the same moment.
                stop.wait(random.uniform(5, 30))


def _has_data(health: dict) -> bool:
    summary = health.get("summary") or {}
    sleep = (health.get("sleep") or {}).get("dailySleepDTO") or {}
    return bool(summary.get("totalSteps") or summary.get("restingHeartRate") or sleep.get("sleepTimeSeconds"))
