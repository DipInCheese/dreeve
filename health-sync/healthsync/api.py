"""The internal HTTP API the app uses to connect a Garmin account and to show the sync status.

Only reachable inside the Docker network. Every request carries the shared secret the app
generated in the data folder. Passwords pass through once and are never stored.
"""

from __future__ import annotations

import hmac
import json
import logging
import re
import threading
import time
from collections.abc import Callable
from dataclasses import dataclass
from http import HTTPStatus
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

from .garmin import GarminConnect, GarminUnavailable, LoginFailed, PendingLogin
from .storage import Storage, UnknownUser
from .sync import SINCE_OPTIONS, Syncer, since_date

log = logging.getLogger("health-sync")

ROUTE = re.compile(r"^/v1/users/([^/]+)/(login|mfa|status|sync|disconnect)$")
MFA_TIMEOUT = 300
MAX_LOGIN_ATTEMPTS = 5
LOGIN_ATTEMPT_WINDOW = 900
MAX_BODY_SIZE = 4096


class ApiError(Exception):
    def __init__(self, status: HTTPStatus, error: str, message: str) -> None:
        super().__init__(message)
        self.status = status
        self.error = error
        self.message = message


@dataclass
class _Mfa:
    pending: PendingLogin
    since: str
    expires_at: float


class HealthSyncApi:
    def __init__(
        self,
        storage: Storage,
        garmin: GarminConnect,
        syncer: Syncer,
        run_in_background: Callable[[Callable[[], None]], None] | None = None,
        clock: Callable[[], float] = time.monotonic,
    ) -> None:
        self.storage = storage
        self.garmin = garmin
        self.syncer = syncer
        self.clock = clock
        self.run_in_background = run_in_background or (lambda job: threading.Thread(target=job, daemon=True).start())
        self._pending_mfa: dict[str, _Mfa] = {}
        self._attempts: dict[str, list[float]] = {}
        self._lock = threading.Lock()

    def handle(self, method: str, path: str, authorization: str | None, body: bytes) -> tuple[HTTPStatus, dict]:
        if path == "/healthz":
            return HTTPStatus.OK, {"status": "ok"}
        try:
            self._authorize(authorization)
            match = ROUTE.match(path)
            if not match:
                raise ApiError(HTTPStatus.NOT_FOUND, "not_found", "Unknown endpoint.")
            user, action = match.groups()
            try:
                self.storage.user_dir(user)
            except UnknownUser:
                raise ApiError(HTTPStatus.NOT_FOUND, "unknown_user", "Unknown user.") from None
            expected_method = "GET" if action == "status" else "POST"
            if method != expected_method:
                raise ApiError(HTTPStatus.METHOD_NOT_ALLOWED, "method_not_allowed", "Method not allowed.")
            payload = self._parse(body) if method == "POST" else {}
            handlers = {
                "login": self._login,
                "mfa": self._finish_mfa,
                "status": self._status,
                "sync": self._sync,
                "disconnect": self._disconnect,
            }
            return handlers[action](user, payload)
        except ApiError as error:
            return error.status, {"error": error.error, "message": error.message}

    def _authorize(self, authorization: str | None) -> None:
        secret = self.storage.read_secret()
        given = (authorization or "").removeprefix("Bearer ").strip()
        if not secret or not hmac.compare_digest(given.encode(), secret.encode()):
            raise ApiError(HTTPStatus.UNAUTHORIZED, "unauthorized", "Missing or wrong secret.")

    def _parse(self, body: bytes) -> dict:
        if len(body) > MAX_BODY_SIZE:
            raise ApiError(HTTPStatus.REQUEST_ENTITY_TOO_LARGE, "too_large", "Request too large.")
        if not body:
            return {}
        try:
            payload = json.loads(body)
        except ValueError:
            raise ApiError(HTTPStatus.BAD_REQUEST, "bad_request", "Invalid JSON.") from None
        if not isinstance(payload, dict):
            raise ApiError(HTTPStatus.BAD_REQUEST, "bad_request", "Expected a JSON object.")
        return payload

    def _login(self, user: str, payload: dict) -> tuple[HTTPStatus, dict]:
        email = str(payload.get("email") or "").strip()
        password = str(payload.get("password") or "")
        since = str(payload.get("since") or "90d")
        if not email or not password:
            raise ApiError(HTTPStatus.BAD_REQUEST, "bad_request", "Email and password are required.")
        if since not in SINCE_OPTIONS:
            raise ApiError(HTTPStatus.BAD_REQUEST, "bad_request", "Unknown history option.")
        self._count_attempt(user)

        try:
            pending = self.garmin.start_login(email, password, self.storage.pending_tokens_dir(user))
        except LoginFailed:
            raise ApiError(HTTPStatus.UNAUTHORIZED, "login_failed", "Garmin did not accept this email and password.") from None
        except GarminUnavailable as error:
            raise ApiError(HTTPStatus.SERVICE_UNAVAILABLE, "garmin_unavailable", str(error)) from None
        finally:
            del password

        if pending is not None:
            with self._lock:
                self._pending_mfa[user] = _Mfa(pending=pending, since=since, expires_at=self.clock() + MFA_TIMEOUT)
            return HTTPStatus.OK, {"status": "mfa_required"}

        self._connected(user, since)
        return HTTPStatus.OK, {"status": "connected"}

    def _finish_mfa(self, user: str, payload: dict) -> tuple[HTTPStatus, dict]:
        code = re.sub(r"\s+", "", str(payload.get("code") or ""))
        if not re.fullmatch(r"\d{4,8}", code):
            raise ApiError(HTTPStatus.BAD_REQUEST, "bad_request", "Enter the code Garmin sent you.")
        with self._lock:
            mfa = self._pending_mfa.get(user)
            if mfa is None or mfa.expires_at < self.clock():
                self._pending_mfa.pop(user, None)
                raise ApiError(HTTPStatus.GONE, "mfa_expired", "This login expired, start again.")
        self._count_attempt(user)

        try:
            self.garmin.finish_login(mfa.pending, code, self.storage.user_dir(user) / "garmin" / ".tokens-pending")
        except LoginFailed:
            raise ApiError(HTTPStatus.UNAUTHORIZED, "mfa_failed", "Garmin did not accept this code.") from None
        except GarminUnavailable as error:
            raise ApiError(HTTPStatus.SERVICE_UNAVAILABLE, "garmin_unavailable", str(error)) from None

        with self._lock:
            self._pending_mfa.pop(user, None)
        self._connected(user, mfa.since)
        return HTTPStatus.OK, {"status": "connected"}

    def _connected(self, user: str, since: str) -> None:
        self.storage.commit_session(user)
        state = self.storage.read_state(user)
        state.since = since_date(since, self.syncer.now().date()).isoformat()
        state.needs_login = False
        state.last_error = None
        state.consecutive_failures = 0
        state.last_attempt_at = None
        self.storage.write_state(user, state)
        log.info("[%s] Garmin connected", user)
        self.run_in_background(lambda: self.syncer.sync(user))

    def _status(self, user: str, payload: dict) -> tuple[HTTPStatus, dict]:
        state = self.storage.read_state(user)
        with self._lock:
            mfa = self._pending_mfa.get(user)
            mfa_pending = mfa is not None and mfa.expires_at >= self.clock()
        return HTTPStatus.OK, {
            "connected": self.storage.has_session(user) and not state.needs_login,
            "needsLogin": self.storage.has_session(user) and state.needs_login,
            "mfaPending": mfa_pending,
            "syncing": self.syncer.is_syncing(user),
            "displayName": state.display_name,
            "since": state.since,
            "lastSyncAt": state.last_sync_at,
            "lastError": state.last_error,
            "activitiesSynced": state.activities_synced,
            "backfilling": state.backfilling,
        }

    def _sync(self, user: str, payload: dict) -> tuple[HTTPStatus, dict]:
        if not self.storage.has_session(user):
            raise ApiError(HTTPStatus.CONFLICT, "not_connected", "Garmin is not connected.")
        self.run_in_background(lambda: self.syncer.sync(user))
        return HTTPStatus.ACCEPTED, {"status": "started"}

    def _disconnect(self, user: str, payload: dict) -> tuple[HTTPStatus, dict]:
        with self._lock:
            self._pending_mfa.pop(user, None)
        self.storage.forget_session(user)
        state = self.storage.read_state(user)
        state.needs_login = False
        state.last_error = None
        self.storage.write_state(user, state)
        log.info("[%s] Garmin disconnected", user)
        return HTTPStatus.OK, {"status": "disconnected"}

    def _count_attempt(self, user: str) -> None:
        """Protects the Garmin account: too many failed logins get it locked by Garmin."""
        now = self.clock()
        with self._lock:
            attempts = [moment for moment in self._attempts.get(user, []) if moment > now - LOGIN_ATTEMPT_WINDOW]
            if len(attempts) >= MAX_LOGIN_ATTEMPTS:
                raise ApiError(HTTPStatus.TOO_MANY_REQUESTS, "too_many_attempts", "Too many attempts, wait 15 minutes.")
            attempts.append(now)
            self._attempts[user] = attempts


def serve(api: HealthSyncApi, host: str, port: int) -> ThreadingHTTPServer:
    class Handler(BaseHTTPRequestHandler):
        server_version = "health-sync"
        sys_version = ""

        def _respond(self) -> None:
            length = int(self.headers.get("Content-Length") or 0)
            body = self.rfile.read(min(length, MAX_BODY_SIZE + 1)) if length else b""
            status, payload = api.handle(self.command, self.path, self.headers.get("Authorization"), body)
            encoded = json.dumps(payload).encode()
            self.send_response(status)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(encoded)))
            self.end_headers()
            self.wfile.write(encoded)

        do_GET = _respond
        do_POST = _respond

        def log_message(self, format: str, *args) -> None:  # noqa: A002 - signature of the base class
            # Paths only, never bodies: they contain passwords.
            log.debug("%s %s", self.command, self.path)

    server = ThreadingHTTPServer((host, port), Handler)
    return server
