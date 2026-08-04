# Pending Work

Everything shipped through 2026-07-03 (consumption/reporting, alert delivery, opt-in alert triggers) is live
in code with a green suite. What remains:

## 1. FGAC — IN PROGRESS (hybrid: permissions enforced by code, bundles for admins)
**Plan + status ledger:** `docs/FGAC_IMPLEMENTATION_PLAN.md` (v2) · **Permission matrix:** `docs/FGAC_FEATURES_PERMISSIONS.csv`

Done 2026-07-10 (phases R, 0–4, commits `c812e7f`…`396258f`): repo hygiene, Redis cache
(docker `iot-redis` + predis), Spatie + `Gate::before` bypass, permission catalog +
5 bundles seeded, all legacy users migrated to bundles, self-serve registration →
consumer bundle (`AUTH_ALLOW_REGISTRATION`), and the `/users/{user}/permissions`
Manage Access screen.

Done 2026-07-13 (`21e0b7a`, `dfab8cf`, `da16779`, `f20a55b`): Phase 5 enforcement
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

## 2. Delivery scaling ("Phase C") — pull each item only when its trigger fires
No new alert types here; purely how alerts get out. All additive.

| Item | Trigger to build it | Shape |
|---|---|---|
| Redis + Horizon (priority queues) | queue latency/depth climbs with device volume | config swap — everything is already queued |
| SMS / web-push channels | users miss email-only alerts | add channel methods to `AlertDigestNotification` + a pref field |
| Escalation re-notify | operators want reminders on long-open criticals | scheduled job over `alert_events` + one new transition |
| Horizontal Reverb / managed pusher | concurrent WebSocket connections near node limit | infra/driver change |
| Digest window tuning | first observed correlated burst | a number |

## 3. Availability alerts — NEXT, designed and ready to build
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
