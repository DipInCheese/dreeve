<p align="center">
  <img src="public/assets/images/logo.svg" width="250" alt="Logo" >
</p>

<h1 align="center">Dreeve, health edition</h1>

<h4 align="center">A self-hosted health and training app around your Garmin data.</h4>

<p align="center">
A fork of <a href="https://github.com/dreeveapp/dreeve">Dreeve</a> (formerly "Statistics for Strava"), reworked around health.
It is not maintained by the Dreeve maintainers: report problems with this fork here, not to the Dreeve project.
</p>

---

## What it does

Connect your Garmin account in the browser and Dreeve fills itself: every hour it fetches your activities, sleep,
HRV, resting heart rate, stress, Body Battery, steps and weigh-ins. Every number is shown next to **your own
normal**, because a resting heart rate of 52 only means something compared with yourself.

* **Today** - last night and today at a glance: sleep, Body Battery, HRV, resting heart rate, stress, steps, training readiness
* **Health** - charts per topic (sleep, heart, stress & Body Battery, body, fitness) over a week, a month, three months or a year
* **Insights** - your week summed up: every average against your normal and the week before, your sleep rhythm, your training
* **Training** - activities, calendar, training load, best efforts, heatmap, gear and maintenance, segments, Eddington, milestones, rewind
* **Private** - every page needs a sign-in; your data stays on your own server
* **Multi-user** - one installation for the whole household, each person with their own data, sign-in and Garmin account
* **Phone-friendly** - a bottom tab bar and thumb-sized controls; add it to your home screen like an app
* **Metric** - kilometres, kilograms and km/h everywhere
* **AI coach (optional)** - chat about your sleep, recovery and training; off by default, works with a local model
* **File import** - FIT, TCX and GPX files from any watch or app
* **Other sources** - weigh-ins and body composition from any scale (for example Eufy) in a simple JSON format

## How it runs

Three Docker containers share one data folder:

| Container | Job |
|---|---|
| `app` | The web app, and the sign-in page on the bare address. |
| `daemon` | Imports new files within seconds and runs the scheduled reminders. |
| `health-sync` | Talks to Garmin Connect (unofficial API) and drops activities and health data into each user's folder. Only reachable inside Docker. |

Every user has their own folder with their own SQLite database: `data/<user>/`.

## Getting started

See [Multiple users](docs/src/content/docs/getting-started/multi-user.mdx) for the setup, and
[Garmin Connect](docs/src/content/docs/integrations/garmin-connect.mdx) for connecting your account.
The rest of the documentation is in [docs/src/content/docs](docs/src/content/docs), for example
[your health data](docs/src/content/docs/health/overview.mdx) and the [AI coach](docs/src/content/docs/integrations/ai.mdx).

The design behind this fork, and what is planned next, is in [docs/design/health-redesign.md](docs/design/health-redesign.md).

## 🌳 What's in a name?

**Dreeve** • */driːv/* • *noun*

A `dreeve` is the West-Flemish word for a country road lined with trees on both sides.
The kind of road every cyclist and runner loves.

## License

[GNU Affero General Public License v3](LICENSE), like Dreeve itself.
