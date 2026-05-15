# IoT Meter Pilot: Project Handoff

## Purpose

This document is the primary handoff reference for the current `iot-meter-pilot` codebase.

The current application is a Laravel-based pilot for ingesting MQTT telemetry from IoT electricity meters, storing the data in MySQL/SQLite, and displaying a live dashboard with historical charts and realtime updates.

This is **not yet the full IoT management platform described in the planning deck**. It is a working pilot focused on:

- MQTT telemetry ingestion
- device registry
- historical reading storage
- cached latest state per device
- single-device dashboard
- realtime browser updates via Reverb / Echo

## Current Status

### Implemented

- Laravel 12 backend
- MQTT consumer command: `php artisan mqtt:consume-meter`
- Device registry stored in `devices`
- Historical telemetry stored in `meter_readings`
- Latest state cached in `latest_meter_states`
- Single-device dashboard at `/`
- Realtime update event broadcast on the public `meters` channel
- JSON API for device readings with time windows and incremental refresh
- Basic tests around route resolution, health endpoint, and event payload shape

### Partially Implemented / Pilot-Only

- Dashboard assumes a single selected device for `/` via `METER_DEVICE_CODE`
- Device CRUD exists only as JSON API endpoints, not as management UI pages
- Only meter telemetry is modeled end-to-end
- Duplicate prevention exists, but duplicate events are not explicitly logged
- Invalid payload and missing-device detection are currently console-visible, not fully structured in Laravel logs

### Not Yet Implemented

- Alerts / notifications
- entity / floor / room mapping
- multi-device management UI
- exports / reporting UI
- authentication / authorization / RBAC
- remote control / command ACK workflow
- operational audit log UI
- backup / restore automation

## Tech Stack

- PHP `^8.2`
- Laravel `^12.0`
- Reverb `^1.0`
- Sanctum `^4.0`
- php-mqtt/laravel-client `^1.7`
- Vite `^7`
- Tailwind CSS `^4`
- Laravel Echo + Pusher JS client
- Chart.js (loaded from CDN inside the Blade view)

Primary package manifests:

- `composer.json`
- `package.json`

## Repository Structure

```text
app/
  Console/Commands/ConsumeMeterTopic.php
  Events/MeterReadingUpdated.php
  Http/Controllers/
    Api/DeviceController.php
    DeviceReadingController.php
    MeterDashboardController.php
  Models/
    Device.php
    MeterReading.php
    LatestMeterState.php

config/
  mqtt-client.php
  broadcasting.php
  reverb.php

database/
  migrations/
    create_devices_table
    create_meter_readings_table
    create_latest_meter_states_table

resources/
  js/app.js
  views/meter-dashboard.blade.php

routes/
  web.php
  api.php

tests/
  Feature/ApiReadingsRouteTest.php
  Feature/ExampleTest.php
  Unit/MeterReadingUpdatedTest.php
```

## Architecture Overview

### Data Flow

1. A physical IoT meter publishes JSON payloads to an MQTT topic.
2. `php artisan mqtt:consume-meter` connects to the broker and subscribes to all active device topics from the `devices` table.
3. Each received message is decoded and matched back to a `Device` record by `mqtt_topic`.
4. The payload is stored in `meter_readings` using `device_id + ts` as the dedupe key.
5. The current device state is updated in `latest_meter_states`.
6. `devices.last_seen_at` is updated.
7. A `MeterReadingUpdated` event is broadcast via Reverb.
8. The dashboard:
   - loads initial state from Blade + API
   - polls `/api/devices/{device}/readings`
   - listens for the realtime event and merges it into the page

### High-Level Components

- **MQTT Broker**
  Receives telemetry from physical devices.

- **Consumer Command**
  Long-running Laravel CLI process that ingests MQTT messages.

- **Database**
  Stores devices, historical readings, and latest state.

- **Dashboard**
  Single-device monitoring interface with charts and tables.

- **Realtime Layer**
  Reverb server + Echo client bridge backend events into the browser.

## Runtime Processes

To run the application fully in local development, the following processes are needed:

1. Laravel web server
2. Vite dev server
3. Reverb server
4. MQTT consumer

Typical local startup:

```bash
php artisan serve
npm run dev
php artisan reverb:start
php artisan mqtt:consume-meter
```

### Important Note About `composer run dev`

`composer run dev` starts:

- `php artisan serve`
- `php artisan queue:listen`
- `php artisan pail`
- `npm run dev`

It does **not** start:

- `php artisan reverb:start`
- `php artisan mqtt:consume-meter`

For a functioning live dashboard, those two still need to be started separately.

## Database Model

### `devices`

Represents known devices available to the platform.

Fields of note:

- `code`
- `name`
- `type`
- `mqtt_topic`
- `is_active`
- `last_seen_at`

Current relationship usage:

- `Device -> readings()` has many `MeterReading`
- `Device -> latestState()` has one `LatestMeterState`

### `meter_readings`

Stores every telemetry reading as history.

Fields of note:

- `device_id`
- `ts` (device timestamp from payload)
- `voltage`
- `current`
- `power`
- `energy_computed_wh`
- `energy_pzem_wh`
- `frequency`
- `pf`
- `raw_payload`
- Laravel `created_at` / `updated_at`

Important constraint:

- unique index on `device_id + ts`

### `latest_meter_states`

Stores only the latest known state per device to make the dashboard fast.

Fields of note:

- `device_id` (unique)
- `ts`
- same metric columns as `meter_readings`
- `received_at`

## Environment Variables Actually Used

### Core App / DB

- `APP_NAME`
- `APP_ENV`
- `APP_KEY`
- `APP_DEBUG`
- `APP_URL`
- `DB_CONNECTION`
- `DB_HOST`
- `DB_PORT`
- `DB_DATABASE`
- `DB_USERNAME`
- `DB_PASSWORD`

### MQTT

- `MQTT_HOST`
- `MQTT_PORT`
- `MQTT_CLIENT_ID`
- `MQTT_USERNAME`
- `MQTT_PASSWORD`
- `MQTT_CONNECTION_TIMEOUT`
- `MQTT_KEEP_ALIVE_INTERVAL`

### Realtime

- `BROADCAST_CONNECTION`
- `REVERB_APP_ID`
- `REVERB_APP_KEY`
- `REVERB_APP_SECRET`
- `REVERB_HOST`
- `REVERB_PORT`
- `REVERB_SCHEME`
- `REVERB_SERVER_HOST`
- `REVERB_SERVER_PORT`
- `VITE_REVERB_APP_KEY`
- `VITE_REVERB_HOST`
- `VITE_REVERB_PORT`
- `VITE_REVERB_SCHEME`

### Dashboard Selection

- `METER_DEVICE_CODE`

This is currently used by `MeterDashboardController` to decide which single device is shown on `/`.

### Important Clarification

The following variables may exist in some local `.env` files but are **not used by the current code paths**:

- `METER_TOPIC`
- `METER_DEVICE_NAME`

The MQTT consumer subscribes to topics from the `devices` table, not from `.env`.

## Key Application Flows

### 1. MQTT Ingestion

Implemented in:

- `app/Console/Commands/ConsumeMeterTopic.php`

Current behavior:

- loads all active devices
- subscribes to each trimmed `mqtt_topic`
- parses JSON payloads
- finds matching device by topic
- writes / updates reading using `updateOrCreate`
- updates latest state
- updates device `last_seen_at`
- broadcasts `MeterReadingUpdated`

Current payload assumptions:

- the payload is JSON
- fields are meter-centric
- `ts` exists or falls back to `time()`
- no formal schema validation layer yet

### 2. Dashboard Load

Implemented in:

- `app/Http/Controllers/MeterDashboardController.php`
- `resources/views/meter-dashboard.blade.php`

Current behavior:

- fetches one device by `METER_DEVICE_CODE`
- eagerly loads `latestState`
- loads recent readings
- renders a Blade dashboard

### 3. Historical Readings API

Implemented in:

- `app/Http/Controllers/DeviceReadingController.php`
- `routes/api.php`

Endpoint:

- `GET /api/devices/{device}/readings`

Features:

- time window filtering:
  - `1h`
  - `6h`
  - `24h`
  - `today`
  - `7d`
- optional `after` cursor for incremental refresh
- oldest-first response order
- full-load downsampling above 500 points

### 4. Realtime Updates

Backend:

- `app/Events/MeterReadingUpdated.php`

Frontend:

- `resources/js/app.js`
- `resources/views/meter-dashboard.blade.php`

Mechanism:

- backend broadcasts on public channel `meters`
- frontend Echo listener dispatches a browser `CustomEvent`
- dashboard view listens for `meter-reading-updated`
- view merges the reading into the in-memory dataset and updates:
  - KPI cards
  - charts
  - table
  - last seen header

## Routes

### Web

- `GET /`
  - single-device dashboard

### API

- `GET /api/devices`
- `POST /api/devices`
- `GET /api/devices/{id}`
- `GET /api/devices/{device}/readings`
- `GET /api/devices/{id}/snapshot`

### Health

- `GET /up`

## Dashboard Behavior

The dashboard is implemented in a large self-contained Blade file.

Features:

- KPI cards
- five charts
- time range switcher
- 30-second auto-refresh polling
- incremental fetch by `lastKnownId`
- realtime merge from Reverb event
- scrollable readings table

### Important Pilot Assumption

The dashboard is single-device oriented.
The main route `/` shows the device selected by `METER_DEVICE_CODE`.

### Technical Debt In The View

`resources/views/meter-dashboard.blade.php` contains:

- the active current implementation
- an older legacy implementation left inside a large Blade comment at the bottom

The commented legacy block begins after the active closing `</html>`.
It should eventually be removed to reduce confusion.

## Tests

Current tests are intentionally small and focused.

### Present

- `tests/Feature/ExampleTest.php`
  - verifies `/up` returns `200`

- `tests/Feature/ApiReadingsRouteTest.php`
  - verifies `/api/devices/{device}/readings` resolves to `DeviceReadingController@index`

- `tests/Unit/MeterReadingUpdatedTest.php`
  - verifies the broadcast payload shape for realtime updates

- `tests/Unit/ExampleTest.php`
  - placeholder unit test

### Missing Coverage

- MQTT consumer behavior
- invalid payload handling
- duplicate payload handling
- unknown-topic handling
- database integration behavior
- dashboard API query behavior with seeded records
- browser/UI behavior

## Known Limitations / Risks

### 1. Logging Noise

`config/mqtt-client.php` sets:

- `enable_logging => true`

This makes `storage/logs/laravel.log` very noisy in environments with active MQTT traffic.
It is useful for debugging but not ideal for long-running operations.

### 2. Invalid / Missing Device Cases Are Console-Visible, Not Fully Structured

The consumer currently:

- prints `Invalid JSON`
- prints `Device NOT FOUND for topic`

These cases are not yet consistently written as structured Laravel warning logs.

### 3. Duplicate Handling Exists But No Explicit Duplicate Audit

Duplicates are prevented via:

- `MeterReading::updateOrCreate(['device_id', 'ts'], ...)`

This avoids duplicate storage, but the system does not currently record that a duplicate was received.

### 4. Single-Device Root Dashboard

The root route depends on one env-selected device.
This is fine for the pilot, but it is not sufficient for the planned multi-device platform.

### 5. `resources/js/echo.js` Appears Unused

There is an additional Echo setup file:

- `resources/js/echo.js`

The actual bundle entry in use is `resources/js/app.js`.
`echo.js` appears to be legacy / redundant and should be reviewed before further frontend work.

## Operational Smoke Tests

Before handoff, the following should be checked in any environment:

1. `php artisan route:list`
2. `php artisan test`
3. dashboard loads at `/`
4. one valid MQTT message creates / updates:
   - `meter_readings`
   - `latest_meter_states`
   - `devices.last_seen_at`
5. dashboard still loads after process restart
6. a second valid MQTT message appears live without page reload

## What A New Developer Should Know First

If someone new picks this up, these are the first files to read in order:

1. `docs/PROJECT_HANDOFF.md`
2. `app/Console/Commands/ConsumeMeterTopic.php`
3. `app/Http/Controllers/MeterDashboardController.php`
4. `app/Http/Controllers/DeviceReadingController.php`
5. `app/Events/MeterReadingUpdated.php`
6. `resources/views/meter-dashboard.blade.php`
7. `resources/js/app.js`
8. database migrations under `database/migrations`

## Recommended Next Steps

These are the most sensible next improvements from the current state:

### Priority 1: Reliability

- add structured warning logs for invalid payloads / missing topics / duplicates
- reduce MQTT debug noise in long-running environments
- add ingestion-focused tests
- document expected payload schema

### Priority 2: Move Beyond Single-Device Pilot

- add UI pages for device management
- support dashboard device selection without env-only binding
- introduce entity / floor / room mapping

### Priority 3: Management Platform Features

- alerts and notifications
- reporting + exports
- online/offline tracking
- role-based access
- command / ACK workflow for controllable device types

## Suggested Handoff Summary For Management / Team Leads

The current repository is a stable pilot for:

- meter telemetry ingestion
- storage
- historical retrieval
- realtime visualization

It is a solid base for a broader IoT management platform, but it still needs product-layer work for:

- multi-device operations
- organizational mapping
- alerts
- governance
- remote control

## Last Verified State

At the time this handoff document was created:

- route mapping for readings was verified
- test suite passed
- frontend bundle built successfully
- the project was in a clean git state before documentation changes

