import io
import json
import tempfile
import unittest
import zipfile
from datetime import date, datetime, timedelta, timezone
from http import HTTPStatus
from pathlib import Path

from healthsync.api import HealthSyncApi
from healthsync.garmin import Activity, GarminUnavailable, LoginFailed, PendingLogin, SessionExpired, extract_fit
from healthsync.storage import Storage
from healthsync.sync import Syncer

SECRET = "s3cret"
NOW = datetime(2026, 10, 16, 12, 0, tzinfo=timezone.utc)
FIT = b"\x0e\x10\x00\x00\x00\x00\x00\x00.FITdata"


class FakeAccount:
    def __init__(self, garmin):
        self.garmin = garmin

    def activities_between(self, start, end):
        self.garmin.requested.append((start, end))
        if self.garmin.fail_with:
            raise self.garmin.fail_with
        return [activity for activity in self.garmin.activities if start.isoformat() <= activity.start[:10] <= end.isoformat()]

    def download_fit(self, activity_id):
        return None if activity_id in self.garmin.without_fit else FIT + str(activity_id).encode()

    def display_name(self):
        return "Test Runner"


class FakeGarmin:
    def __init__(self):
        self.mfa = False
        self.reject_password = False
        self.reject_code = False
        self.expired = False
        self.fail_with = None
        self.activities = []
        self.without_fit = set()
        self.requested = []

    def start_login(self, email, password, tokens_dir):
        if self.reject_password:
            raise LoginFailed("nope")
        if self.mfa:
            return PendingLogin(client=None, client_state={"email": email})
        (tokens_dir / "garmin_tokens.json").write_text("{}")
        return None

    def finish_login(self, pending, code, tokens_dir):
        if self.reject_code:
            raise LoginFailed("wrong code")
        (tokens_dir / "garmin_tokens.json").write_text("{}")

    def resume(self, tokens_dir):
        if self.expired:
            raise SessionExpired("expired")
        return FakeAccount(self)


class HealthSyncTestCase(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.data = Path(self.directory.name)
        (self.data / ".health-sync-secret").write_text(SECRET + "\n")
        (self.data / "alice" / "watch").mkdir(parents=True)
        self.storage = Storage(self.data)
        self.garmin = FakeGarmin()
        self.now = NOW
        self.syncer = Syncer(self.storage, self.garmin, now=lambda: self.now, pause=lambda seconds: None)
        self.background = []
        self.clock = 1000.0
        self.api = HealthSyncApi(self.storage, self.garmin, self.syncer, run_in_background=self.background.append, clock=lambda: self.clock)

    def tearDown(self):
        self.directory.cleanup()

    def call(self, method, path, payload=None, secret=SECRET):
        body = json.dumps(payload).encode() if payload is not None else b""
        return self.api.handle(method, path, f"Bearer {secret}" if secret else None, body)

    def connect(self, since="90d"):
        status, body = self.call("POST", "/v1/users/alice/login", {"email": "a@example.com", "password": "pw", "since": since})
        self.assertEqual((HTTPStatus.OK, {"status": "connected"}), (status, body))
        for job in self.background:
            job()
        self.background.clear()

    def watched_files(self):
        return sorted(path.name for path in (self.data / "alice" / "watch").iterdir() if path.is_file())


class ApiTest(HealthSyncTestCase):
    def test_it_requires_the_secret(self):
        self.assertEqual(HTTPStatus.UNAUTHORIZED, self.call("GET", "/v1/users/alice/status", secret=None)[0])
        self.assertEqual(HTTPStatus.UNAUTHORIZED, self.call("GET", "/v1/users/alice/status", secret="wrong")[0])

    def test_it_refuses_everything_without_a_secret_file(self):
        (self.data / ".health-sync-secret").unlink()
        self.assertEqual(HTTPStatus.UNAUTHORIZED, self.call("GET", "/v1/users/alice/status", secret="")[0])

    def test_it_only_knows_existing_users(self):
        for user in ["bob", "..", "Alice", "alice%2F..", ".health-sync-secret"]:
            self.assertEqual(HTTPStatus.NOT_FOUND, self.call("GET", f"/v1/users/{user}/status")[0], user)

    def test_health_needs_no_secret(self):
        self.assertEqual((HTTPStatus.OK, {"status": "ok"}), self.api.handle("GET", "/healthz", None, b""))

    def test_connecting_without_mfa(self):
        self.assertFalse(self.call("GET", "/v1/users/alice/status")[1]["connected"])

        self.connect()

        status = self.call("GET", "/v1/users/alice/status")[1]
        self.assertTrue(status["connected"])
        self.assertEqual("Test Runner", status["displayName"])
        self.assertEqual("2026-07-18", status["since"])
        self.assertEqual(0o700, (self.data / "alice" / "garmin" / "tokens").stat().st_mode & 0o777)

    def test_connecting_with_mfa(self):
        self.garmin.mfa = True
        login = self.call("POST", "/v1/users/alice/login", {"email": "a@example.com", "password": "pw", "since": "all"})
        self.assertEqual((HTTPStatus.OK, {"status": "mfa_required"}), login)
        self.assertFalse(self.call("GET", "/v1/users/alice/status")[1]["connected"])
        self.assertTrue(self.call("GET", "/v1/users/alice/status")[1]["mfaPending"])

        self.assertEqual((HTTPStatus.OK, {"status": "connected"}), self.call("POST", "/v1/users/alice/mfa", {"code": "123 456"}))
        self.assertTrue(self.call("GET", "/v1/users/alice/status")[1]["connected"])
        self.assertEqual("2000-01-01", self.call("GET", "/v1/users/alice/status")[1]["since"])

    def test_a_wrong_password_connects_nothing(self):
        self.garmin.reject_password = True
        status, body = self.call("POST", "/v1/users/alice/login", {"email": "a@example.com", "password": "pw"})
        self.assertEqual(HTTPStatus.UNAUTHORIZED, status)
        self.assertEqual("login_failed", body["error"])
        self.assertFalse(self.call("GET", "/v1/users/alice/status")[1]["connected"])

    def test_a_wrong_code_keeps_the_login_waiting(self):
        self.garmin.mfa = True
        self.garmin.reject_code = True
        self.call("POST", "/v1/users/alice/login", {"email": "a@example.com", "password": "pw"})
        self.assertEqual("mfa_failed", self.call("POST", "/v1/users/alice/mfa", {"code": "111111"})[1]["error"])
        self.assertFalse(self.call("GET", "/v1/users/alice/status")[1]["connected"])
        self.assertTrue(self.call("GET", "/v1/users/alice/status")[1]["mfaPending"])

    def test_a_waiting_login_expires(self):
        self.garmin.mfa = True
        self.call("POST", "/v1/users/alice/login", {"email": "a@example.com", "password": "pw"})
        self.clock += 301
        self.assertEqual(HTTPStatus.GONE, self.call("POST", "/v1/users/alice/mfa", {"code": "123456"})[0])

    def test_it_limits_login_attempts(self):
        self.garmin.reject_password = True
        for _ in range(5):
            self.call("POST", "/v1/users/alice/login", {"email": "a@example.com", "password": "pw"})
        self.assertEqual(HTTPStatus.TOO_MANY_REQUESTS, self.call("POST", "/v1/users/alice/login", {"email": "a@example.com", "password": "pw"})[0])
        self.clock += 901
        self.assertEqual(HTTPStatus.UNAUTHORIZED, self.call("POST", "/v1/users/alice/login", {"email": "a@example.com", "password": "pw"})[0])

    def test_it_validates_input(self):
        self.assertEqual(HTTPStatus.BAD_REQUEST, self.call("POST", "/v1/users/alice/login", {"email": "a@example.com"})[0])
        self.assertEqual(HTTPStatus.BAD_REQUEST, self.call("POST", "/v1/users/alice/login", {"email": "a", "password": "b", "since": "1d"})[0])
        self.assertEqual(HTTPStatus.BAD_REQUEST, self.call("POST", "/v1/users/alice/mfa", {"code": "abc"})[0])
        self.assertEqual(HTTPStatus.METHOD_NOT_ALLOWED, self.call("GET", "/v1/users/alice/login")[0])
        self.assertEqual(HTTPStatus.CONFLICT, self.call("POST", "/v1/users/alice/sync")[0])

    def test_disconnecting(self):
        self.connect()
        self.assertEqual(HTTPStatus.OK, self.call("POST", "/v1/users/alice/disconnect")[0])
        self.assertFalse(self.call("GET", "/v1/users/alice/status")[1]["connected"])
        self.assertFalse((self.data / "alice" / "garmin" / "tokens").exists())


class SyncTest(HealthSyncTestCase):
    def test_it_drops_new_activities_in_the_watch_folder(self):
        self.garmin.activities = [
            Activity(id=1, start="2026-01-01 08:00:00", name="Too old"),
            Activity(id=2, start="2026-10-01 08:00:00", name="Run"),
            Activity(id=3, start="2026-10-15 18:00:00", name="Ride"),
        ]
        self.connect()

        self.assertEqual(["garmin-2.fit", "garmin-3.fit"], self.watched_files())
        self.assertEqual(FIT + b"2", (self.data / "alice" / "watch" / "garmin-2.fit").read_bytes())
        self.assertEqual(2, self.call("GET", "/v1/users/alice/status")[1]["activitiesSynced"])

    def test_it_never_downloads_an_activity_twice(self):
        self.garmin.activities = [Activity(id=2, start="2026-10-14 08:00:00", name="Run")]
        self.connect()
        (self.data / "alice" / "watch" / "garmin-2.fit").unlink()  # imported by the app

        self.now += timedelta(hours=2)
        self.syncer.sync("alice")

        self.assertEqual([], self.watched_files())
        # Only the last few days are asked for again.
        self.assertEqual(date(2026, 10, 12), self.garmin.requested[-1][0])

    def test_activities_without_a_fit_file_are_skipped(self):
        self.garmin.activities = [Activity(id=4, start="2026-10-14 08:00:00", name="Manual")]
        self.garmin.without_fit = {4}
        self.connect()
        self.assertEqual([], self.watched_files())

    def test_an_expired_session_asks_for_a_new_login(self):
        self.connect()
        self.garmin.expired = True
        self.now += timedelta(hours=2)
        self.syncer.sync("alice")

        status = self.call("GET", "/v1/users/alice/status")[1]
        self.assertFalse(status["connected"])
        self.assertTrue(status["needsLogin"])
        self.assertFalse(self.syncer.is_due("alice"))

        self.garmin.expired = False
        self.connect()
        self.assertTrue(self.call("GET", "/v1/users/alice/status")[1]["connected"])

    def test_it_backs_off_while_garmin_is_unavailable(self):
        self.connect()
        self.garmin.fail_with = GarminUnavailable("down")
        for hours in [1, 2, 4]:
            self.now += timedelta(hours=hours)
            self.assertTrue(self.syncer.is_due("alice"))
            self.syncer.sync("alice")
            self.now += timedelta(minutes=50)
            self.assertFalse(self.syncer.is_due("alice"))
            self.now -= timedelta(minutes=50)
        self.assertEqual("down", self.call("GET", "/v1/users/alice/status")[1]["lastError"])

    def test_a_long_history_comes_in_batches(self):
        self.garmin.activities = [
            Activity(id=100 + day, start=(date(2025, 10, 20) + timedelta(days=day)).isoformat() + " 08:00:00", name="Run")
            for day in range(70)
        ]
        self.connect(since="all")

        self.assertEqual(30, len(self.watched_files()))
        self.assertTrue(self.call("GET", "/v1/users/alice/status")[1]["backfilling"])
        self.now += timedelta(minutes=11)
        self.assertTrue(self.syncer.is_due("alice"))

        for _ in range(3):
            self.now += timedelta(minutes=11)
            self.syncer.sync("alice")

        self.assertEqual(70, len(self.watched_files()))
        self.assertFalse(self.call("GET", "/v1/users/alice/status")[1]["backfilling"])
        self.now += timedelta(minutes=11)
        self.assertFalse(self.syncer.is_due("alice"))

    def test_it_is_due_hourly(self):
        self.connect()
        self.assertFalse(self.syncer.is_due("alice"))
        self.now += timedelta(minutes=61)
        self.assertTrue(self.syncer.is_due("alice"))


class ExtractFitTest(unittest.TestCase):
    def test_it_unzips_the_original(self):
        buffer = io.BytesIO()
        with zipfile.ZipFile(buffer, "w") as archive:
            archive.writestr("123_ACTIVITY.fit", FIT)
        self.assertEqual(FIT, extract_fit(buffer.getvalue()))

    def test_it_accepts_a_plain_fit_file(self):
        self.assertEqual(FIT, extract_fit(FIT))

    def test_it_skips_archives_without_a_fit_file(self):
        buffer = io.BytesIO()
        with zipfile.ZipFile(buffer, "w") as archive:
            archive.writestr("activity.gpx", b"<gpx/>")
        self.assertIsNone(extract_fit(buffer.getvalue()))
        self.assertIsNone(extract_fit(b"<gpx/>"))


if __name__ == "__main__":
    unittest.main()
