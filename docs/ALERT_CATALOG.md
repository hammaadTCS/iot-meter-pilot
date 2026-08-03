# Alert & Trigger Catalog — every condition the platform can detect

The single reference for what this platform alerts on: what ships today, what is
designed and ready to build, and what is deliberately deferred. Companion to
`docs/OPERATIONS_RUNBOOK.md` (how the detectors are scheduled) and `docs/erd.md`
(the tables they read).

Last updated: 2026-07-24 · **Shipped: 9 alert types across 3 detectors.**
**Designed, not yet built: 4 availability alert types (§3).**

---

## 1. The 30-second mental model

- **A trigger is a condition; an alert is a record.** Detectors run on a
  schedule, evaluate conditions, and write `alert_events` rows.
- **Every alert is stateful.** It opens once and resolves once — never one row
  per scan. That is the structural anti-spam guarantee, and it is why a meter
  down for a week produces one alert, not ten thousand.
- **Every alert is opt-in per meter** (`meter_alert_settings`), except offline
  detection, which is on by default and can be switched off.
- **Delivery is shared and generic.** A detector never sends anything. It emits
  `AlertOpened` / `AlertResolved`, and the pipeline does the rest.
- **Detectors never run on the ingestion hot path.** They are scheduled scans
  over `latest_meter_states` and the rollups — one indexed row per meter — so
  alerting can never slow MQTT intake. This is a hard architectural rule.

### The delivery pipeline every alert inherits for free

```
detector → AlertOpened/AlertResolved
         → EnqueueAlertForDelivery (queued)   resolve recipients:
                                              owner always + fleet operators
                                              (alerts.fleet_scope ∧ fleet_scope='all'
                                               ∧ severity ≥ their floor)
         → pending_alert_notifications        per-recipient buffer
         → alerts:dispatch-digests (1 min)    coalesce per user into ONE digest
         → bell (database) + broadcast + mail  gated by notification_preferences
                                               (severity floor, quiet hours)
```

Consequence: **a new alert type costs one detector method.** It needs no
delivery work, no notification work, and no permission work.

### Why new alert types are cheap

| Fact | Where | Why it matters |
|---|---|---|
| `alert_type` and `severity` are plain indexed **strings**, not enums | [create_meter_alert_events_table.php:21-22](../database/migrations/2026_04_27_010000_create_meter_alert_events_table.php#L21-L22) | A new type needs **zero schema change** |
| `alert_events` is **device-agnostic** (`device_type` column) | [AlertEvent.php](../app/Models/AlertEvent.php) | New device types reuse everything |
| Two reusable detector patterns already exist | `reconcile()` in [ScanConsumptionAlerts.php:165](../app/Console/Commands/ScanConsumptionAlerts.php#L165) · `evaluate()` in [ScanThresholdAlerts.php:107](../app/Console/Commands/ScanThresholdAlerts.php#L107) | Open-once/promote/resolve and hysteresis are solved |

---

## 2. Shipped alerts (9 types, 3 detectors)

### 2.1 Health — telemetry freshness
`meters:scan-health`, every minute · [ScanMeterHealth.php](../app/Console/Commands/ScanMeterHealth.php)

| `alert_type` | Severity | Opens when | Resolves when | Config |
|---|---|---|---|---|
| `telemetry_stale` | warning | Silent ≥ `METER_HEALTH_STALE_AFTER_SECONDS` (default **180s**) | Telemetry resumes, or state escalates to down | `offline_enabled` |
| `telemetry_down` | critical | Silent ≥ `METER_HEALTH_DOWN_AFTER_SECONDS` (default **600s**) | Telemetry resumes | `offline_enabled` |

Precedence is already handled: moving to `down` resolves any open `stale`, and
recovery resolves both. Turning `offline_enabled` off also resolves anything open,
so the console never shows an alert the user opted out of.

### 2.2 Consumption — budgets and anomaly
`alerts:scan-consumption`, hourly · [ScanConsumptionAlerts.php](../app/Console/Commands/ScanConsumptionAlerts.php)

| `alert_type` | Severity | Opens when | Config |
|---|---|---|---|
| `consumption_budget` | warning → critical | Month usage ≥ `monthly_budget_warn_pct` (default 80%), promoted to critical at ≥ 100% | `monthly_budget_kwh`, `monthly_budget_warn_pct` |
| `consumption_daily` | warning | Today's kWh ≥ daily budget | `daily_budget_kwh` |
| `consumption_anomaly` | warning | Today ≥ `anomaly_multiplier` × mean of last 7 completed days (needs ≥ 3 days of history) | `anomaly_enabled`, `anomaly_multiplier` |

All three read **only the rollups** (`meter_daily_consumption` /
`meter_monthly_consumption`) — never raw readings — and self-resolve at the next
period rollover.

### 2.3 Electrical thresholds — equipment safety
`alerts:scan-thresholds`, every minute · [ScanThresholdAlerts.php](../app/Console/Commands/ScanThresholdAlerts.php)

| `alert_type` | Severity | Opens when | Config |
|---|---|---|---|
| `threshold_voltage_high` | critical | V > limit | `voltage_high` |
| `threshold_voltage_low` | critical | V < limit | `voltage_low` |
| `threshold_power_max` | critical | W > limit × 1000 | `power_max_kw` |
| `threshold_pf_min` | critical | PF < limit | `pf_min` |

Two safeguards worth knowing: **hysteresis** — 3 consecutive breaching scans to
open, 3 clear scans to resolve (streaks persisted in `meter_threshold_states`, so
a momentary spike never pages anyone); and a **freshness guard** — readings older
than 10 minutes are not judged at all, because the health detector owns that
condition and grading a dead meter's last known voltage would be wrong.

### 2.4 The per-meter trigger menu

`meter_alert_settings` — 10 configurable fields, null = off
([MeterAlertSetting.php](../app/Models/MeterAlertSetting.php), UI at `/devices/{id}/alerts`):

```
monthly_budget_kwh   monthly_budget_warn_pct(80)  daily_budget_kwh
anomaly_enabled      anomaly_multiplier(2.0)
voltage_high  voltage_low  power_max_kw  pf_min
offline_enabled(true)
```

---

## 3. Availability alerts — designed, ready to build

**Status: specified here, not yet implemented.** The availability topic is now
live and publishing, so this data is arriving today and nothing consumes it
beyond a UI pill and a live broadcast.

### 3.1 Why availability is a genuinely different signal

Health alerts **infer** a problem from silence. Availability is the device (or the
broker's Last Will) **telling you** its connection state. Two consequences:

- **Speed.** An explicit offline signal is known within seconds. Inferring the
  same thing from telemetry silence takes 600s.
- **Discrimination.** Silence cannot distinguish "the network died" from "the
  device is fine but its metering stopped". Availability can — and that
  distinction is the difference between dispatching an engineer and not.

### 3.2 The five states already computed

[`Device::availabilityStatus()`](../app/Models/Device.php#L212) already derives
these from `last_availability_status`, `last_heartbeat_at` and `last_seen_at`.
Nothing new needs computing — this is why the work is small:

| State | Meaning | Alert-worthy? |
|---|---|---|
| `online` | Availability says online/heartbeat **and** telemetry is fresh | No |
| **`silent`** | Availability says online/heartbeat **but** telemetry is stale/down | **Yes — highest value** |
| **`offline`** | Explicit offline (LWT), not superseded by newer telemetry/heartbeat | **Yes** |
| `unknown` | No availability message has ever arrived | Maybe (installation gap) |
| `disabled` | `is_active` false — monitoring intentionally off | No |

The model is already careful about staleness: an old offline signal stops being
authoritative once newer telemetry or a heartbeat proves the device came back
([`offlineSignalWasSuperseded()`](../app/Models/Device.php#L296)).

### 3.3 Proposed alert types

| `alert_type` | Severity | Opens when | Why it earns its place |
|---|---|---|---|
| `availability_offline` | critical | State is `offline` — explicit LWT/offline payload | Certain, and ~10× faster than `telemetry_down`. No inference. |
| `availability_silent` | warning → critical | State is `silent`; **warning** while health is `stale`, promoted to **critical** at `down` | **Not detectable any other way.** Broker connection alive, data flow dead ⇒ firmware/sensor fault, not a network fault. Different repair path entirely. |
| `availability_never_reported` | warning | Availability topic configured, `last_availability_at` still null after a grace period | Installation was never completed — the device was provisioned but never spoke. |
| `availability_unclassified` | warning | Payloads arriving but normalising to `unknown` | Firmware publishing a vocabulary the processor doesn't recognise ⇒ config/firmware bug. Lowest priority of the four. |

Severity escalation for `availability_silent` deliberately **reuses the existing
health thresholds** rather than introducing new ones — same 180s/600s the user
already understands, and the `reconcile()` promote/demote pattern handles the
severity change with no new machinery.

### 3.4 The overlap problem — the one real design decision

A meter that genuinely loses power would, naively, fire **three** alerts for one
event: `availability_offline` (seconds), `telemetry_stale` (3 min),
`telemetry_down` (10 min). Three digests, one incident. That must not ship.

**Rule: when an availability signal exists, it owns the "is it reachable?"
question. Health alerts are the fallback for meters with no availability signal.**

| Availability state | Opens | Suppresses / resolves |
|---|---|---|
| `offline` | `availability_offline` | `telemetry_stale`, `telemetry_down` |
| `silent` | `availability_silent` (warning→critical) | `telemetry_stale`, `telemetry_down` |
| `unknown` (no signal ever) | — | Nothing — **today's health alerts behave exactly as they do now** |
| `online` / `disabled` | — | All of the above resolve |

Two things this buys: meters without an availability topic are **completely
unaffected** (full backwards compatibility), and every reachability incident
produces exactly one open alert with the most specific available explanation.

### 3.5 Where it belongs — extend `ScanMeterHealth`, don't add a command

`ScanMeterHealth` already owns reachability and already implements state
precedence (`down` resolves `stale`; recovery resolves both; opt-out resolves
everything). The precedence table in §3.4 is the *same kind of rule* over a
larger state set, and it must be applied atomically per device.

A separate `alerts:scan-availability` command would race with it — both writing
reachability alerts for the same device on the same schedule, with no shared
transaction. **One detector owns one question.**

Concretely: `syncDeviceAlerts()` grows from a 3-branch health `match` into a
branch on `availabilitySnapshot()['status']` first, falling through to today's
health logic when the state is `unknown`. The existing `openAlertIfMissing()` /
`resolveOpenAlerts()` helpers, the `lockForUpdate()` transaction, and the
after-commit event firing all carry over unchanged.

**Detection stays in the 1-minute scan, not the MQTT processor.** Alerting from
`MeterAvailabilityProcessor` would be instant, but it would put alert writes on
the ingestion hot path — the one thing this architecture refuses to do. Worst-case
latency of ≤60s is still an order of magnitude better than `telemetry_down`.

### 3.6 Configuration

**Recommendation: reuse `offline_enabled`.** It is already the per-meter opt-out
for "tell me when my meter is unreachable", which is exactly what these alerts
are. No migration, no new UI, and opting out keeps resolving everything.

If the two failure classes should be separable later — a user who wants "device
disconnected" but not "device connected yet mute" — add one nullable
`availability_silent_enabled` column then. Do not build it pre-emptively.

`availability_never_reported` needs one grace-period constant; put it in
`config/meter-health.php` alongside the existing thresholds.

### 3.7 Test coverage to add

`MeterAvailabilityProcessorTest` already covers payload normalisation. The new
tests are about **precedence**, since that is where the risk is:

- explicit offline → `availability_offline` opens **and** any open
  `telemetry_stale`/`telemetry_down` resolves in the same scan;
- availability online + telemetry stale → `availability_silent` at warning,
  promoted to critical once past the down threshold, and **no** `telemetry_*`
  alert opens alongside it;
- a meter with **no** availability signal → today's health alerts, unchanged
  (the backwards-compatibility guarantee);
- recovery → every reachability alert resolves, exactly once;
- `offline_enabled = false` → nothing opens and anything open resolves.

---

## 4. Deferred backlog

Analysed 2026-07-23, deliberately not scheduled. Signals already captured with no
trigger attached, so each is a detector method rather than a data project.

**Tier 1 — one settings column + a method in an existing detector**

| Candidate | Value | Reuses |
|---|---|---|
| **Zero-consumption / flatline** | Meter online and reporting **0 W** for N hours ⇒ CT clamp detached, breaker off, or tampering. Looks perfectly healthy today. Highest-value item in this table. | threshold `evaluate()` |
| Frequency high/low | Closes an obvious asymmetry — V, P and PF have thresholds; Hz is captured and ignored | `evaluate()` verbatim |
| Current max | Overcurrent independent of PF-distorted power | `evaluate()` verbatim |
| Budget forecast | "At this rate you exceed on the 24th" — actionable *before* 80%, which arrives too late to change behaviour | consumption `reconcile()` |
| Sustained average breach | Hour-average voltage out of band — catches slow drift that debounced instantaneous checks miss | hourly accumulators |

**Tier 2 — a new detector or new wiring**

Data-quality alerts from `meter_ingestion_events` (sustained `invalid_json` /
`payload_invalid` ⇒ firmware bug; `unknown_topic` ⇒ device in the field but not in
the platform); unexpected PZEM counter reset (today silently absorbed into
`rollover_wh` — a reset can mean power loss, tamper or replacement); and
`energy_computed_wh` vs `energy_pzem_wh` divergence as a metering-integrity check.

**Tier 3 — needs new domain or infrastructure**

- **Cost / tariff alerts.** No tariff, cost or currency domain exists anywhere in
  the schema — verified. Needs a tariff/slab model first. For a B2C product this
  is arguably the highest-value alert of all, because consumers think in money,
  not kWh. It is a domain project, not an alert.
- **Fleet-outage correlation.** 50 meters down at once is one broker incident, not
  50 device faults. Needs a correlation layer above the per-device detectors.
- **Escalation / re-notify** — already tracked as Phase C in `docs/PENDING_WORK.md`.
- **Ingestion-pipeline watchdog.** If `mqtt:consume-meter` dies, every meter goes
  stale and you get a storm instead of one "the consumer is down".

---

## 5. Recipe — adding a new alert type

1. **Pick the owning detector.** One detector owns one question. Reachability →
   `ScanMeterHealth`. Consumption → `ScanConsumptionAlerts`. Instantaneous
   electrical values → `ScanThresholdAlerts`. Only add a command for a genuinely
   new question with no precedence relationship to an existing one.
2. **Add the opt-in** to `meter_alert_settings` (null = off), the validation rules
   in `MeterAlertSettingsController`, and the field to the settings view.
3. **Write the check** using the matching pattern — `reconcile()` for
   open/promote/resolve, `evaluate()` when a noisy signal needs hysteresis.
4. **Choose a slug and severity.** `{domain}_{condition}`, lowercase. Severity is
   `warning` or `critical` only. Put the numbers that justified the alert into
   `context` — the digest and console render from it.
5. **Nothing else.** Delivery, coalescing, preferences, quiet hours, the bell, the
   console and its permission scoping all work already.
6. **Test the transitions, not the arithmetic**: opens once, does not re-open
   while open, resolves exactly once, and respects its opt-out. If the new alert
   overlaps an existing one, test the precedence explicitly — that is where bugs
   live.
