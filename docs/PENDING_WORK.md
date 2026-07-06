# Pending Work

Everything shipped through 2026-07-03 (consumption/reporting, alert delivery, opt-in alert triggers) is live
in code with a green suite. What remains:

## 1. FGAC — the active pending task
Replace the 3-role system (user/admin/super_admin) with fine-grained permissions.
**Plan:** `docs/FGAC_IMPLEMENTATION_PLAN.md` · **Permission matrix:** `docs/FGAC_FEATURES_PERMISSIONS.csv`

The alerting subsystem already left FGAC its seams — swap these two spots and delivery/visibility become
permission-based with no pipeline rewrite:
- `App\Listeners\EnqueueAlertForDelivery::recipientIds()` (who is *delivered* an alert)
- `App\Models\AlertEvent::visibleTo()` (who can *see* alerts in the console)
- plus the role checks the FGAC plan already enumerates (routes, policies, blades).

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
