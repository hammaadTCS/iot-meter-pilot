# Reliability Hardening Changes

## Purpose

This document explains the reliability changes added to the IoT Meter Pilot backend. It covers:

- why each change was needed
- what was changed
- where the change was implemented
- how the change works
- what was intentionally not changed yet

The goal of this work was to improve MQTT connectivity and data safety without breaking the existing dashboard, API routes, or local development workflow.

## Summary

The project was hardened in eight main areas:

1. MQTT subscription reliability
2. MQTT reconnect behavior
3. Out-of-order telemetry protection
4. Dashboard realtime safety
5. Single-instance MQTT consumer protection
6. Ingestion audit records
7. Meter health alert records
8. Operations runbook and scheduled health scan

The following checks passed after the work:

```bash
php artisan test
npm run build
```

At verification time for the first hardening pass, the Laravel test suite passed with 35 tests. After the second pass, the Laravel test suite passed with 39 tests.

## 1. MQTT Subscription Reliability

### Why

MQTT QoS 0 means messages are delivered at most once. That is acceptable for a quick pilot, but it can lose readings during unstable Wi-Fi, broker reconnects, or brief network drops.

For an IoT meter project, losing telemetry can make charts, reports, and device health misleading. QoS 1 is a safer default because the broker/client acknowledge delivery.

### What Changed

MQTT subscription QoS is now configurable and defaults to QoS 1.

### Where

- `config/mqtt-client.php`
- `.env.example`
- `app/Console/Commands/ConsumeMeterTopic.php`

### How

The MQTT config now includes:

```php
'subscribe_qos' => min(2, max(0, (int) env('MQTT_SUBSCRIBE_QOS', 1))),
```

The consumer reads that value:

```php
$connectionConfig = (array) config('mqtt-client.connections.default', []);
$subscribeQos = (int) ($connectionConfig['subscribe_qos'] ?? 1);
```

Then it uses the configured QoS for both telemetry and availability subscriptions:

```php
$mqtt->subscribe($topic, $callback, $subscribeQos);
$mqtt->subscribe($availabilityTopic, $callback, $subscribeQos);
```

### Operational Note

QoS 1 can redeliver messages. The project already protects historical storage with the unique `device_id + ts` key, and the new latest-state guard prevents older messages from corrupting the dashboard current state.

If a broker/device setup has problems with QoS 1, set this in `.env`:

```env
MQTT_SUBSCRIBE_QOS=0
```

Then restart:

```bash
php artisan mqtt:consume-meter
```

## 2. MQTT Reconnect Backoff

### Why

The consumer already had a reconnect loop, but a fixed retry delay can cause multiple processes to reconnect in lockstep after a broker or network outage. That can add load exactly when the broker is recovering.

### What Changed

The consumer now uses bounded exponential backoff with light jitter.

### Where

- `config/mqtt-client.php`
- `.env.example`
- `app/Console/Commands/ConsumeMeterTopic.php`

### How

The config now includes:

```php
'retry' => [
    'base_delay_seconds' => max(1, (int) env('MQTT_RETRY_DELAY', 5)),
    'max_delay_seconds' => max(1, (int) env('MQTT_RETRY_MAX_DELAY', 60)),
],
```

The command calculates the next delay with:

```php
private function nextRetryDelaySeconds(
    int $attempt,
    int $baseDelaySeconds,
    int $maxDelaySeconds,
): int
```

The delay grows after repeated disconnects but is capped by `MQTT_RETRY_MAX_DELAY`.

### New Environment Variables

```env
MQTT_SUBSCRIBE_QOS=1
MQTT_RETRY_DELAY=5
MQTT_RETRY_MAX_DELAY=60
```

## 3. Structured MQTT Logging

### Why

Before this work, some important MQTT conditions were only printed to the console. That makes investigation harder after a long-running consumer has been restarted or deployed under Supervisor/systemd.

For an IoT backend, unknown topics, invalid payloads, and out-of-order readings should be visible in Laravel logs.

### What Changed

The MQTT consumer now writes structured logs for:

- telemetry topic subscription
- availability topic subscription
- unknown telemetry topics
- unknown availability topics
- payload validation issues
- stored readings that did not update latest state
- broadcast failures
- reconnect/disconnect failures

### Where

- `app/Console/Commands/ConsumeMeterTopic.php`
- `app/Services/Meters/MeterPayloadProcessor.php`

### How

Examples:

```php
Log::info('MQTT consumer subscribing to data topic', [
    'topic' => $topic,
    'device_id' => $device->id,
    'qos' => $subscribeQos,
]);
```

```php
Log::warning('MQTT payload issue recorded for device', [
    'topic' => $topic,
    'device_id' => $device?->id,
    'error_code' => $result->errorCode,
    'error_message' => $result->errorMessage,
]);
```

## 4. Out-of-Order Telemetry Protection

### Why

MQTT messages can arrive late or be redelivered. Historical storage should still keep valid delayed readings, but the dashboard's latest/current state should not move backward.

Example problem before this guard:

1. Meter sends reading with `ts = 200`.
2. Dashboard current state shows `ts = 200`.
3. A delayed packet arrives with `ts = 150`.
4. Without protection, latest state could move backward to `ts = 150`.

That would make KPI cards look stale or incorrect.

### What Changed

Historical readings are still stored, but `latest_meter_states` is only updated when the incoming payload timestamp is newer than or equal to the current latest timestamp.

### Where

- `app/Services/Meters/MeterPayloadProcessor.php`
- `app/Services/Meters/MeterProcessingResult.php`
- `tests/Feature/MeterPayloadProcessorTest.php`

### How

The processor now locks and checks the current latest state:

```php
$latestState = LatestMeterState::where('device_id', $device->id)
    ->lockForUpdate()
    ->first();
```

Then it decides whether to promote the incoming reading:

```php
protected function shouldPromoteToLatestState(?LatestMeterState $latestState, int $incomingTs): bool
{
    if (! $latestState || $latestState->ts === null) {
        return true;
    }

    return $incomingTs >= (int) $latestState->ts;
}
```

If the incoming reading is older, it is still stored in `meter_readings`, but it does not replace `latest_meter_states`.

The result object now carries this state:

```php
public bool $latestStateUpdated = false
```

## 5. Realtime Dashboard Safety

### Why

Protecting only the database latest state was not enough. A delayed packet could still be broadcast to the browser and temporarily overwrite the dashboard KPI cards in realtime.

### What Changed

Realtime meter reading events now include a flag telling the frontend whether the reading updated latest state.

### Where

- `app/Events/MeterReadingUpdated.php`
- `app/Console/Commands/ConsumeMeterTopic.php`
- `resources/views/meter-dashboard.blade.php`
- `tests/Unit/MeterReadingUpdatedTest.php`

### How

The broadcast payload now includes:

```php
'latest_state_updated' => $this->latestStateUpdated,
```

The consumer passes the processor result into the event:

```php
event(new MeterReadingUpdated($device->fresh(), $reading, $result->latestStateUpdated));
```

The dashboard uses the flag before updating KPI/current snapshot state:

```js
if (eventPayload.latest_state_updated !== false) {
    updateCurrentSnapshot(normalizeRealtimeSnapshot(eventPayload));
}
```

This means delayed readings can still appear in charts/tables when they belong in the selected range, but they cannot move the live KPI strip backward.

## 6. Dashboard Polling Safety

### Why

The dashboard polls the readings API as a fallback and for initial loads. If polling blindly used the newest returned row as the KPI state, it could bypass the backend latest-state guard.

### What Changed

The dashboard now treats `/api/devices/{device}/status` as the source of truth for current KPI state during polling.

### Where

- `resources/views/meter-dashboard.blade.php`

### How

During background refresh, readings update charts and tables, while KPI cards stay tied to `current_snapshot` returned by the status endpoint:

```js
/*
 * KPI cards are driven by /status current_snapshot instead of the newest
 * polled row. That preserves the backend's monotonic latest-state rule
 * when delayed MQTT packets arrive after a newer sample.
 */
```

## 7. Tests Added Or Updated

### Why

The reliability behavior is subtle. It needs test coverage so future changes do not accidentally reintroduce latest-state regression.

### What Changed

Tests now cover:

- MQTT config includes QoS and retry settings
- out-of-order payloads are stored as history
- out-of-order payloads do not replace latest state
- broadcast payload includes `latest_state_updated`
- broadcast payload can mark readings as historical-only
- unknown topics are audited
- duplicate readings are audited
- stale/down health alerts are opened and resolved by command

### Where

- `tests/Unit/MqttClientConfigTest.php`
- `tests/Feature/MeterPayloadProcessorTest.php`
- `tests/Unit/MeterReadingUpdatedTest.php`
- `tests/Feature/MeterHealthAlertCommandTest.php`

### Verification

```bash
php artisan test
```

Result:

```text
39 passed after the second hardening pass
```

Frontend build was also verified:

```bash
npm run build
```

## 8. Single-Instance MQTT Consumer Lock

### Why

Running more than one MQTT consumer on the same server can cause duplicate processing, noisy logs, MQTT client-id conflicts, and confusing operational behavior.

For a long-running command, a cache lock with a short TTL is risky because the lock can expire while the MQTT loop is idle. A second consumer could then start even though the first one is still alive.

### What Changed

The MQTT consumer now uses a local process-level file lock. If another local consumer is already running, the second process exits safely.

### Where

- `app/Console/Commands/ConsumeMeterTopic.php`

### How

The command opens and locks:

```text
storage/framework/mqtt-consumer.lock
```

The lock is held by keeping the file handle open for the lifetime of the process:

```php
private mixed $consumerLockHandle = null;
```

If another process already holds the lock, the command returns `FAILURE` and logs a warning.

Important limitation:

- this is a single-host lock
- if the application is deployed on multiple servers, only one server should run the MQTT consumer for a given topic group, or a distributed lock should be introduced

## 9. Ingestion Audit Records

### Why

The latest issue columns on `devices` show the current problem, but they do not provide a history of ingestion decisions. For debugging field devices, it is useful to know when invalid JSON, missing fields, duplicate packets, unknown topics, and out-of-order readings happened.

### What Changed

A new audit table records compact operational events for meter ingestion.

### Where

- `database/migrations/2026_04_27_000000_create_meter_ingestion_events_table.php`
- `app/Models/MeterIngestionEvent.php`
- `app/Services/Meters/MeterIngestionRecorder.php`
- `app/Services/Meters/MeterPayloadProcessor.php`
- `app/Console/Commands/ConsumeMeterTopic.php`
- `tests/Feature/MeterPayloadProcessorTest.php`

### How

New table:

```text
meter_ingestion_events
```

Important columns:

```text
device_id
topic
status
error_code
error_message
payload_preview
context
received_at
```

Recorded statuses include:

```text
stored
duplicate
out_of_order
invalid_json
payload_invalid
unknown_topic
availability_unknown_topic
```

Recording is intentionally non-blocking. If the audit insert fails, live ingestion continues and Laravel logs a warning.

## 10. Meter Health Alert Records

### Why

Before this change, the dashboard could show stale/down state, but the backend did not create durable alert records. That means operators could miss when a meter went stale/down and recovered.

### What Changed

A new alert table and scan command were added. The scanner opens and resolves health alerts based on existing device health logic.

### Where

- `database/migrations/2026_04_27_010000_create_meter_alert_events_table.php`
- `app/Models/MeterAlertEvent.php`
- `app/Console/Commands/ScanMeterHealth.php`
- `routes/console.php`
- `tests/Feature/MeterHealthAlertCommandTest.php`

### How

New table:

```text
meter_alert_events
```

The scanner command:

```bash
php artisan meters:scan-health
```

Scan one device:

```bash
php artisan meters:scan-health --device=1
```

It opens:

```text
telemetry_stale  severity=warning
telemetry_down   severity=critical
```

It resolves open stale/down alerts when telemetry recovers. It also resolves a stale alert when the device moves into the more severe down state, then opens a down alert.

The command is scheduled in `routes/console.php`:

```php
Schedule::command('meters:scan-health')
    ->everyMinute()
    ->withoutOverlapping();
```

To run scheduled tasks continuously:

```bash
php artisan schedule:work
```

## 11. Operations Runbook

### Why

The application depends on long-running processes. Running them manually is fine for local development, but production needs a process manager.

### What Changed

An operations runbook was added with process lists and Supervisor/systemd examples.

### Where

- `docs/OPERATIONS_RUNBOOK.md`

### How

The runbook covers:

- required local development processes
- production process list
- MQTT consumer lock behavior
- health alert scanner
- Supervisor examples
- systemd examples
- deployment checklist

## 12. What Was Intentionally Not Changed

Some recommended improvements were not implemented in this pass because they can break working behavior if done without a larger rollout plan.

### Authentication / Authorization

Not changed yet.

Why:

- current dashboard and management screens use public API calls
- adding auth immediately could cause `401`, `403`, or `419` responses
- frontend login/session handling should be planned first

Recommended next step:

- protect destructive routes first, such as device create/delete
- then move read APIs and realtime channels behind auth

### Queued Broadcasts

Not changed yet.

Why:

- queued broadcasting requires a reliable queue worker
- if the queue worker is not running, realtime updates may stop
- current polling fallback still keeps the dashboard working

Recommended next step:

- introduce queued broadcasting only after queue workers are supervised

### Rollup Tables / SQL Downsampling

Not changed yet.

Why:

- rollups require schema changes and new data-generation logic
- chart behavior can change if aggregation is not carefully designed

Recommended next step:

- add a separate chart endpoint backed by rollups
- keep the raw readings endpoint available

### Private Reverb Channels

Not changed yet.

Why:

- current frontend listens on a public `meters` channel
- private channels require auth route and frontend auth alignment

Recommended next step:

- add auth first, then migrate realtime channels to private per-device channels

## 13. How To Run After These Changes

Make sure `.env` contains:

```env
MQTT_SUBSCRIBE_QOS=1
MQTT_RETRY_DELAY=5
MQTT_RETRY_MAX_DELAY=60
```

Clear cached config:

```bash
php artisan optimize:clear
```

Run migrations after pulling these changes:

```bash
php artisan migrate
```

Run the local app:

```bash
php artisan serve
npm run dev
php artisan reverb:start
php artisan mqtt:consume-meter
```

For scheduled health alerts, also run:

```bash
php artisan schedule:work
```

Open:

```text
http://127.0.0.1:8000
```

Management page:

```text
http://127.0.0.1:8000/devices/manage
```

## 14. Remaining Risks

### QoS 1 Redelivery

QoS 1 can redeliver messages. This is expected behavior. The backend is now safer against duplicate/out-of-order effects, but logs may show repeated messages during broker reconnects.

### Device Clock Problems

The latest-state guard depends on the device-provided `ts`. If a meter clock is wrong or frozen, new real-world readings may not promote to latest state.

If that happens, inspect the raw payload `ts` and decide whether the device firmware should be fixed or the backend should use another ordering rule.

### Multiple App Servers

The MQTT consumer now has a local file lock, so duplicate consumers on the same server are blocked. If the app is deployed on multiple servers, that local lock does not coordinate across machines.

In multi-server production, run one supervised consumer per topic group or introduce a distributed lock.

## 15. Recommended Next Safe Improvements

1. Add auth to POST/DELETE device routes.
2. Move realtime channels to private per-device channels after auth exists.
3. Add notification delivery for open alert events.
4. Add rollup tables for long-range charts.
5. Add a UI for ingestion audit and alert history.
6. Move Chart.js and inline dashboard JavaScript out of the Blade file over time.
