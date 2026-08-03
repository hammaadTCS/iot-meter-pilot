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
- Real mail transport (`MAIL_MAILER=log` today — bell works without it; email needs SES/Postmark).
- New device types (AC/switch/water): add a payload processor + a detector; `alert_events` and the delivery
  pipeline need no changes. Readings-table strategy decision noted in `docs/erd.md`.
- Raw-readings export was intentionally **not** built (product decision: aggregate exports only).
