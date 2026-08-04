# Threat Model

**Written:** 2026-08-04, during the commercial-hardening sprint · **Scope:** the meter
platform as deployed to consumer homes, facing the public internet.

This is the security reasoning behind the Week 1 hardening work and the deliberate
decisions taken alongside it. It exists so that the *accepted* risks are accepted on
purpose and re-examined on a trigger, rather than rediscovered during an incident.

Read alongside [PROJECT_HANDBOOK.md](PROJECT_HANDBOOK.md) (how the system works),
[DEVICE_CLAIMING.md](DEVICE_CLAIMING.md) (device↔account binding) and
[PENDING_WORK.md](PENDING_WORK.md) (what is scheduled).

---

## 1. What we are protecting

| Asset | Why it matters |
|---|---|
| **Consumption data** | Reveals occupancy — when a household is empty, asleep, on holiday. Personal data about the home, not just a number. |
| **Account PII** | `users` stores `cnic` (national ID), `phone_number`, `address`, all unencrypted at rest. |
| **Device↔account binding** | Whoever owns the binding receives the data. Getting this wrong is a silent cross-tenant leak, not an error. |
| **Broker access** | Meters connect *from* customer homes *to* our broker over the public internet. |
| **Availability of ingestion** | Silent data loss is indistinguishable from a working system until a customer asks for a report. |

## 2. Who we are defending against

1. **A curious or malicious customer** — has a valid account and a real device, and can craft HTTP requests. *The primary threat: everything is multi-tenant.*
2. **An unauthenticated internet attacker** — can reach the web app and the broker port.
3. **Someone with physical access to a meter** — installer, courier, previous tenant, flatmate. Devices sit in homes we do not control.
4. **A compromised internal account** — an operator or super-admin whose credentials leak.

Explicitly **not** in scope for the pilot: a hostile cloud provider, and a determined attacker with database read access (that is a breach, handled by response rather than prevention).

---

## 3. Closed in Week 1

Each of these was a live, exploitable path in the running system.

| ID | Threat | Fix |
|---|---|---|
| **Public telemetry broadcast** | `MeterReadingUpdated` and `MeterAvailabilityUpdated` broadcast on a **public** Reverb channel carrying `device_code`, live power and monthly units for **every device on the platform**. The Reverb app key is compiled into browser JS, so any visitor could subscribe and stream every tenant's consumption — bypassing `DevicePolicy` and every `meter.*` permission. | Per-device `PrivateChannel`, authorized against `DevicePolicy::view` + `meter.access` + `meter.live_data`. `TelemetryBroadcastChannelTest`. |
| **Topic squatting** | Customers bound a meter by typing an MQTT topic. The global unique index stopped taking a *registered* topic, but nothing stopped registering one belonging to a meter **not yet installed** — capturing that customer's readings the moment it powered on. Topics were guessable (`meters/{code}/data`). | Cross-column uniqueness on both topic fields; server-derived topics land with [DEVICE_CLAIMING.md](DEVICE_CLAIMING.md). |
| **Availability-topic collision** | `availability_topic` had a plain index, not a unique one, and the web forms validated neither topic field. A user could point their device at another household's status topic and learn when that home was occupied. | Unique index + `topicRules()`. `DeviceTopicUniquenessTest`. |
| **Unthrottled heavy endpoints** | `readings/chart`, `readings` and `snapshot` — the three that scan raw readings — were the only unthrottled routes in their group. Self-DoS invitation on the public internet. | `throttle:120,1`; `ApiReadingsRouteTest` fails if a new sibling ships unthrottled. |
| **Insecure session cookies** | `SESSION_SECURE_COOKIE` was absent from both env files **and** had no config default, so it resolved to `null` and production cookies were never marked `Secure`. | Defaults from `APP_ENV`; `URL::forceScheme('https')` in production. |
| **Unverified isolation** | Three cross-tenant authorization tests called `markTestSkipped()` when their fixture was missing — which it always was. The isolation assertions had **never executed**. | Tests build their own fixture. |

---

## 4. Accepted for the pilot — each with a trigger

**These are decisions, not oversights.** Each names the condition that forces action.

### 4.1 A compromised meter can publish arbitrary readings for itself

Per-device broker credentials (planned, A6) prove *which device* is talking — not that
its data is true. [MeterPayloadValidator](../app/Services/Meters/MeterPayloadValidator.php)
checks only presence and `is_numeric`; there are no plausibility bounds at all. Anyone
with physical access to a meter can report any consumption they like for their own
device.

**Trigger — mandatory before consumption data drives money** (billing, tariffs,
settlement, or any customer-facing figure with financial consequence). The mitigation is
payload signing or, more cheaply, server-side plausibility bounds plus monotonic-energy
checks.

**Note this is bounded:** a compromised device can only lie about *itself*. Topic ACLs
(A6) prevent it publishing as another device.

### 4.2 One device can exhaust ingestion

There is no per-device rate limit. Each message drives a transaction with three rollup
writes and several `lockForUpdate()` calls, against a **single-threaded, file-locked,
single-instance** consumer. A flooding device degrades ingestion for every customer.

**Trigger — any of:** first observed flood, the fleet passing ~500 devices, or A19's
capacity baseline landing. Mitigation is item A17 (~4h).

### 4.3 Ingestion cannot reach the stated device target

One message costs roughly ten queries. At the 10,000-device goal with per-minute
sampling that is ~167 msg/s ≈ **1,670 queries/second in one PHP process** — not
reachable. The realistic ceiling is likely 1–3K devices, and it needs measuring rather
than estimating.

**Trigger — measure now (A19), act when ingest lag stops returning to zero overnight.**
`received_at - ts` per device already gives the signal, read as a trend (ESP8266 has no
RTC, so absolute values are contaminated by clock skew). The fix is architectural —
parallel consumers partitioned by device, or a queue between MQTT and persistence — not
a config change.

### 4.4 Single consumer, no failover

`acquireConsumerLock()` enforces exactly one instance. A14 plus the availability alerts
will *detect* a wedged consumer; nothing fails over. **Coupling worth knowing:** running
a second consumer is not just an infrastructure change — duplicate suppression is
currently application-level (`updateOrCreate` on `device_id` + `ts`), so a second
consumer needs a dedupe strategy first or rollups will double-count.

**Trigger — any availability commitment to a customer.**

### 4.5 `script-src` provides no XSS protection

The CSP keeps `'unsafe-inline'` (the dashboards are ~2,900-line Blade files with inline
JS) *and* `'unsafe-eval'` (Alpine, bundled in Livewire, compiles every directive
expression with `new AsyncFunction`). Removing the latter requires Alpine's CSP build,
which forbids expressions in markup entirely and touches every Blade file.

**What still protects you:** `frame-ancestors`, `object-src`, `base-uri` and
`form-action` do not depend on those escapes. And **output escaping is the primary
defence, not a backstop** — audited 2026-08-04: the three `{!! !!}` outputs are
hardcoded SVG constants from a lookup keyed by `$device->type`, and `device->name`/`code`
appear only in escaped `{{ }}` with no device field interpolated into inline JS.

**Trigger — re-audit escaping whenever user-controlled data reaches a new view or a
broadcast payload,** since there is no second line of defence.

### 4.6 No privilege-change audit trail

`PermissionController::update` and `detachBundle` sync roles and direct grants with no
record of who granted what to whom, or when. Combined with the `Gate::before`
super-admin bypass, a compromised admin account is undetectable after the fact.

**Trigger — first external user with elevated permissions, or any compliance review.**
Item A18 (~3h).

### 4.7 Email addresses are unproven

`User` does not implement `MustVerifyEmail`, so the `verified` middleware on every route
is a no-op and anyone can register with an address they do not own. Password reset does
not work either (`MAIL_MAILER=log`). Deliberately deferred — see
[PENDING_WORK.md §4a](PENDING_WORK.md).

**Trigger — before FGAC Phase 7** (both rewrite `User.php`), and before device claiming
ships, since claiming binds a device to an account identified only by that address.

### 4.8 PII unencrypted at rest

`cnic`, `phone_number` and `address` are stored in plaintext. Backups (A10) will copy
them off-server, which is why **backup encryption is mandatory rather than optional** in
that item.

**Trigger — any regulatory requirement, or the first non-pilot customer cohort.**

---

## 5. Risks introduced by the hardening work itself

Recorded because a mitigation that creates a new failure mode should not be silent.

| Risk | From | Handling |
|---|---|---|
| **Fleet-wide TLS cliff** — ESP8266 has no RTC, so certificate validation fails if NTP has not synced. A cert renewal or NTP outage drops **every meter simultaneously**. | A7 | NTP before connect with backoff; cert expiry monitored as a scheduled check; staged renewal; documented rollback for one release cycle. |
| **Signup/reset DoS** — Laravel's `throttle` keys on IP by default. Consumers behind carrier-grade NAT (common on mobile networks here) share IPs and would rate-limit each other; an attacker could lock a known email out of password reset. | A4 | Key throttles on **email+IP**, matching `LoginRequest`. |
| **Claim code is a bearer token** — printed on the unit, readable by anyone who handles it. | A5 | Single-use; owner-authorised release only; rate-limited per account *and* per IP; uniform failure responses so the endpoint is not an oracle; audit record on claim and release. |
| **Provisioning secret window** — a per-device broker secret must reach the firmware. | A6 | Generate → flash once → store only the broker-side hash. Rotation is re-provisioning, not lookup. |
| **New DB-hitting auth endpoint** — every private-channel subscription hits `/broadcasting/auth`, which runs a policy check. | A15 | Throttled in `bootstrap/app.php`. |
| **Partitioning would weaken dedupe** — MySQL requires the partition key in every unique key, so `(device_id, ts)` would become `(device_id, ts, received_at)` and stop being a backstop; the per-message dedupe SELECT would also scan every partition. | A11b | **Deferred.** The suite runs on SQLite, which has no partitioning, so a driver-guarded migration would put an untested schema shape into production. Revisit when the prune job exceeds ~2 minutes or the table passes ~50M rows. |

---

## 6. Where the boundaries are enforced

A map, so a reviewer does not have to reconstruct it:

| Boundary | Enforced by |
|---|---|
| Who may see a device | `DevicePolicy` + `Device::forUser()` query scoping. Never UI hiding. |
| Which dashboard sections render | `meter.*` permission slugs, checked in the view **and** re-checked in the matching API — the split is a security boundary, not presentation. |
| Which live data reaches a browser | `routes/channels.php`, reusing `DevicePolicy` so there is no second copy of the rule. |
| Which device a message belongs to | The MQTT topic string, exact-match. **Topic uniqueness is an identity guarantee** — see [DEVICE_CLAIMING.md](DEVICE_CLAIMING.md). |
| Who receives an alert | `EnqueueAlertForDelivery::recipientIds()` — owner always, plus `alerts.fleet_scope` holders who opted in. |
| Request volume | `throttle` on every readings endpoint and on `/broadcasting/auth`. |
| Permission drift | Planned Phase 8 guardrails: bundle snapshot test, route authorization audit, `grep hasRole` invariant. |

---

## 7. Review triggers

Re-read this document when any of these happen:

- Consumption data starts driving money → §4.1
- The fleet passes ~500 devices → §4.2, §4.3
- Any availability commitment is made to a customer → §4.4
- User-controlled data reaches a new view or broadcast payload → §4.5
- A user outside the founding team gets elevated permissions → §4.6
- Device claiming ships → §4.7, §5
- A regulatory or customer security review begins → all of §4
