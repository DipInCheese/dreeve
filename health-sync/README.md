# health-sync

Fetches Garmin Connect activities and health data into each user's data folder, for Dreeve to import.
It ships inside the Dreeve image and runs as its own container (command `health-sync`,
see `docker/multi-user/docker-compose.yml`).

- One process for all users, built on [python-garminconnect](https://github.com/cyberjunky/python-garminconnect).
- Writes `.fit` files into `<data>/<user>/watch/`; the app's file import takes it from there.
- Writes Garmin's health answers, untouched, as JSON into `<data>/<user>/inbox/health/`: one file per day
  (`garmin-day-<date>.json`: daily summary, sleep, HRV, training readiness) and one per batch
  (`garmin-range-<start>-<end>.json`: VO2max, weigh-ins). Any other source (a smart scale, say) can deliver
  through the same inbox.
- Keeps each user's Garmin session in `<data>/<user>/garmin/` (owner-only). Passwords are never stored.
- Internal HTTP API for the app (login, MFA code, status, sync now, disconnect), protected by the
  secret in `<data>/.health-sync-secret`. Not reachable from outside the Docker network.

Run the tests:

```bash
docker run --rm -v "$PWD:/app" -w /app python:3.13-slim python -m unittest discover -s tests
```
