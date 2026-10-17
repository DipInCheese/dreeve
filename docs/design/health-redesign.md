# Design: from sports dashboard to personal health overview

Status: **draft for review**. Nothing in here is built yet.

## 1. Goal

Every person gets one private place that answers *"How am I doing?"*, built from their Garmin data:

- **Today**: how did I sleep, how recovered am I, how stressed, how active.
- **Trends**: how are sleep, resting heart rate, HRV, weight and fitness evolving over weeks and months.
- **Training**: what did I do, how hard, how am I progressing (Dreeve's existing strengths).
- **Later**: body composition from a Eufy scale, and an AI analysis with recommendations.

## 2. Decisions

These were proposed as recommendations and are assumed until changed:

| Topic | Decision |
|---|---|
| Starting point | Evolve this fork of Dreeve. Keep its activity analytics, rebuild the app around health. Upstream Dreeve updates can no longer be merged automatically. |
| Main source | Garmin Connect, for activities **and** daily health data. |
| Garmin connection | Each person connects their own Garmin account from their settings page, including the MFA code. Only session tokens are stored, never the password. |
| Other sources | Strava is removed. Uploading activity files stays, as a fallback and for old history. |
| Privacy | Everything is private to its owner. No public dashboards. |
| Eufy scale, AI analysis | Prepared in the architecture, not built now. |

Garmin and Eufy only offer business APIs, so both integrations use **unofficial** APIs, the same way the
Garmin Connect app and the Home Assistant integration do. They can break when Garmin or Eufy change something, and
their terms of service do not cover them. This is stated plainly in the app and the docs.

## 3. What is wrong with the current experience

Found while setting up the multi-user version and reviewing the code:

1. **Health data would be public.** Every non-admin page, including the AI chat, can be viewed without logging in
   unless a security setting is switched on.
2. **Two worlds.** An "admin panel" with its own navigation and a separate "app", with "Admin panel" and "Return to
   app" links between them. Regular things (uploading, connecting Strava, profile, settings) live in the admin world.
3. **Walls instead of guidance.** A new user is forced through the athlete settings form, then lands on a
   "Finish setup" page, before seeing anything. After logging in, people land on the upload page.
4. **Jargon.** "Daemon", "cron expressions", "import mode", "watch folder", "build", "Strava import" in settings.
5. **Manual inputs the data already knows.** Weight history, resting and maximum heart rate formulas, heart rate
   zones and FTP are typed in by hand, while Garmin measures most of them.
6. **Strava everywhere.** Segments, challenges, Strava links, an AI assistant called "Mark" that asks for Strava
   activities.
7. **Noise.** Donation, Discord, GitHub and WindAhead links in the top bar of a personal health app.
8. **No sense of freshness.** Nothing tells you when data was last synced or that a connection broke.

## 4. The new user journey

### 4.1 First visit (new person)

1. Open the invite link, pick a password (unchanged).
2. A short **welcome wizard** instead of walls:
   1. **About you**: name, birth date, sex, units (metric/imperial). Only what Garmin does not provide.
   2. **Connect Garmin**: Garmin email and password, then the MFA code if Garmin asks for one. A clear note that the
      password is used once and not stored. *Skip* is possible: upload files instead.
   3. **How far back**: last 3 months, 1 year, or everything.
3. Straight to **Today**. While the first sync runs, cards show "Syncing your Garmin data…" with progress instead
   of empty charts. Activities appear as they come in; history fills up over the following hours or days.

### 4.2 Every day

- Open the app, land on **Today**.
- A small status dot in the header: "Synced 12 minutes ago". If Garmin needs a new login, a banner says so, with a
  button that goes straight to reconnecting.

### 4.3 Navigation (one app, one menu)

| Section | Pages |
|---|---|
| **Today** | Snapshot of last night and today: sleep, Body Battery, HRV status, resting heart rate, stress, steps, training readiness; latest activity; latest weight. Every card opens its detail page. |
| **Health** | Sleep · Heart (resting heart rate, HRV) · Stress & Body Battery · Body (weight, body composition) · Fitness (VO2max, training status) |
| **Training** | Activities · Calendar · Training load · Records (best efforts) · Heatmap · Gear |
| **Insights** | Placeholder now; later the AI analysis and recommendations. |
| **Settings** | Profile · Connections (Garmin, file upload) · Display (units, theme, dashboard layout) · Notifications · Users (owner only) · Security |

Every health detail page follows the same pattern: the latest value, how it compares to *your* normal range
(baseline), and a chart with 7 days / 30 days / 3 months / 1 year.

Removed from the navigation: Segments, Challenges, Photos (Strava), Badges, Eddington, Rewind and Milestones move into
Training as secondary views or are dropped (see the open questions).

### 4.4 Empty states, not gates

No more forced redirects. Every page that has no data yet explains why and what to do ("Connect Garmin",
"Syncing…", "No sleep data: wear your watch at night"). The setup gates (athlete form, finish setup) are removed.

## 5. Architecture

### 5.1 Overview

```
            ┌──────────────────────────────── data/<user>/ ───────────────────────────────┐
            │                                                                              │
Garmin ◄──► health-sync (Python, one container)  ──writes──►  inbox/activities/*.fit       │
Connect     · per-user Garmin sessions (tokens 0600)          inbox/health/*.json          │
            · hourly sync with jitter and backoff                       │                  │
            · internal HTTP API (login, MFA, status)                       ▼                  │
                     ▲                                 daemon: import inbox ──► SQLite (per user)
                     │ login + MFA, status                                          │
            Dreeve app (PHP) ── Settings → Connections                     Today / Health / Training
            └──────────────────────────────────────────────────────────────────────────────┘
```

- **health-sync** is a new, small Python service (one container for all users), built on `python-garminconnect`
  (MIT). It replaces the per-person Garmin connector container.
- It only **writes files** into each user's inbox; the PHP side imports them. The two sides share no database and
  only talk over HTTP for the login flow and status. A broken sync can never corrupt the app's data.
- The MFA step requires keeping the half-finished Garmin login in memory between "password" and "code". health-sync
  holds it for 5 minutes, per user. It is not reachable from outside the Docker network and checks a shared secret.

### 5.2 Data sources as a general concept

A `DataSource` (Garmin, File upload, later Eufy, Apple Health export, …) delivers **canonical records**:
activities, daily summaries, sleep, HRV, body measurements. Every record keeps its source and the source's own id, so
the same measurement arriving twice (for example a weigh-in synced to both Garmin and Eufy) is stored once, with a
clear priority when sources disagree.

The original payload of every record is archived, compressed. When parsing improves, data can be re-processed
without fetching it from Garmin again.

### 5.3 New data model (per user, SQLite)

| Table | One row per | Main fields |
|---|---|---|
| `DailySummary` | day | steps, distance, floors, active and total calories, intensity minutes, resting / min / max heart rate, average and max stress, Body Battery high / low / charged / drained, SpO2, respiration |
| `SleepSession` | night | start, end, deep / light / REM / awake time, sleep score, average respiration, average overnight HRV |
| `HrvSummary` | night | last-night average, weekly average, status, baseline range |
| `FitnessStatus` | day | VO2max (running, cycling), training readiness, training status, acute load |
| `BodyMeasurement` | weigh-in | weight, BMI, body fat %, muscle mass, bone mass, body water %, visceral fat, source |
| `SourceRecord` | received payload | source, type, external id, date, compressed raw payload |
| `IntradaySeries` *(later)* | day and type | heart rate, stress, Body Battery through the day, compressed like activity streams |

The current weight history (stored in a settings field) moves into `BodyMeasurement`. Resting heart rate, maximum
heart rate and heart rate zones come from Garmin data; manual values remain possible as an override.

New measurement units: beats per minute, milliseconds (HRV), percentage, score, steps, ml/kg/min (VO2max).

### 5.4 Baselines

Most health numbers only mean something compared to your own normal. A `Baseline` service calculates, per metric,
a rolling 7-day average and a personal normal range (for example the 60-day median ± spread). The Today cards and the
detail pages show "within / above / below your normal". The same numbers are the foundation for the AI analysis.

### 5.5 Activities

- Garmin activities arrive as original `.fit` files and go through the existing, well-tested FIT import. The FIT
  parser already reads Garmin files.
- Strava-specific code is removed: the Strava client, OAuth, webhooks, the Strava import pipeline, challenges,
  Strava segments, Strava photos and the Strava AI tools. The activity id scheme and stream names stay as they are, to
  keep all existing analytics working.

### 5.6 Prepared for the Eufy scale (groundwork built in phase 4)

Two possible routes, decided when we get there:

1. **Via Garmin**: a small bridge sends Eufy weigh-ins to Garmin Connect; they then arrive through the existing sync.
2. **Direct**: a `eufy` adapter in health-sync (the unofficial EufyLife cloud API) writes body measurements into the
   inbox, with source `eufy`.

Either way, `BodyMeasurement` with source attribution already supports it. Phase 4 added a source-neutral
delivery format (`kind: body`, see `BodyMeasurementDelivery` and the docs page "Weigh-ins from other sources"):
any adapter, script or home automation can drop weigh-ins into a user's health inbox today. Eufy cannot sync
to Garmin by itself, only to Apple Health, Fitbit and Google Fit.

### 5.7 Prepared for AI analysis (groundwork built in phase 4)

- **HealthSnapshot**: builds a compact, structured summary of a period (metrics, baselines, deviations, training load,
  notable activities, sleep consistency). Deterministic and testable, and useful without AI too (e.g. a weekly
  summary).
- **InsightProvider** interface and an `Insight` table (period, type, content, model, created at). No provider
  implementation yet.
- Built in phase 4: `HealthSnapshotBuilder` (weekly summary on the Insights page), the `Insight` table and
  repository, the `InsightProvider` interface, and a `get_health_snapshot` tool for the existing AI chat, whose
  prompt is now about health and training instead of Strava.
- The existing AI integration (Neuron AI, many providers including a local Ollama model) is kept; the Strava-specific
  prompt and tools are removed.
- AI is **off by default** and an explicit per-user choice, because health data would be sent to the chosen AI
  provider. A local model keeps everything on your own server.

### 5.8 Multi-user and security

- The multi-user foundation stays: per-user data folder and database, invites, owner-managed users.
- Public viewing is removed: every page requires login.
- One login for the whole app; no separate admin login.
- Garmin tokens are stored per user with owner-only permissions; health-sync is only reachable inside Docker.

## 6. Plan

Each phase ends with the full test suite, the code checks, an end-to-end run in Docker and a published image.

| Phase | Content |
|---|---|
| **0. Clean-up** | Remove Strava. Private by default. Merge the admin panel into the app shell (one navigation). Replace setup gates with empty states. Remove top-bar noise. |
| **1. Garmin connection** | health-sync service, Connect Garmin page with MFA, activity sync. Replaces the separate Garmin connector. |
| **2. Health data** | New tables, health import from the inbox, baselines, Today page, Health pages. Weight history migrated. |
| **3. Journey** | Welcome wizard, sync status and banners, settings consolidated, wording cleaned up (no daemon/cron/import mode). |
| **4. Foundations for later** | HealthSnapshot, InsightProvider interface, Eufy adapter slot, documentation. |

## 7. Risks

- **Unofficial APIs.** Garmin can change its login flow; health-sync must fail loudly (status banner) and recover
  with a re-login, never silently.
- **Rate limits.** Garmin throttles logins in particular. Sessions are reused; history is fetched slowly; sync backs
  off for hours after a 429.
- **No upstream merges.** This fork becomes its own product.
- **Translations.** New texts start in English; Dutch can follow.

## 8. Open questions

1. Which numbers matter most to you on **Today**? (Proposal: sleep, Body Battery, HRV, resting heart rate, stress,
   steps, training readiness, latest activity, latest weight.)
2. Keep, move or drop: **Eddington, Rewind (year in review), Milestones, Badges, custom Segments, Zwift stats,
   Photos**?
3. UI language: English, Dutch, or both?
