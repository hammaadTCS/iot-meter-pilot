# Pending Work

Everything shipped through 2026-07-03 (consumption/reporting, alert delivery, opt-in alert triggers) is live
in code with a green suite. What remains:

---

## 0. DO THIS NEXT — commercial-hardening sprint (revised 2026-08-04)

**This section is the current work queue and overrides the ordering implied by §1–§3.**
Week 1 shipped (see `PROJECT_HANDBOOK.md` §14). What follows is the revised order for
what comes after it.

### Why the order changed

The original plan put broker security (TLS, per-device credentials) immediately after
Week 1. Measuring the live system on 2026-08-04 changed that judgement:

- **The fleet is 5 devices, 4 reporting, ~9 messages/minute at peak.** The MQTT
  plaintext exposure is real but bounded to five known pilot households.
- **All customer data has zero recovery capability.** No backups, no tested restore.
- **454 queue jobs had been failing since 2026-08-01 and nobody knew.** Every one a
  `BroadcastException` (Reverb unreachable). The bell still worked, so damage was
  limited — but three days of invisible failure is the point, not the damage.
- The broker cutover is the **riskiest operation in the whole plan**: fleet-wide,
  firmware-coordinated, hard to reverse. Running it before backups and error visibility
  exist is doing the most dangerous thing with the weakest safety net.

So: **recoverability and observability first, broker second.** Same total effort,
reordered. Full reasoning in `THREAT_MODEL.md`.

### The queue

| # | Item | Effort | Why it sits here |
|---|---|---|---|
| 1 | **Mail transport** — ~~free tier SMTP + switch `config/mail.php` default to the `failover` mailer~~ **DONE** `2a82216` (plus a `mail:test` command to verify end to end). **A4** email verification is **still open** — `MustVerifyEmail` remains commented out in `app/Models/User.php`. | 20 min + 4 h | Unblocks the skipped pair in §4a at zero cost. No procurement needed. |
| 2 | ~~**Triage the 454 failed jobs, and make the broadcast leg non-fatal**~~ — **DONE** `3e9d5d4`. Live push is cosmetic and must never fail alert delivery. | 2 h | Live bug; the noise also hides real failures. |
| 2b | **npm advisories + split `package.json`** — 11 advisories (2 critical, 7 high) as of 2026-08-27. Only one chain genuinely ships: **axios**, set on `window.axios` in `resources/js/bootstrap.js`. The rest are dev-server, build-time, or a socket.io transport we never import — chain-by-chain reasoning is in the comment on the `npm audit` step in `ci.yml`. Fix axios, then **split `package.json` into `dependencies` (alpinejs, axios, laravel-echo, pusher-js) and `devDependencies` (build tooling)**; there is no `dependencies` block at all today, which is why `--omit=dev` reports a misleading zero. Then make the `npm audit` step blocking. | 3 h | Small, and it converts CI's last non-blocking step into a real gate. |
| 3 | **A10 backups** — `spatie/laravel-backup` → S3-compatible (Cloudflare R2: zero egress, which matters because a restore drill pulls the whole archive), **encrypted** (`cnic`/`phone`/`address` are leaving the building), plus an automated monthly restore-and-diff. **Tier it:** business data (users, devices, rollups, alerts, permissions) daily and long-retained; `meter_readings` weekly and short-retained — the rollups are the product, raw readings are the audit trail. | 1 d | Highest business risk, zero external dependency. **Also unblocks FGAC Phase 7**, whose rollback plan cites a backup that does not exist. |
| 4 | **A12 observability, in two layers.** (a) Sentry across all five processes, verified with a test exception from each; `LOG_STACK` `single` → `daily`. (b) **Silence detection** — Sentry cannot see "nothing happened", which is the characteristic IoT failure: fleet-wide reading freshness, `failed_jobs` growth, and per-device stalled promotion. Plus an **external** uptime monitor on `/up`, because everything else runs inside the box and cannot report its own death. | 5 h | The 454-job blind spot is the argument. Needed before any fleet-wide operation. |
| 5 | **Device-time trust guards** (new — see below) | 4 h | Closes a live silent-failure mode. |
| 6 | **Firmware version tracking** (new — see below) | 3 h | Prerequisite for a safe OTA. |
| 7 | **Supervise the remaining four daemons** — standardise on systemd and delete the supervisor config (two mechanisms with conflicting paths today). `queue:work` gets `--max-time=3600`; the **scheduler becomes a systemd timer, not a cron line**, because a dead scheduler means no alerts are ever *created* and cron fails silently. | 3 h | |
| 8 | **A7 TLS → A6 per-device credentials + ACLs**, canary first: one device → verify 24 h → rest of fleet | 2–3 d | Now with backups, visibility, device-trust guards and fleet awareness in place. |

After 8: **A11a** (`raw_payload` retention), **FGAC Phase 7**, **Phase 8 guardrails**,
then §3 availability alerts merged with the ingestion watchdog.

### New item — device-time trust guards (#5)

**Found in live data 2026-08-04.** Every device has `meter_readings` rows with `ts`
values of 8, 9, 10 alongside real epochs. Cause: the ESP8266 has no real-time clock, so
before NTP syncs its clock reads seconds-since-boot, and the firmware publishes that as
`ts`.

Currently benign — `shouldPromoteToLatestState` correctly rejects them, rollups key on
`$receivedAt` (server time) so nothing is corrupted, and all four active devices are
healthy. **But it is a latent silent failure:** if NTP never syncs, a device publishes
low `ts` forever. Readings are stored, `last_seen_at` updates *unconditionally* so
`ScanMeterHealth` reports the device **ONLINE**, yet nothing promotes and consumption
silently stops accruing. The customer sees a live-looking meter with a frozen kWh figure
and no alert fires.

Three server-side changes (all under our control, unlike firmware):

1. **Plausibility guard** — reject `ts` outside `[now − 30d, now + 1d]`, recorded in
   `meter_ingestion_events` under a new `implausible_ts` status. This is the cheap first
   half of A17.
2. **Stop conflating arrival with validity** — `last_seen_at` is set before the promotion
   check, which is exactly why a stuck device looks healthy. Set it only for accepted
   readings, or add `last_valid_reading_at` and point `healthStatus()` at that.
3. **New detector: stalled promotion** — readings arriving but `latest_state.ts` not
   advancing for N minutes. Not in `ALERT_CATALOG.md`, and not detectable by any existing
   check. Fold into the §3 availability work.

**Firmware side (root cause):** do not publish before NTP syncs — buffer locally, or
publish with an explicit `time_valid: false` flag. Longer term a **monotonic sequence
number per device is more robust than a timestamp** for ordering, since counters need no
clock and do not jump backwards on reboot. Schedule alongside the OTA work.

*Principle: never trust device time for business logic. Use server receive time for
anything affecting money or reporting; use device time only for ordering within a single
device, and validate it. The existing rollups already do this correctly — which is why
this bug produced junk rows instead of corrupted bills.*

**Interaction with #8:** TLS certificate validation needs a correct clock, so after A7 an
NTP failure becomes a *loud* connection failure instead of silent data loss. Better — but
expect the TLS cutover to surface latent NTP problems as sudden device dropouts.

### New item — firmware version tracking (#6)

`devices` has no `firmware_version`, `hardware_revision`, `provisioned_at` or
`last_ota_at`. Two coordinated OTA windows are coming (TLS, then credentials), each with
a dual-accept period during which **every meter must be confirmed migrated before the old
path closes** — and there is currently no way to answer "which devices are on the new
firmware?" except by inferring it from connection behaviour.

At 5 devices that is eyeballable. At 50 it is not, and a half-migrated fleet with no
visibility is how OTA rollouts become outages.

- Add `firmware_version`, `hardware_revision`, `last_ota_at` to `devices`
- Firmware reports its version in the payload or on the status topic; store it on ingest
- Surface it in the devices list; scheduled check for "devices not on the target version"
- Run every OTA as a canary: one device → verify 24 h → rest of fleet

### Deliberately NOT scheduled

- **A11b monthly partitioning of `meter_readings`** — the suite runs on SQLite, which has
  no partitioning, so a driver-guarded migration would put a schema shape into production
  that CI never exercises. A11a captures most of the storage win at no risk. **Trigger:**
  prune job exceeds ~2 min, or the table passes ~50 M rows.
- **A19 capacity work beyond the baseline** — measured 2026-08-04: peak ~9 msg/min, about
  1,000× below where ingestion strains. **Trigger:** ingest lag stops returning to zero
  overnight. When it fires, the cheap wins come first (drop the redundant `exists()`,
  batch ingestion-event writes, move rollups to a queued job — plausibly 2–4×) before any
  sharding or time-series store.

---

## 1. FGAC — IN PROGRESS (hybrid: permissions enforced by code, bundles for admins)
**Plan + status ledger:** `docs/FGAC_IMPLEMENTATION_PLAN.md` (v2) · **Permission matrix:** `docs/FGAC_FEATURES_PERMISSIONS.csv`

Done 2026-07-10 (phases R, 0–4, commits `f50eb0a`…`b4b40b6`): repo hygiene, Redis cache
(docker `iot-redis` + predis), Spatie + `Gate::before` bypass, permission catalog +
5 bundles seeded, all legacy users migrated to bundles, self-serve registration →
consumer bundle (`AUTH_ALLOW_REGISTRATION`), and the `/users/{user}/permissions`
Manage Access screen.

Done 2026-07-13 (`54cae7e`, `3e8cd52`, `f91dea9`, `8a1bde7`): Phase 5 enforcement
cutover (permissions gate every request, incl. the two alerting seams) and Phase 6
view cutover (dashboard sections render per permission; `meter.charts` is a
per-user opt-in; reporting panels are basic).

Done 2026-07-14: **simplified consumer dashboard** (plan §3.4) — new
`meter.full_dashboard` slug (prosumer bundle); consumers get 4 KPI tiles +
click-to-expand hour/day usage history from the new `meter_hourly_consumption`
rollup + `GET /readings/aggregate`; raw minute-level readings are now
full-dashboard-only, in the view AND the API. 30-permission catalog.

Remaining: Phase 7 (delete role column, legacy middleware, `updateRole`,
role-badge), Phase 8 (CI guardrails + doc rewrite, incl. the bundle snapshot test).

> **Both are now gated — see §0.** Phase 7 drops `users.role` irreversibly and its own
> rollback plan (`FGAC_IMPLEMENTATION_PLAN.md` §8) says *"restore column from the
> pre-migration backup"* — a backup that does not exist. **Phase 7 waits for §0 item 3.**
> It also waits for A4, since both rewrite `app/Models/User.php` and doing them together
> makes a login regression impossible to attribute.
>
> Phase 8's three guardrails are defined as CI steps. CI landed 2026-08-04 (`33a219c`)
> but **failed on every run from that day until 2026-08-27**, so the dependency was not
> actually satisfied — the guardrails would have been added to a suite that could not
> pass. Repaired in `efc8ab3`; the gate is green now and the dependency genuinely holds.
> Full account: [CHANGELOG_2026-08-27.md](CHANGELOG_2026-08-27.md).

## 2. Delivery scaling ("Phase C") — pull each item only when its trigger fires
No new alert types here; purely how alerts get out. All additive.

| Item | Trigger to build it | Shape |
|---|---|---|
| Redis + Horizon (priority queues) | queue latency/depth climbs with device volume | config swap — everything is already queued |
| SMS / web-push channels | users miss email-only alerts | add channel methods to `AlertDigestNotification` + a pref field |
| Escalation re-notify | operators want reminders on long-open criticals | scheduled job over `alert_events` + one new transition |
| Horizontal Reverb / managed pusher | concurrent WebSocket connections near node limit | infra/driver change |
| Digest window tuning | first observed correlated burst | a number |

## 3. Availability alerts — designed and ready to build (queued after §0)
**Full spec:** `docs/ALERT_CATALOG.md` §3 · **Catalog of every alert type:** `docs/ALERT_CATALOG.md`

The MQTT availability topic went live 2026-07-24 and its data is arriving but
unconsumed — it drives a UI pill and a live broadcast, nothing else. Four new alert
types are specified: `availability_offline` (explicit LWT — certain, and ~10× faster
than `telemetry_down`), `availability_silent` (broker connected but telemetry dead —
**not detectable any other way today**), `availability_never_reported`, and
`availability_unclassified`.

The one real design decision is precedence: an availability signal, when present,
owns the "is it reachable?" question and suppresses the `telemetry_*` alerts, so one
incident never produces three alerts. Meters with no availability topic are
unaffected. Implementation extends `ScanMeterHealth` (which already owns reachability
and its precedence rules) rather than adding a racing command; the per-meter
`offline_enabled` opt-out is reused, so there is no migration.

## 4. Noted, deliberately deferred

### 4a. Real mail transport + email verification — SKIPPED 2026-08-04 (decision)
Both were scheduled for the commercial-hardening sprint (items A8 and A4) and were
**deliberately skipped** because no mail provider was available at the time. They are a
pair: A4 must never ship without A8, or every new signup dead-ends on the "verify your
email" screen with the link sitting in a log file.

**What is broken while this stands:**
- `MAIL_MAILER=log` — every queued `AlertDigestNotification` writes its mail leg to
  `storage/logs/laravel.log`. The bell still works (the `database` + `broadcast` channels
  are independent), so this is silent: a customer whose meter goes offline gets an in-app
  notification and no email.
- **Password reset is non-functional** for the same reason — no account can be recovered.
- The `verified` middleware on every route in `routes/web.php` is **decorative**. `User`
  does not implement `MustVerifyEmail`, so Laravel's `EnsureEmailIsVerified` short-circuits
  on its `instanceof` check and every unverified user passes. `Registered` therefore never
  fires `SendEmailVerificationNotification` either. `tests/Feature/Auth/EmailVerificationTest.php`
  is green regardless, because the `MustVerifyEmail` *trait* is present on the framework's
  base class — the contract is what's missing. **Anyone can register with an address they
  do not own and get immediate access to that account.**

**To pick this up (roughly half a day once credentials exist):**
1. A8 — set `MAIL_MAILER=smtp` plus host/port/username/password/from-address. Any
   transactional provider's SMTP works with **no new composer package**; the `ses`,
   `postmark` and `resend` mailers in `config/mail.php` each need their bridge installed
   first. SPF + DKIM on the sending domain is the real long pole (SES additionally starts
   sandboxed).
2. A4 — uncomment the import at `app/Models/User.php:5` and add `implements MustVerifyEmail`;
   **ship a backfill migration setting `email_verified_at = now()` for all existing users**
   or the contract locks out every current account; add throttles to the register / login /
   forgot-password routes in `routes/auth.php` (login and forgot-password carry none today)
   **keyed on email+IP, not IP alone** — IP-only throttling makes customers behind
   carrier-grade NAT rate-limit each other.

**Deadline:** A4 must land **before FGAC Phase 7**. Both rewrite `app/Models/User.php`
(Phase 7 strips the role helpers and `'role'` from `$fillable`), and doing them in one
window makes a login regression impossible to attribute.

**Knock-on:** the device-claiming design (A5) specifies that claiming requires a verified
account, so until A4 lands the claim code is a weaker primitive than designed.

### 4b. Other deferrals
- New device types (AC/switch/water): add a payload processor + a detector; `alert_events` and the delivery
  pipeline need no changes. Readings-table strategy decision noted in `docs/erd.md`.
- Raw-readings export was intentionally **not** built (product decision: aggregate exports only).
