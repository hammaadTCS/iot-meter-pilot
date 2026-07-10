# Pending Work

Everything shipped through 2026-07-03 (consumption/reporting, alert delivery, opt-in alert triggers) is live
in code with a green suite. What remains:

## 1. FGAC — IN PROGRESS (hybrid: permissions enforced by code, bundles for admins)
**Plan + status ledger:** `docs/FGAC_IMPLEMENTATION_PLAN.md` (v2) · **Permission matrix:** `docs/FGAC_FEATURES_PERMISSIONS.csv`

Done 2026-07-10 (phases R, 0–4, commits `c812e7f`…`396258f`): repo hygiene, Redis cache
(docker `iot-redis` + predis), Spatie + `Gate::before` bypass, 29-permission catalog +
5 bundles seeded, all legacy users migrated to bundles, self-serve registration →
consumer bundle (`AUTH_ALLOW_REGISTRATION`), and the `/users/{user}/permissions`
Manage Access screen. **Legacy role checks still enforce everything** — behavior is
unchanged until the Phase 5 cutover.

Remaining: Phase 5 (enforcement cutover — plan §6; includes the two alerting seams below),
Phase 6 (view cutover, meter dashboard section permissions), Phase 7 (delete role column,
legacy middleware, `updateRole`, role-badge), Phase 8 (CI guardrails + doc rewrite).

The alerting seams to swap in Phase 5:
- `App\Listeners\EnqueueAlertForDelivery::recipientIds()` (who is *delivered* an alert) → `alerts.fleet_scope`
- `App\Models\AlertEvent::visibleTo()` (who can *see* alerts in the console) → `alerts.view_any`

## 2. Delivery scaling ("Phase C") — pull each item only when its trigger fires
No new alert types here; purely how alerts get out. All additive.

| Item | Trigger to build it | Shape |
|---|---|---|
| Redis + Horizon (priority queues) | queue latency/depth climbs with device volume | config swap — everything is already queued |
| SMS / web-push channels | users miss email-only alerts | add channel methods to `AlertDigestNotification` + a pref field |
| Escalation re-notify | operators want reminders on long-open criticals | scheduled job over `alert_events` + one new transition |
| Horizontal Reverb / managed pusher | concurrent WebSocket connections near node limit | infra/driver change |
| Digest window tuning | first observed correlated burst | a number |

## 3. Noted, deliberately deferred
- Real mail transport (`MAIL_MAILER=log` today — bell works without it; email needs SES/Postmark).
- New device types (AC/switch/water): add a payload processor + a detector; `alert_events` and the delivery
  pipeline need no changes. Readings-table strategy decision noted in `docs/erd.md`.
- Raw-readings export was intentionally **not** built (product decision: aggregate exports only).
