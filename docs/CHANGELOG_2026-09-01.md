# 2026-08-28 → 2026-09-01 — The six-hour silent outage, and making failure loud

**Commits:** `8a4df57` ("docker-done"), `b231e82` ("1ST SEP 2026")

Both commit messages are useless, which is why this document exists. Between them they
carry 805 insertions across ten files, four new systemd units, an infrastructure
teardown, and a schema migration. This is the record.

---

## 1. The finding

On **2026-08-28 at 05:27:35** telemetry stopped. It resumed at **12:03:39** when a human
noticed. **6 hours 36 minutes**, and the data is permanently gone — the broker had not
retained the QoS-1 session, so nothing replayed on reconnect.

Three things were true during those six hours, and each is worse than the last:

1. The application reported itself **healthy**.
2. It had **correctly detected the problem** — a `telemetry_stale` warning at 05:31 and a
   `telemetry_down` **critical** at 05:38, ten minutes in.
3. Nobody was told. `alert_events.notified_at` for those rows reads **11:19:01** —
   **5 h 41 m after detection**, and only because services were restarted by hand.

The detection layer worked exactly as designed. The delivery layer did not exist in any
meaningful sense.

---

## 2. Causes — four, and they compounded

### 2.1 A settings file was regenerated, and a fallback covered for it

`.env` was regenerated from `.env.example` on 2026-08-27 18:50. A key-by-key comparison
afterwards showed `.env` held the example's value for **every key except** `APP_KEY`,
`DB_CONNECTION` and `DB_DATABASE`. `APP_KEY` survived, so encrypted data was safe, and
the DB block was spotted and fixed. **The MQTT block was not.**

The real settings were `attendance.thecloudserv.com:8883` with credentials. The example
ships `MQTT_HOST=127.0.0.1`, `MQTT_PORT=1883` and blank credentials.

This is worth being precise about, because it is easy to draw the wrong lesson: the key
was **present and wrong**, not missing. `MQTT_HOST=127.0.0.1` was sitting in `.env`.

### 2.2 An idle local broker accepted the connection

A local mosquitto was running with `allow_anonymous true`. The misconfigured application
connected to it successfully and received nothing. Had no broker been listening, the
consumer would have failed with `Connection refused` within a second.

**The idle broker is what converted a loud failure into a silent one.**

### 2.3 The disk was full, and that is what actually killed the consumer

`storage/logs/laravel.log` had reached **16.92 GB**. Measured growth: **247 MB/day and
rising** (153 → 247 MB/day over the preceding five weeks). Composition of a 200 MB
sample:

| Lines | Share of bytes |
|---|---|
| `Read data from the socket (without blocking)` | **97.0%** |
| All `MQTT [` lines | **99.5%** |
| Everything the application actually logs | 0.5% — about 1.3 MiB/day |

`MQTT_ENABLE_LOGGING=true` makes php-mqtt log **every non-blocking socket poll**, not
every message. Four meters produce ~4,900 messages/day; the library was recording
hundreds of thousands of empty checks.

Then Laravel Pail multiplied it. `storage/pail/` held **four** `.pail` files receiving
**byte-identical writes**, while only one `pail` process was running. Pail registers a
file per listener and the app writes to every registered file; `Ctrl+C` on `composer dev`
never removes it. Three were orphans dating to 10 and 18 August. **Every log line was
being written to disk four times.**

Monolog then threw `UnexpectedValueException: No space left on device`, which is what
stopped the consumer.

### 2.4 Nothing restarted it, and nothing could tell anyone

`ConsumeMeterTopic` carries `--restart-after=50000` with the comment *"so the supervisor
recycles memory"*, and another at line 144 saying *"the supervisor restarts the process
with a clean PHP heap"*. **There was no supervisor.** At ~4,200 messages/day the consumer
had been exiting cleanly roughly **every 12 days** and being restarted by hand — read as
a series of one-offs rather than a pattern.

Delivery failed on every channel simultaneously: `MAIL_MAILER=log` wrote the alert **into
the log file on the disk that had just filled**; the broadcast leg reached an unbindable
Reverb (phpMyAdmin held port 8080), producing `BroadcastException: Pusher error: 404`;
and `queue:listen --tries=1` destroyed each job on its first failure. **574 notification
jobs had accumulated in `failed_jobs` since 2026-08-01, and nothing was watching the
count.**

---

## 3. What changed, and why each choice

Organised as three rules, because five separate fixes would not prevent a sixth failure.

### Rule 1 — nothing runs unsupervised

Before, **one** of five long-running processes was supervised. `composer dev` held
`serve`, `queue:listen`, `pail` and `vite` under `concurrently --kill-others`, so one
crash took the queue worker down with it; the scheduler and Reverb were bare background
processes that would not survive a reboot.

Four systemd user units now exist (`deploy/systemd/local/`, with production templates in
`deploy/systemd/`):

| Unit | Replaced |
|---|---|
| `iot-meter-consumer` | already existed |
| `iot-meter-queue` | `queue:listen --tries=1` inside `composer dev` |
| `iot-meter-scheduler` | a bare background process |
| `iot-meter-reverb` | a bare background process |

**`Restart=always`, deliberately not `on-failure`.** Both the consumer and the queue
worker exit *successfully* on purpose to recycle the heap; `on-failure` would leave them
stopped. This is the single most important line in the units.

**`queue:work --tries=3 --backoff=10,60,300 --max-time=3600`** replaces
`queue:listen --tries=1`. The old flag is why 574 alerts were destroyed.

`composer.json` was edited so `composer dev` runs only `serve`, `pail`, `vite`.

**Verified**: all four `SIGKILL`ed, all four back inside 10 seconds; all four `enabled`
at boot; `loginctl enable-linger` set (it was `no`, so user units would not have survived
logout).

### Rule 2 — nothing is unbounded

| Sink | Before | After |
|---|---|---|
| `laravel.log` | 247 MB/day, no rotation | `MQTT_ENABLE_LOGGING=false`; `LOG_STACK` `single`→`daily`, 14-day retention |
| `storage/pail/` | 9.3 GB, ×4 amplification, never pruned | 3 orphans deleted; **prune still to build** |
| journald | **no limit set — default ~11.7 GB** | `SystemMaxUse=200M` drop-in |

Measured result: **247 MB/day → 4.2 MB/day**, a 59× reduction. Disk went from
**5.9 GB free (95% full)** to **36 GB free (68%)**, roughly 29 GB reclaimed across the
pail orphans, journald, the legacy log, and the `iot-mvp` teardown below.

The frozen 16.92 GB `laravel.log` was truncated with `: >` rather than `rm`, preserving
the inode so any process still holding it would write to a valid file.

### Rule 3a — fail fast on missing configuration

`config/mqtt-client.php` had `env('MQTT_HOST', '127.0.0.1')`. The fallback is now gone
and `AppServiceProvider::assertRequiredConfiguration()` refuses to boot when
`MQTT_HOST`, `MQTT_CLIENT_ID`, `DB_DATABASE` or `APP_KEY` are missing or blank, naming
the offending key. Skipped under tests, which run on in-memory SQLite with no broker.

**Stated limit, recorded in the code comments as well as here: this would NOT have
prevented the 2026-08-28 outage.** The key was present and wrong, and this guard only
catches absent or blank. It is worth having — a deleted line now fails loudly instead of
silently pointing at localhost — but it must not be credited with more than it does.

A separate `?: null` coercion on `auth.username` / `auth.password` fixes a real bug found
the same day: a blank `MQTT_USERNAME=` resolves to `''`, not `null`, and php-mqtt rejects
an empty-string credential with *"The username may not consist of white space only."*

### Rule 3b — the system watches itself

`system:scan-health` (scheduled every five minutes) is a deliberate sibling of
`ScanMeterHealth`: it opens and resolves `AlertEvent` rows and fires
`AlertOpened`/`AlertResolved` into the **existing** delivery pipeline, inheriting
coalescing, digests and `alerts:prune` for free.

Checks: disk (warn 80%, critical 90%), the four units being `active`, `failed_jobs`
depth, and log sinks against declared ceilings. Thresholds live in
`config/system-health.php`, all env-overridable so a test can force a condition.

One migration was required: `alert_events.device_id` was `NOT NULL`. It is now nullable,
with `device_type = 'system'` marking machine-level alerts.

**This worked because the pipeline already supported it.**
`EnqueueAlertForDelivery::recipientIds()` was already null-safe on device
(`if ($alert->device && $alert->device->user_id)`) before routing to fleet operators —
exactly the right audience for "the disk is filling". Before shipping, we verified a
recipient actually exists (one user with `alerts.fleet_scope` and `fleet_scope = 'all'`);
without that check the alerts would have been raised perfectly and delivered to nobody,
which is the August failure in a new place.

On first run it immediately raised two real alerts: disk at 86%, and the 574 failed jobs.

### Infrastructure teardown

`/home/hammaad/iot-mvp` — an older prototype, not under version control — was deleted
with its Docker stack: `iot-mysql` (database `iot_app`, 10 tables, `telemetry` = **2
rows**), `emqx`, its phpMyAdmin, the `iot-mvp_mysql_data` volume, and four unused images.

Two findings behind that decision:

- **phpMyAdmin had never shown this project's data.** It was configured `PMA_HOST: mysql`
  → the *Docker* MySQL, so it displayed `iot_app`. The real database is native
  **MariaDB 10.11** on `127.0.0.1:3306`.
- **`iot-redis` had no definition anywhere** — started by `docker run` on 2026-07-10 with
  an anonymous volume. `CACHE_STORE=redis` depends on it.

A `docker-compose.yml` now lives in this repository declaring both services. phpMyAdmin
reaches MariaDB over the **unix socket** (`/run/mysqld/mysqld.sock`) rather than TCP,
because MariaDB is correctly bound to `127.0.0.1` and a bridge-network container cannot
reach that. Host networking was unavailable (port 80 taken) and widening `bind-address`
would expose the database to every container. The socket directory is mounted, not the
socket file, because MariaDB recreates that inode on restart.

**Caveat recorded in the compose file:** the socket is `srwxrwxrwx` and MariaDB root uses
`unix_socket` auth, so any container mounting it gets root database access without a
password. Acceptable only while the port stays bound to `127.0.0.1`.

Both local brokers were stopped — EMQX with the teardown, mosquitto via
`systemctl disable --now`. Per §2.2 this is a safety feature: the same misconfiguration
now fails immediately. `docker-compose.yml` carries a comment saying not to add one back.

---

## 4. Measurements taken, for future reference

Taken against the live database inside a rolled-back transaction (verified: zero rows
persisted).

| Metric | Value |
|---|---|
| Database queries per ingested message | **16** |
| DB time per message | 4.95 ms |
| Processing wall time | mean 9.85 ms, p95 13.20 ms |
| Broadcast, Reverb up | mean 1.52 ms |
| **Total per message** | **≈ 11.4 ms → ~88 messages/second, single process** |
| Observed rate | 0.044 msg/sec — **0.05% of capacity** |
| Storage per reading | 362 B (`meter_readings`) + 519 B (`meter_ingestion_events`) = **881 B** |
| Readings per meter per day | 1,232 (7-day average) |

At the 50-device 12-month plan this is 0.6% of measured ingestion capacity. **Throughput
is not a constraint and should not receive effort.** Retention analysis is in
[DATA_RETENTION_DECISION.md](DATA_RETENTION_DECISION.md).

---

## 5. The honesty pass

Three claims made during this work were wrong and are corrected here, in the spirit of
`d91d9a3` ("stop the workflow and the handbook asserting things that are not true").

1. **"Fail-fast config would have prevented the outage."** It would not. The key was
   present and wrong; the guard catches absent and blank. Corrected in §3 Rule 3a and in
   the code comments.
2. **"Monthly partitioning of `meter_readings` should be adopted."** Recommended in the
   retention analysis without having read that `PENDING_WORK.md` had already deferred it
   as **A11b**, for a better reason than was accounted for: the suite runs on SQLite,
   which has no partitioning, so a driver-guarded migration puts a schema shape into
   production that CI never exercises. **A11b's deferral stands**, with its stated
   triggers (prune exceeding ~2 min, or the table passing ~50 M rows).
3. **"The redundant `exists()` call is a new finding."** It was already documented under
   **A19** as one of the cheap wins to take when ingest lag fires.

Also corrected: an early storage estimate counted only `meter_readings` and missed
`meter_ingestion_events`, which is the larger of the two (519 vs 362 bytes). The figure
was understated by roughly 45%.

---

## 6. What this did NOT fix

- **Alert delivery still travels the failing path.** `MAIL_MAILER=log` remains, so a
  disk-full alert would still be written to the disk that is filling. Tracked as
  `PENDING_WORK.md` §0 #4(b).
- **The pail orphan prune was not built.** Two new orphans appeared within hours of the
  cleanup — the problem recurs on every `Ctrl+C` of `composer dev`.
- **`RangeConsumption` silently undercounts** for windows starting before any retention
  cutoff. Must be guarded before pruning is enabled.
- **Device-time defect (`PENDING_WORK.md` §0 #5) is live**, not historical: 130 rows with
  `ts < 100000`, most recent 2026-09-02 08:31 with `ts=107`.
