"""Starts the internal API and the hourly sync loop."""

from __future__ import annotations

import logging
import os
import signal
import threading
from pathlib import Path

from .api import HealthSyncApi, serve
from .garmin import GarminConnect
from .storage import Storage
from .sync import Syncer


def main() -> None:
    logging.basicConfig(
        level=os.environ.get("LOG_LEVEL", "INFO").upper(),
        format="%(asctime)s %(levelname)s %(message)s",
    )
    # The garminconnect library logs request details at debug level; keep it quiet.
    logging.getLogger("garminconnect").setLevel(logging.WARNING)

    storage = Storage(Path(os.environ.get("DATA_DIR", "/data")))
    garmin = GarminConnect()
    syncer = Syncer(storage, garmin)
    api = HealthSyncApi(storage, garmin, syncer)

    stop = threading.Event()
    worker = threading.Thread(target=syncer.run_forever, args=(stop,), daemon=True)
    worker.start()

    server = serve(api, "0.0.0.0", int(os.environ.get("PORT", "8000")))

    def shutdown(*_: object) -> None:
        stop.set()
        threading.Thread(target=server.shutdown, daemon=True).start()

    signal.signal(signal.SIGTERM, shutdown)
    signal.signal(signal.SIGINT, shutdown)
    logging.getLogger("health-sync").info("health-sync listening on port %s", server.server_port)
    server.serve_forever()


if __name__ == "__main__":
    main()
