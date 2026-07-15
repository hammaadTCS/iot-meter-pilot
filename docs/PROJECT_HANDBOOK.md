# IoT Meter Pilot — Complete Developer Handbook

> **Who this is for:** a developer who knows programming (Python, general logic, databases)
> but has **never used PHP or Laravel**. After reading this you should understand
> (1) how Laravel applications work in general, and (2) exactly where every feature
> of *this* project lives and how to change it.
>
> Everything in this document is written from the actual code in this repository —
> file paths and line references are real. Last verified: **2026-07-07**.

---

## Table of Contents

1. [What this project is](#1-what-this-project-is)
2. [PHP crash course for a Python programmer](#2-php-crash-course-for-a-python-programmer)
3. [How Laravel works (the general part)](#3-how-laravel-works-the-general-part)
4. [The tech stack of this project and why](#4-the-tech-stack-of-this-project-and-why)
5. [Directory map — what every folder is responsible for](#5-directory-map)
6. [The database — every table and what it means](#6-the-database)
7. [The five running processes](#7-the-five-running-processes)
8. [Feature deep-dives — where everything is controlled](#8-feature-deep-dives)
   - 8.1 [Authentication and roles](#81-authentication-and-roles)
   - 8.2 [Devices: CRUD, ownership, health](#82-devices)
   - 8.3 [MQTT ingestion — the heart of the system](#83-mqtt-ingestion)
   - 8.4 [Consumption accounting (daily/monthly rollups)](#84-consumption-accounting)
   - 8.5 [Dashboards and the frontend](#85-dashboards-and-the-frontend)
   - 8.6 [Real-time updates (WebSockets)](#86-real-time-updates)
   - 8.7 [The alert pipeline end-to-end](#87-the-alert-pipeline)
   - 8.8 [Scheduled background jobs](#88-scheduled-background-jobs)
   - 8.9 [The JSON API](#89-the-json-api)
9. [Configuration — .env and config/](#9-configuration)
10. [Deployment pieces](#10-deployment-pieces)
11. [Tests](#11-tests)
12. [Recipes — "I want to change X, where do I go?"](#12-recipes)
13. [Glossary](#13-glossary)

---

## 1. What this project is

A **web platform for monitoring electricity meters over the internet**.

Physical meters in the field (built around a PZEM energy-monitor chip) publish
their readings — voltage, current, power, cumulative energy, frequency, power
factor — as small JSON messages over **MQTT** (a lightweight publish/subscribe
protocol used everywhere in IoT). This application:

1. **Listens** to those MQTT messages 24/7 and stores every reading.
2. **Maintains derived data**: the latest state of each meter, and per-day /
   per-month energy-consumption totals ("units", i.e. kWh — what an electricity
   bill charges for).
3. **Shows dashboards**: live KPI cards, time-series charts, monthly/daily
   consumption reports, CSV export.
4. **Detects problems and notifies people**: meter went silent, meter reported
   itself offline, monthly budget exceeded, voltage out of range, etc. —
   delivered as in-app bell notifications and coalesced email digests.
5. **Manages users**: registration, login, three roles (`user`, `admin`,
   `super_admin`), per-user device ownership, admin fleet views.

The long-term product is a multi-device consumer IoT platform (the codebase is
deliberately structured so other device types can be added later — see the
`type` column on devices and the `device_type` column on alerts); **meters are
the first and currently only fully implemented device type**.

### The big picture in one diagram

```
                     ┌──────────────────────────────────────────────────────┐
                     │                    THIS LARAVEL APP                   │
                     │                                                      │
 physical meter ──MQTT──> [MQTT broker] ──> php artisan mqtt:consume-meter  │
 (publishes JSON)    │     (Mosquitto,        │  validates + stores          │
                     │      external)         ▼                              │
                     │                   MySQL/SQLite tables                 │
                     │                   (readings, latest state, rollups)   │
                     │                        │            │                 │
                     │        broadcasts ─────┘            │ read by         │
                     │        (Reverb WebSocket)           ▼                 │
                     │                        HTTP controllers + JSON API    │
                     │                                     │                 │
                     │   scheduler (every minute):         ▼                 │
                     │   health/threshold/budget scans   Blade HTML pages    │
                     │        │                          + Chart.js charts   │
                     │        ▼                          + polling JS        │
                     │   alert events ─> queue worker ─> email + bell        │
                     └──────────────────────────────────────────────────────┘
                                                          ▲
                                              user's browser
```

---

## 2. PHP crash course for a Python programmer

You don't need to *master* PHP to maintain this project — you need to read it
fluently. Here is the mapping from what you already know:

| Concept | Python | PHP |
|---|---|---|
| Variable | `x = 5` | `$x = 5;` (every variable starts with `$`, every statement ends with `;`) |
| String interp | `f"hi {name}"` | `"hi {$name}"` (double quotes only) or `'hi '.$name` (`.` concatenates) |
| List | `[1, 2, 3]` | `[1, 2, 3]` (called an *array*) |
| Dict | `{"a": 1}` | `['a' => 1]` (also an *array* — PHP arrays are ordered dicts) |
| Method call | `obj.method()` | `$obj->method()` (arrow, because `.` is taken by string concat) |
| Attribute | `obj.name` | `$obj->name` |
| Static/class member | `Cls.method()` | `Cls::method()` (double colon) |
| Import | `from app.models import Device` | `use App\Models\Device;` (namespaces use backslashes) |
| Define function | `def f(a, b=1):` | `function f($a, $b = 1) { ... }` |
| Anonymous fn | `lambda x: x + 1` | `fn ($x) => $x + 1` (short) or `function ($x) { return $x + 1; }` |
| Class | `class A(B):` | `class A extends B { ... }` |
| Constructor | `def __init__(self):` | `public function __construct() { ... }` |
| `self` | explicit `self` | implicit `$this` |
| None / null check | `x is None` | `$x === null` (always use `===`, `==` does loose coercion) |
| Ternary | `a if c else b` | `$c ? $a : $b`, plus `$x ?? $default` (like `x or default` but null-only) |
| f-string method | — | `?->` is null-safe access: `$device?->name` ≈ `device.name if device else None` |
| Exceptions | `try/except` | `try { } catch (Throwable $e) { }` |
| Type hints | `def f(x: int) -> str:` | `function f(int $x): string` (actually *enforced* at runtime) |

Two PHP-specific things you'll see constantly in this codebase:

- **`match` expressions** (like Python's `match` but returns a value):
  ```php
  return match ($status) {
      'disabled' => 'Disabled',
      'offline'  => 'Offline',
      default    => 'Unknown',
  };
  ```
  Real example: [Device.php:237-244](app/Models/Device.php#L237-L244).

- **Named arguments** (same as Python kwargs):
  ```php
  $this->ingestionRecorder->record(topic: $topic, status: 'unknown_topic');
  ```
  Real example: [MeterPayloadProcessor.php:31-36](app/Services/Meters/MeterPayloadProcessor.php#L31-L36).

**Execution model — the biggest mental shift from Python:** a Python web app
(Flask/Django) is usually one long-lived process. PHP's classic model is the
opposite: **every HTTP request boots the framework fresh, handles one request,
and dies.** No globals survive between requests; all persistent state lives in
the database, cache, or session. (Long-running processes *do* exist here — the
MQTT consumer and queue worker are deliberate exceptions, and you'll see the
code taking care to restart them periodically to avoid memory buildup.)

---

## 3. How Laravel works (the general part)

Laravel is PHP's equivalent of Django: batteries-included MVC web framework
with an ORM, template engine, CLI, migrations, auth scaffolding, queues, and a
task scheduler. If you know Django, almost everything maps 1:1:

| Django | Laravel | In this project |
|---|---|---|
| `manage.py` | `php artisan` | `php artisan migrate`, `php artisan mqtt:consume-meter`, … |
| `urls.py` | `routes/web.php`, `routes/api.php` | [routes/web.php](routes/web.php), [routes/api.php](routes/api.php) |
| Views (functions/classes) | Controllers | [app/Http/Controllers/](app/Http/Controllers/) |
| Models + ORM | Eloquent models | [app/Models/](app/Models/) |
| Templates (DTL/Jinja) | Blade templates | [resources/views/](resources/views/) |
| Migrations | Migrations | [database/migrations/](database/migrations/) |
| `settings.py` | `.env` + `config/*.php` | [config/](config/) |
| Signals | Events + Listeners | [app/Events/](app/Events/), [app/Listeners/](app/Listeners/) |
| Celery tasks | Queued jobs / listeners | queue worker + `ShouldQueue` |
| Celery beat | Task scheduler | [routes/console.php](routes/console.php) |
| Middleware | Middleware | [app/Http/Middleware/](app/Http/Middleware/) |
| Django admin permissions | Policies + Gates | [app/Policies/DevicePolicy.php](app/Policies/DevicePolicy.php) |

### 3.1 Life of an HTTP request

When a browser hits `/devices/5/dashboard`:

1. **Entry point** — the web server sends every request to
   [public/index.php](public/index.php). This is the *only* publicly exposed
   file; it boots the framework.
2. **Bootstrap** — [bootstrap/app.php](bootstrap/app.php) assembles the
   application: which route files to load, which middleware aliases exist,
   how exceptions are handled. In this project it registers the route files
   and two custom middleware aliases (`admin`, `superadmin`).
3. **Routing** — Laravel matches the URL against [routes/web.php](routes/web.php).
   Line 45 says:
   ```php
   Route::get('/devices/{device}/dashboard', [DeviceDashboardController::class, 'show'])
       ->name('devices.dashboard');
   ```
   Translation: *GET requests to this URL pattern call the `show` method of
   `DeviceDashboardController`.* The `{device}` segment is special — see step 5.
4. **Middleware** — before the controller runs, the request passes through
   middleware (think: decorators around the whole request). This route sits
   inside `Route::middleware(['auth', 'verified'])->group(...)` so the user must
   be logged in and email-verified, or they get redirected to the login page.
5. **Route-model binding** — because the parameter is named `{device}` and the
   controller signature is `show(Device $device)`, Laravel automatically runs
   `Device::findOrFail(5)` and hands the controller a ready-made `Device`
   object (404 if it doesn't exist). No manual lookup code.
6. **Controller** — [DeviceDashboardController.php](app/Http/Controllers/DeviceDashboardController.php)
   checks ownership (403 if it's not your device and you're not an admin),
   loads data via models, and returns a **view**.
7. **View** — `view('devices.dashboards.meter', [...])` renders the Blade
   template [resources/views/devices/dashboards/meter.blade.php](resources/views/devices/dashboards/meter.blade.php)
   into HTML with the passed variables.
8. **Response** goes back to the browser.

### 3.2 Eloquent — the ORM

Each model class maps to a table (class `MeterReading` ↔ table
`meter_readings` — Laravel pluralizes and snake_cases automatically).
Comparison with Django ORM:

```php
// Get a device's readings from the last hour, newest first:
$rows = MeterReading::where('device_id', $device->id)
    ->where('ts', '>=', now()->subHour()->timestamp)
    ->orderByDesc('ts')
    ->get();

// Insert-or-update keyed on (device_id, ts) — this one line is what makes
// duplicate MQTT deliveries harmless (idempotency):
MeterReading::updateOrCreate(
    ['device_id' => $device->id, 'ts' => $ts],   // find by these
    ['voltage' => 231.4, 'power' => 550]          // set these
);
```

**Relationships** are declared as methods on the model. From
[Device.php](app/Models/Device.php):

```php
public function readings(): HasMany       { return $this->hasMany(MeterReading::class); }
public function latestState(): HasOne     { return $this->hasOne(LatestMeterState::class); }
public function user(): BelongsTo         { return $this->belongsTo(User::class); }
```

Then `$device->readings` gives you a collection, `$device->user->name` walks
the foreign key. `Device::with('latestState')->get()` eager-loads to avoid the
N+1 query problem (you'll see this pattern in every controller here).

Two model properties you must understand before editing any model:

- **`$fillable`** — whitelist of columns allowed in mass assignment
  (`Model::create([...])`). If you add a DB column and it silently doesn't
  save, this is why. See [Device.php:16-36](app/Models/Device.php#L16-L36).
- **`$casts`** — automatic type conversion: `'last_seen_at' => 'datetime'`
  makes the column come back as a Carbon date object (PHP's arrow-rich
  datetime library), `'context' => 'array'` transparently JSON-encodes/decodes.

### 3.3 Migrations — version-controlled schema

Every schema change is a dated file in [database/migrations/](database/migrations/).
`php artisan migrate` applies the ones not yet run (tracked in a `migrations`
table). Example from this project —
[2026_06_30_000000_create_meter_daily_consumption_table.php](database/migrations/2026_06_30_000000_create_meter_daily_consumption_table.php)
creates the daily rollup table. **Never edit an already-run migration; add a
new one.** The migration filenames double as a readable history of how the
schema evolved — read them in order and you can watch the project grow.

### 3.4 Blade — the template engine

Blade is Jinja2-flavored HTML. `{{ $var }}` echoes (HTML-escaped), `@if/@endif`,
`@foreach/@endforeach` control flow, `@extends`/`@section` or components for
layout. This project uses **anonymous components**: any file in
[resources/views/components/](resources/views/components/) becomes a tag —
`<x-stats-card title="Devices" :value="$count" />` renders
[components/stats-card.blade.php](resources/views/components/stats-card.blade.php).
The page shell everyone extends is
[layouts/app.blade.php](resources/views/layouts/app.blade.php) (sidebar +
header + toast + content slot).

### 3.5 Artisan — the CLI

`php artisan <command>`. Ships with dozens (`migrate`, `tinker` — a REPL like
`python manage.py shell`, `route:list`, `queue:work`) and you can define your
own by dropping a class in [app/Console/Commands/](app/Console/Commands/).
**This project's entire ingestion and alerting machinery is custom artisan
commands** — see [§7](#7-the-five-running-processes).

### 3.6 Events, listeners, queues

- **Event** = a plain class that says "this happened" (e.g.
  [AlertOpened.php](app/Events/AlertOpened.php) carries an `AlertEvent` model).
  Fired with `event(new AlertOpened($alert))`.
- **Listener** = a class with a `handle()` method whose type-hint declares which
  event(s) it reacts to. Laravel auto-discovers this — no registration file.
  [EnqueueAlertForDelivery.php](app/Listeners/EnqueueAlertForDelivery.php)
  handles `AlertOpened|AlertResolved`. (Careful: registering it manually as
  well would make it fire twice — there's a warning comment about exactly this
  in [AppServiceProvider.php:34-37](app/Providers/AppServiceProvider.php#L34-L37).)
- **`ShouldQueue`** — if a listener/notification implements this marker
  interface, it doesn't run inline; it's serialized into the `jobs` table and
  executed later by the `php artisan queue:work` process. That's this
  project's Celery. Anything slow (sending mail, resolving alert recipients)
  is queued so it never blocks a scan or a request.

### 3.7 Config and .env

Secrets and per-environment values live in `.env` (never committed;
`.env.example` is the committed template). PHP config files in
[config/](config/) read them with `env('MQTT_HOST', '127.0.0.1')` and the rest
of the app reads config with `config('mqtt-client.connections.default')`.
**Rule: application code reads `config()`, only config files read `env()`.**

---

## 4. The tech stack of this project and why

From [composer.json](composer.json) (PHP dependencies — like
`requirements.txt`; the `vendor/` folder is its `site-packages`, never edited
by hand) and [package.json](package.json) (frontend build deps):

| Piece | What it is | Role here |
|---|---|---|
| **PHP ^8.2 / Laravel 12** | language + framework | everything |
| **laravel/breeze** | auth scaffolding (dev-dep) | generated the login/register/password-reset controllers and views under `Auth/` — they're normal app code now, committed and editable |
| **laravel/sanctum** | API token auth | protects `/api/*`; browser JS calls the API using its session cookie via `statefulApi()` ([bootstrap/app.php:16](bootstrap/app.php#L16)) |
| **spatie/laravel-permission** | roles & permissions | the hybrid FGAC system: permission slugs checked by code, roles used as admin-facing grant bundles (see §8.1) |
| **predis/predis** | pure-PHP Redis client | permission-cache reads; Redis itself runs as the `iot-redis` Docker container (`CACHE_STORE=redis`) |
| **php-mqtt/laravel-client** | MQTT client | the consumer command's connection to the broker; config in [config/mqtt-client.php](config/mqtt-client.php) |
| **laravel/reverb** | first-party WebSocket server | pushes live events to browsers (notification bell; live meter events) |
| **laravel-echo + pusher-js** (npm) | browser WebSocket client | [resources/js/echo.js](resources/js/echo.js) connects to Reverb |
| **Alpine.js** | tiny reactive JS (sprinkle-on, like a mini Vue) | dropdowns, modals, small UI state in Blade files |
| **Tailwind CSS** | utility-class CSS | most styling; built by Vite |
| **Chart.js** (CDN) | charting | all charts on the meter dashboard ([meter.blade.php:12](resources/views/devices/dashboards/meter.blade.php#L12)) |
| **Vite** | frontend bundler | compiles `resources/js` + `resources/css` → `public/build`; `npm run dev` gives hot reload |
| **livewire/livewire** | server-driven reactive components | **installed but not yet used** — a UI rebuild on Livewire is planned; today's UI is plain Blade + vanilla JS |
| **MySQL** (prod) / **SQLite** (default dev & tests) | database | `.env` `DB_CONNECTION` decides |
| **Mosquitto** (or any MQTT broker) | message broker | *external* — not part of this repo; the meters and this app both connect to it |

Philosophy (deliberate, per the project's history): **boring, battle-tested,
first-party**. No React/SPA, no Kafka, no microservices. One Laravel monolith,
a SQL database, and an MQTT broker.

---

## 5. Directory map

What every folder is responsible for — general Laravel purpose first, then
what *this project* keeps there.

```
iot-meter-pilot/
├── app/                    ← ALL application PHP code (autoloaded as App\...)
│   ├── Console/Commands/   ← custom `php artisan` commands: the MQTT consumer,
│   │                          all alert/health scanners, rollup closers, pruners
│   ├── Events/             ← 4 event classes: 2 broadcast to browsers,
│   │                          2 drive the alert pipeline
│   ├── Http/
│   │   ├── Controllers/    ← one class per screen/endpoint group
│   │   │   ├── Auth/       ← Breeze-generated login/register/reset controllers
│   │   │   └── Api/        ← JSON-only device API controller
│   │   ├── Middleware/     ← AdminMiddleware, SuperAdminMiddleware (role gates)
│   │   └── Requests/       ← form-validation classes (login, profile update)
│   ├── Listeners/          ← EnqueueAlertForDelivery (alert → recipients)
│   ├── Models/             ← 12 Eloquent models, one per table (see §6)
│   ├── Notifications/      ← AlertDigestNotification (mail + bell + websocket)
│   ├── Policies/           ← DevicePolicy: who may view/edit/delete a device
│   ├── Providers/          ← AppServiceProvider: registers the policy
│   ├── Services/Meters/    ← the domain logic: payload validation/processing,
│   │                          availability processing, range-consumption math
│   └── View/Components/    ← PHP classes for the two layout components
├── bootstrap/app.php       ← app assembly: routes, middleware aliases
├── config/                 ← one PHP file per subsystem (see §9);
│                              meter-health.php & mqtt-client.php are custom
├── database/
│   ├── migrations/         ← full schema history, 25 files
│   ├── factories/          ← fake-data blueprints for tests
│   ├── seeders/            ← DB seeding entry point
│   └── database.sqlite     ← the dev database (when using sqlite)
├── deploy/                 ← supervisor + systemd unit files for the
│                              MQTT consumer daemon (see §10)
├── docs/                   ← project documentation (this file, runbook,
│                              ERD, use-cases, pending work, FGAC plan)
├── public/                 ← THE ONLY WEB-EXPOSED FOLDER: index.php + built assets
├── resources/
│   ├── css/app.css         ← Tailwind entry point
│   ├── js/                 ← app.js (Alpine), echo.js (WebSocket client)
│   └── views/              ← all Blade templates (see §8.5)
├── routes/
│   ├── web.php             ← browser page routes (session auth)
│   ├── api.php             ← JSON API routes (/api/*, Sanctum auth)
│   ├── auth.php            ← Breeze auth routes (login, register, …)
│   ├── console.php         ← THE SCHEDULE: all recurring jobs defined here
│   └── channels.php        ← WebSocket channel authorization
├── storage/                ← logs (storage/logs/laravel.log), cache, the
│                              MQTT consumer lock file — never web-accessible
├── tests/                  ← Feature + Unit tests, run with `php artisan test`
├── vendor/                 ← composer packages (like site-packages; don't edit)
├── artisan                 ← the CLI entry script (php artisan …)
├── composer.json           ← PHP deps + the `composer dev` all-in-one runner
├── package.json            ← npm deps (vite, tailwind, alpine, echo)
├── vite.config.js          ← frontend build config
└── phpunit.xml             ← test config (uses in-memory SQLite)
```

> Root also contains a handful of loose planning notes
> (`IMPLEMENTATION_PLAN.md`, `WEEK_1_AUTH_COMPLETE.md`, a stray
> `User::whereIn(...)` file, etc.). These are historical scratch files, not
> load-bearing — the maintained documentation is in [docs/](docs/).

---

## 6. The database

Twelve domain tables (plus framework tables: `sessions`, `cache`, `jobs`,
`migrations`, `password_reset_tokens`, `personal_access_tokens`, and — since the
FGAC phases — Spatie's five: `permissions`, `roles`, `model_has_permissions`,
`model_has_roles`, `role_has_permissions`). A visual ERD
already exists at [docs/erd.md](docs/erd.md); here is the annotated version:

| Table | Model | One row means | Written by |
|---|---|---|---|
| `users` | [User](app/Models/User.php) | an account; `role` ∈ user/admin/super_admin; also cnic, phone, address | registration, user-management screens |
| `devices` | [Device](app/Models/Device.php) | one physical device: owner (`user_id`), `code`, `name`, `type` (currently `meter`), `mqtt_topic`, `availability_topic`, `is_active`, plus ~12 live-status columns (`last_seen_at`, `last_error_*`, `last_availability_*`, `last_heartbeat_at`) | device CRUD screens; status columns by the MQTT consumer |
| `meter_readings` | [MeterReading](app/Models/MeterReading.php) | one telemetry sample: `ts` (device epoch timestamp), voltage, current, power, `energy_computed_wh`, `energy_pzem_wh`, frequency, pf, `received_at`, `raw_payload` JSON. **Unique on (device_id, ts)** — the idempotency key | MQTT consumer only |
| `latest_meter_states` | [LatestMeterState](app/Models/LatestMeterState.php) | ONE row per meter: its current values + cached `monthly_units_kwh`. Exists so dashboards never scan history | MQTT consumer only |
| `meter_daily_consumption` | [MeterDailyConsumption](app/Models/MeterDailyConsumption.php) | one meter × one calendar day: baseline/last energy counter, `rollover_wh`, computed `units_kwh`, `finalized_at` | MQTT consumer (incrementally), close-day command, backfill command |
| `meter_monthly_consumption` | [MeterMonthlyConsumption](app/Models/MeterMonthlyConsumption.php) | same, one calendar month | MQTT consumer, close-month, backfill |
| `meter_ingestion_events` | [MeterIngestionEvent](app/Models/MeterIngestionEvent.php) | audit row per interesting ingestion outcome (`stored`, `duplicate`, `out_of_order`, `invalid_json`, `payload_invalid`, `unknown_topic`, …) with payload preview. Pruned after 30 days | MQTT consumer |
| `alert_events` | [AlertEvent](app/Models/AlertEvent.php) | one alert lifecycle: `alert_type` (telemetry_stale, telemetry_down, availability_offline, consumption_budget, consumption_daily, consumption_anomaly, threshold_*), `severity` (warning/critical), `status` (open/resolved), timestamps, JSON context. **Device-agnostic on purpose** (`device_type` column) so future device types reuse it | the three scan commands |
| `meter_alert_settings` | [MeterAlertSetting](app/Models/MeterAlertSetting.php) | per-meter **opt-in** alert config: monthly/daily budget kWh, warn %, anomaly toggle+multiplier, voltage high/low, max kW, min pf, offline toggle. No row = defaults (everything off except offline) | the device "Alerts" settings screen |
| `meter_threshold_states` | [MeterThresholdState](app/Models/MeterThresholdState.php) | debounce counters per meter×check so threshold alerts need N consecutive breaching scans (hysteresis, survives restarts) | ScanThresholdAlerts |
| `notification_preferences` | [NotificationPreference](app/Models/NotificationPreference.php) | per-user delivery prefs: mail/database/broadcast toggles, `min_severity`, quiet hours, `fleet_scope` (own/all) | the notification-settings screen |
| `pending_alert_notifications` | [PendingAlertNotification](app/Models/PendingAlertNotification.php) | buffered "user X should hear about alert Y" row awaiting the next digest flush | EnqueueAlertForDelivery listener |
| `notifications` | (Laravel built-in) | one delivered bell notification (JSON blob), `read_at` | AlertDigestNotification via the `database` channel |

**Key relationships:** `users 1—N devices`; `devices 1—N meter_readings`,
`1—1 latest_meter_states`, `1—N daily/monthly consumption`, `1—N alert_events`,
`1—1 meter_alert_settings`.

**The cumulative-counter idea (crucial to understand the consumption tables):**
the PZEM chip reports lifetime energy in Wh — an ever-growing counter, like a
car odometer. Consumption over any period = counter at end − counter at start.
The daily/monthly tables store the period's `baseline_energy_wh` (counter at
period start, chained from the previous period's last value) and
`last_energy_wh`, so `units_kwh = (last − baseline + rollover) / 1000`. If the
device resets and the counter drops, the pre-reset total is banked into
`rollover_wh` so consumption never goes backwards. This logic lives in
[MeterPayloadProcessor::updateMonthlyConsumption()](app/Services/Meters/MeterPayloadProcessor.php#L273)
and its daily twin right below it.

---

## 7. The five running processes

A Django dev runs one process; **this app needs five in production** (and
`composer dev` starts four of them for you locally — see
[composer.json](composer.json) `scripts.dev`):

| # | Process | Command | What dies if it's down |
|---|---|---|---|
| 1 | Web server | `php artisan serve` (dev) / nginx+php-fpm (prod) | the entire UI and API |
| 2 | **MQTT consumer** | `php artisan mqtt:consume-meter` | no new readings stored (broker buffers QoS-1 messages meanwhile; they arrive as "catch-up" on reconnect) |
| 3 | Queue worker | `php artisan queue:work` | alert recipients never resolved, emails/bell notifications never sent (rows pile up in `jobs`) |
| 4 | Scheduler | `php artisan schedule:work` (dev) / one cron line (prod) | no health/budget/threshold scans, no digests, no pruning, no day/month closing |
| 5 | Reverb WebSocket server | `php artisan reverb:start` | no live bell pushes (pages still work — the dashboard polls over HTTP anyway) |

Plus the **MQTT broker** (Mosquitto) and the database, which are external
services. Local step-by-step setup is documented in
[docs/RUNNING_LOCALLY.md](docs/RUNNING_LOCALLY.md); production process
supervision in [§10](#10-deployment-pieces).

---

## 8. Feature deep-dives

### 8.1 Authentication and roles

**Registration/login/password-reset** — standard Laravel Breeze. Routes in
[routes/auth.php](routes/auth.php), controllers in
[app/Http/Controllers/Auth/](app/Http/Controllers/Auth/), Blade forms in
[resources/views/auth/](resources/views/auth/). Passwords are bcrypt-hashed
automatically by the `'password' => 'hashed'` cast on
[User.php:52](app/Models/User.php#L52). Sessions are cookie-based (config in
[config/session.php](config/session.php)).

**Roles** — a plain `role` enum column on `users`
(migration [2026_05_08_122359_add_role_to_users_table.php](database/migrations/2026_05_08_122359_add_role_to_users_table.php),
extended by [...add_super_admin_to_role_enum.php](database/migrations/2026_05_11_143710_add_super_admin_to_role_enum.php)).
Three helper methods on [User.php:74-93](app/Models/User.php#L74-L93):
`isSuperAdmin()`, `isAdminOrAbove()`, `isAdmin()` (alias of the previous).

**Where access is enforced — three layers (permission slugs since the
2026-07-13 FGAC cutover):**

1. **Route middleware** — `permission:<slug>` middleware in
   [routes/web.php](routes/web.php) (the `/users/*` area: `users.view_list`,
   `users.create`, `users.edit`, `users.view_profile`,
   `users.manage_permissions`) and [routes/api.php](routes/api.php)
   (`api.devices.read/write`, `api.readings.read`). Deleting users remains
   hard-locked to `super_admin`.
2. **Policy** — [DevicePolicy](app/Policies/DevicePolicy.php): pure
   permission + ownership predicates (e.g. `update` = `devices.edit_any` ∨
   (`devices.edit_own` ∧ owner) ∨ name-only via `meter.rename`). Controllers
   invoke it with `$this->authorize('view', $device)`; meter dashboards and
   readings APIs add their `meter.*` section checks on top.
3. **Query scoping** — lists never *fetch* what you shouldn't see:
   `Device::forUser($user)` ([Device.php:61-64](app/Models/Device.php#L61-L64))
   and `AlertEvent::visibleTo($user)` ([AlertEvent.php scopeVisibleTo](app/Models/AlertEvent.php))
   filter by ownership unless the user holds `devices.view_any` /
   `alerts.view_any`.

> **✅ Hybrid FGAC is LIVE (phases R,0–6 done; cutover 2026-07-13; simplified
> consumer dashboard + `meter.full_dashboard` added 2026-07-14):** permission
> slugs are the sole enforcement — `spatie/laravel-permission` with a 30-slug
> catalog ([PermissionSeeder](database/seeders/PermissionSeeder.php), the single
> source of truth) and 5 **grant bundles** (consumer / prosumer /
> field_engineer / fleet_operator / super_admin). Per-user access is managed at
> `/users/{user}/permissions` ([PermissionController](app/Http/Controllers/PermissionController.php));
> self-registration assigns `consumer` (`AUTH_ALLOW_REGISTRATION`). A
> `Gate::before` bypass in [AppServiceProvider](app/Providers/AppServiceProvider.php)
> makes `super_admin` pass every check. The legacy `role` column and
> admin middlewares still exist but are dead weight until Phase 7 removes them.
> **Admin guide (every slug, every bundle, recipes):
> [docs/PERMISSIONS_HANDBOOK.md](docs/PERMISSIONS_HANDBOOK.md)** · engineering
> plan + status ledger: [docs/FGAC_IMPLEMENTATION_PLAN.md](docs/FGAC_IMPLEMENTATION_PLAN.md)
> · raw matrix: [docs/FGAC_FEATURES_PERMISSIONS.csv](docs/FGAC_FEATURES_PERMISSIONS.csv).

**User management screens** — [UserManagementController](app/Http/Controllers/UserManagementController.php)
+ views in [resources/views/users/](resources/views/users/). **Profile** —
[ProfileController](app/Http/Controllers/ProfileController.php) + validation
rules in [app/Http/Requests/ProfileUpdateRequest.php](app/Http/Requests/ProfileUpdateRequest.php).

### 8.2 Devices

**CRUD screens** — [DeviceManagementController](app/Http/Controllers/DeviceManagementController.php)
(routes at [web.php:39-47](routes/web.php#L39-L47)); views
[devices-manage.blade.php](resources/views/devices-manage.blade.php),
[devices-create.blade.php](resources/views/devices-create.blade.php),
[devices-edit.blade.php](resources/views/devices-edit.blade.php). A device is:
owner, code, name, type, MQTT data topic, optional availability topic, active
flag. If no availability topic is given, one is derived from the data topic
(`.../data` → `.../status`) by
[Device::deriveAvailabilityTopic()](app/Models/Device.php#L145-L166).

**Device status logic lives on the model, not in controllers.** Three
independent classifications, each with a `*Snapshot()` method that packages
status/label/message for the UI:

| Question | Method | States | Driven by |
|---|---|---|---|
| Is telemetry fresh? | [healthStatus()](app/Models/Device.php#L311-L333) | online / stale / down / never_seen / disabled | `last_seen_at` vs thresholds in [config/meter-health.php](config/meter-health.php) (stale ≥ 180 s, down ≥ 600 s, env-overridable) |
| What does MQTT availability say? | [availabilityStatus()](app/Models/Device.php#L208-L230) | online / offline / silent / unknown / disabled | `last_availability_*` columns; "silent" = broker says online but no telemetry; an explicit *offline* is authoritative until newer telemetry/heartbeat supersedes it |
| Is the latest payload broken? | [issueStatus()](app/Models/Device.php#L442-L457) | ok / error / recovered / disabled | `last_error_*` vs `last_recovered_at` |

If you need to change what "stale" or "down" means, that's **one config file /
env var**, not code.

### 8.3 MQTT ingestion

**The single most important flow in the system.** A long-running artisan
command subscribes to every active device's topics and processes messages
through service classes. Follow one message end-to-end:

**The consumer** — [ConsumeMeterTopic.php](app/Console/Commands/ConsumeMeterTopic.php)
(`php artisan mqtt:consume-meter`):

1. Takes an **exclusive file lock** (`storage/framework/mqtt-consumer.lock`,
   [line 280](app/Console/Commands/ConsumeMeterTopic.php#L280)) so two
   consumers can never run at once (double-processing guard).
2. Installs **SIGTERM/SIGINT handlers** ([line 255](app/Console/Commands/ConsumeMeterTopic.php#L255))
   so a deploy/restart finishes the current message before exiting.
3. Connects to the broker (settings from [config/mqtt-client.php](config/mqtt-client.php):
   QoS 1, persistent session so the broker queues messages while we're down),
   loads all `is_active` devices, subscribes to each one's **data topic** and
   **availability topic**.
4. On every data message → calls `MeterPayloadProcessor->process($topic, $message)`.
5. If stored **and recent** (< 120 s old, [line 122](app/Console/Commands/ConsumeMeterTopic.php#L122)),
   fires the `MeterReadingUpdated` broadcast event. Older messages are
   "catch-up" after a reconnect — stored silently so a backlog doesn't flood
   the WebSocket server.
6. On broker disconnect: reconnects with **exponential backoff + jitter**
   ([line 273](app/Console/Commands/ConsumeMeterTopic.php#L273)). After 50 000
   messages it exits cleanly on purpose so the process supervisor restarts it
   with a fresh PHP heap (`--restart-after` option).

**The processor** — [MeterPayloadProcessor.php](app/Services/Meters/MeterPayloadProcessor.php),
the brain. For each message:

1. **Find the device** by exact topic match. Unknown topic → audit row, ignore.
2. **Parse JSON.** Invalid → mark the device's `last_error_*` columns
   (dashboard shows "Payload Error"), audit row, done.
3. **Validate** via [MeterPayloadValidator](app/Services/Meters/MeterPayloadValidator.php):
   requires a positive numeric `ts`, accepts any subset of the seven
   measurement fields (voltage, current, power, energy_computed_wh,
   energy_pzem_wh, frequency, pf) but at least one.
4. **In one DB transaction** ([line 106](app/Services/Meters/MeterPayloadProcessor.php#L106)):
   - `updateOrCreate` the reading keyed on `(device_id, ts)` → **duplicates
     are harmless** (QoS-1 redelivery just overwrites the same row).
   - Update the device's `last_seen_at` / `last_message_at`; if it had an
     active payload issue, stamp `last_recovered_at` (drives the "Recovered"
     badge).
   - Decide **promotion**: history accepts out-of-order messages (normal in
     the field), but the cached `latest_meter_states` row — which drives the
     KPI cards — only moves **forward** by device timestamp
     ([shouldPromoteToLatestState()](app/Services/Meters/MeterPayloadProcessor.php#L244)).
   - If promoted and the payload carries a PZEM energy value: **fold it into
     the monthly and daily consumption rollups** (baseline chaining +
     reset/rollover handling, explained in [§6](#6-the-database)), then write
     the latest state including the freshly computed `monthly_units_kwh`.
   - Write an **audit row** (`stored` / `duplicate` / `out_of_order`).

**The availability path** — status messages (`online` / `offline` / heartbeat,
JSON or plain text) go through
[MeterAvailabilityProcessor](app/Services/Meters/MeterAvailabilityProcessor.php),
which updates the device's `last_availability_*` / `last_heartbeat_at` columns
and fires `MeterAvailabilityUpdated` for live dashboards.

**Expected payload shape** (what a meter publishes):

```json
{ "ts": 1751871000, "voltage": 231.4, "current": 2.4, "power": 552.1,
  "energy_pzem_wh": 1284650, "frequency": 50.0, "pf": 0.98 }
```

**Ingestion guarantees, summarized:** idempotent (unique device+ts), ordered
where it matters (forward-only latest state), atomic (one transaction keeps
reading + device + rollups + latest state consistent), audited
(`meter_ingestion_events`), self-healing (backoff reconnect, QoS-1 persistent
session, single-instance lock, scheduled self-restart).

### 8.4 Consumption accounting

Three cooperating layers answer "how many units did this meter use?":

1. **Incremental rollups at ingest time** (§8.3 step 4) keep the current
   day/month row live, so the dashboard's "Monthly Units" KPI is a cached
   column read — never a scan.
2. **[RangeConsumption](app/Services/Meters/RangeConsumption.php)** — the
   *single source of truth* for "units over an arbitrary window [start, end]".
   Every consumer (Range-Units KPI, CSV/JSON export, reports) calls this one
   class so figures can never disagree. Internally it's tiered for scale:
   whole interior days come from the daily rollup table, only the two partial
   edge days touch raw readings — O(days) instead of O(readings). The header
   comment in the file (lines 10-45) explains the telescoping math; read it
   before touching anything here.
3. **Safety-net closers** — a device can go silent forever, leaving its last
   day/month row unfinalized. [CloseMeterDay](app/Console/Commands/CloseMeterDay.php)
   (daily 00:10) and [CloseMeterMonth](app/Console/Commands/CloseMeterMonth.php)
   (daily 00:15) stamp `finalized_at` on ended periods.
   [BackfillDailyConsumption](app/Console/Commands/BackfillDailyConsumption.php) /
   [BackfillMonthlyConsumption](app/Console/Commands/BackfillMonthlyConsumption.php)
   rebuild rollups from raw history (used when the feature shipped, and
   available for repair).

### 8.5 Dashboards and the frontend

There is **no SPA framework**. Pages are server-rendered Blade; interactivity
is vanilla `fetch()` polling + Chart.js + Alpine sprinkles. (Livewire is
installed and a rebuild is planned, but nothing runs on it yet.)

| Screen | Route | Controller | View |
|---|---|---|---|
| Main dashboard | `/dashboard` | [DashboardController](app/Http/Controllers/DashboardController.php) | [dashboard.blade.php](resources/views/dashboard.blade.php) — stat cards + recent device cards ([components/device-card.blade.php](resources/views/components/device-card.blade.php)); admins also get system-wide stats |
| Device list / create / edit | `/devices…` | [DeviceManagementController](app/Http/Controllers/DeviceManagementController.php) | `devices-manage`, `devices-create`, `devices-edit` |
| **Meter dashboard** | `/devices/{id}/dashboard` | [DeviceDashboardController](app/Http/Controllers/DeviceDashboardController.php) — dispatches on `device->type`; non-meters get [placeholder.blade.php](resources/views/devices/dashboards/placeholder.blade.php) (the seam where future device types plug in) | [devices/dashboards/meter.blade.php](resources/views/devices/dashboards/meter.blade.php) |
| Alerts console | `/alerts` | [AlertController](app/Http/Controllers/AlertController.php) | [alerts/index.blade.php](resources/views/alerts/index.blade.php) |
| Alert settings per meter | `/devices/{id}/alerts` | [MeterAlertSettingsController](app/Http/Controllers/MeterAlertSettingsController.php) | [devices/alerts.blade.php](resources/views/devices/alerts.blade.php) |
| Notification prefs | `/settings/notifications` | [NotificationPreferenceController](app/Http/Controllers/NotificationPreferenceController.php) | [settings/notifications.blade.php](resources/views/settings/notifications.blade.php) |
| Users, profile, auth | see §8.1 | | |

**The meter dashboard deserves special mention** — it is one large
self-contained Blade file (~2 870 lines:
[meter.blade.php](resources/views/devices/dashboards/meter.blade.php)) holding
the HTML, CSS and JS for: KPI cards (voltage / current / power / monthly
units), a time-range selector (1h…30d/all) driving dual-axis Chart.js charts, a
monthly consumption bar chart, a daily-breakdown report with month picker, a
paginated readings table, CSV export, and status banners. Its JavaScript
polls the JSON API (§8.9) — e.g. the status endpoint every ~30 s and
chart/table endpoints on range change (`fetch(...)` calls around
[line 1854 and 2431-2534](resources/views/devices/dashboards/meter.blade.php#L1854)).
When you change dashboard behavior, you are almost always editing this one file.

**Shared layout** — [layouts/app.blade.php](resources/views/layouts/app.blade.php)
wraps every authenticated page: [components/sidebar.blade.php](resources/views/components/sidebar.blade.php)
(navigation, role-aware), [components/header.blade.php](resources/views/components/header.blade.php)
(user menu + **notification bell**), [components/toast.blade.php](resources/views/components/toast.blade.php)
(flash messages). Design language: dark theme with cyan/purple accents,
Tailwind utilities.

### 8.6 Real-time updates

Laravel **broadcasting** = server-side events pushed to browser WebSockets.
The chain:

1. **Server event** implements `ShouldBroadcastNow` — see
   [MeterReadingUpdated.php](app/Events/MeterReadingUpdated.php): declares its
   channel (`meters`, public), event name (`meter.reading.updated`), and exact
   payload (`broadcastWith()`).
2. **Reverb** (`php artisan reverb:start`, config
   [config/reverb.php](config/reverb.php)) is the WebSocket server that fans
   the event out. `.env` sets `BROADCAST_CONNECTION=reverb`.
3. **Browser** — [resources/js/echo.js](resources/js/echo.js) creates
   `window.Echo` connected to Reverb.
4. **Channel authorization** for private channels lives in
   [routes/channels.php](routes/channels.php): the only rule says a user may
   listen to `App.Models.User.{id}` only if it's their own id.

Two live surfaces today:

- **The notification bell** — the real-time path in production use. When an
  `AlertDigestNotification` is delivered on the `broadcast` channel, Laravel
  pushes it to the user's private channel; the header script
  ([header.blade.php:192-200](resources/views/components/header.blade.php#L192))
  listens with `window.Echo.private('App.Models.User.{{ auth()->id() }}').notification(...)`
  and prepends the item, unread badge and all, without a page reload.
- **Meter events** (`MeterReadingUpdated`, `MeterAvailabilityUpdated` on the
  public `meters` channel) are broadcast by the consumer; the meter dashboard
  currently relies on HTTP polling as its primary refresh mechanism, so these
  events are the hook for a future push-driven UI (and why catch-up readings
  deliberately skip broadcasting).

### 8.7 The alert pipeline

The most architecturally interesting subsystem. Design goals: detectors never
slow ingestion (all scheduled, all read derived tables), alerts are
**stateful** (open once → resolve once, no repeat spam), delivery is
**coalesced** (a 50-meter outage = ONE email per user, not 50), and recipients/
channels are user-configurable.

```
DETECT (scheduled commands)          DECIDE WHO           COALESCE            DELIVER
─────────────────────────           ───────────          ─────────           ────────
ScanMeterHealth      (1 min) ──┐
ScanThresholdAlerts  (1 min) ──┼─ AlertOpened/ ──> EnqueueAlertFor ──> pending_alert_ ──> DispatchAlertDigests (1 min)
ScanConsumptionAlerts (1 hr) ──┘   AlertResolved      Delivery            notifications        │ one AlertDigestNotification
                                   (events)           (queued listener)   (buffer rows)        ▼ per user
                                                                                   channels per NotificationPreference:
                                                                                   mail (severity floor + quiet hours)
                                                                                   database + broadcast (the bell)
```

**Stage 1 — Detectors** (all in [app/Console/Commands/](app/Console/Commands/)):

- [ScanMeterHealth](app/Console/Commands/ScanMeterHealth.php) — telemetry
  freshness + availability: opens `telemetry_stale` (warning),
  `telemetry_down` (critical), `availability_offline`; auto-resolves when data
  returns. Respects the per-meter `offline_enabled` opt-out. Chunks devices
  100 at a time so it scales.
- [ScanThresholdAlerts](app/Console/Commands/ScanThresholdAlerts.php) —
  electrical limits (over/under-voltage, max power, min power-factor) against
  the **latest cached state** only. Uses **hysteresis**: a check must breach
  for 3 consecutive minutes to open and be clear for 3 to resolve (counters
  persisted in `meter_threshold_states`), so a transient spike never pages
  anyone. Readings older than 10 min are not judged (that's the health
  detector's territory). All critical severity.
- [ScanConsumptionAlerts](app/Console/Commands/ScanConsumptionAlerts.php) —
  budgets and anomaly, read from the **rollup tables** (never raw scans):
  monthly budget (warning at the configured %, default 80; critical at 100 %),
  daily budget (warning), anomaly (today ≥ multiplier × the trailing 7-day
  average, requiring at least 3 days of history).
  Self-resolving — a new day/month resets usage.

All three are **opt-in per meter** via `meter_alert_settings` (§8.2's Alerts
screen); a meter with no settings row only gets offline alerts.

**Stage 2 — Fan-out** — detectors fire plain events
([AlertOpened](app/Events/AlertOpened.php) / [AlertResolved](app/Events/AlertResolved.php))
*after* their transaction commits. The queued listener
[EnqueueAlertForDelivery](app/Listeners/EnqueueAlertForDelivery.php) resolves
recipients — the device **owner** always, plus **fleet operators** (users whose
`notification_preferences.fleet_scope = 'all'`) if the alert meets their
severity floor — and writes one buffer row per recipient. It sends nothing
itself.

**Stage 3 — Digest** — [DispatchAlertDigests](app/Console/Commands/DispatchAlertDigests.php)
runs every minute: groups undispatched buffer rows per user and sends **one**
[AlertDigestNotification](app/Notifications/AlertDigestNotification.php)
covering all of them (email body capped at 20 lines for mass events).

**Stage 4 — Channels** — the notification's `via()` asks
[NotificationPreference::channelsFor()](app/Models/NotificationPreference.php):
`database` + `broadcast` (the bell) are on by default; `mail` respects the
user's `min_severity` and **quiet hours**. Mail transport config:
[config/mail.php](config/mail.php) (`MAIL_MAILER=log` in dev — emails are just
written to the log file).

**Reading alerts** — bell dropdown in the header ("mark all read" posts to
[NotificationController](app/Http/Controllers/NotificationController.php));
full console at `/alerts` ([AlertController](app/Http/Controllers/AlertController.php))
with filters, scoped by `AlertEvent::visibleTo()`.

**Retention** — [PruneAlertsAndNotifications](app/Console/Commands/PruneAlertsAndNotifications.php)
(daily 02:30) deletes old resolved alerts, read notifications, and dispatched
buffer rows.

### 8.8 Scheduled background jobs

**Everything recurring is declared in one file:**
[routes/console.php](routes/console.php). The scheduler process (§7 #4) wakes
every minute and runs what's due. `withoutOverlapping()` = skip this run if
the previous one is still going.

| When | Command | Purpose (detail in) |
|---|---|---|
| every minute | `meters:scan-health` | health/availability alerts (§8.7) |
| every minute | `alerts:scan-thresholds` | electrical threshold alerts (§8.7) |
| every minute | `alerts:dispatch-digests` | flush the notification buffer (§8.7) |
| hourly | `alerts:scan-consumption` | budget/anomaly alerts (§8.7) |
| daily | `meters:prune-ingestion-events --days=30` | audit-table retention |
| daily 00:10 | `meters:close-day` | finalize ended daily rollups (§8.4) |
| daily 00:15 | `meters:close-month` | finalize ended monthly rollups (§8.4) |
| daily 02:30 | `alerts:prune` | alert/notification retention |

Any command can also be run by hand for debugging:
`php artisan meters:scan-health --device=5`.

### 8.9 The JSON API

Defined in [routes/api.php](routes/api.php) (auto-prefixed `/api`, no CSRF,
Sanctum auth — the file's header comment explains why this split from web.php
exists). Consumed today by the dashboards' JavaScript; usable by a future
mobile app unchanged.

| Endpoint | Handler | Returns |
|---|---|---|
| `GET /api/health` | closure | `{"status":"ok"}` — public liveness probe (Laravel also serves `/up`) |
| `GET/POST/DELETE /api/devices…` | [Api/DeviceController](app/Http/Controllers/Api/DeviceController.php) | device CRUD (ownership-scoped, policy-checked) |
| `GET /api/devices/{id}/status` | same | health + availability + issue snapshots — **the dashboard's 30 s poll** |
| `GET /api/devices/{id}/snapshot` | same | latest-state KPI values |
| `GET /api/devices/{id}/readings/chart?range=24h` | [DeviceReadingController](app/Http/Controllers/DeviceReadingController.php) | chart series, **evenly downsampled to ≤ 500 points** so Chart.js stays fast on any range |
| `GET /api/devices/{id}/readings?range=…&page=…` | same | paginated table rows (100/page) |
| `GET /api/devices/{id}/readings/consumption?range=…` | same (throttled 120/min) | units for the selected window via RangeConsumption |
| `GET /api/devices/{id}/consumption/daily?month=YYYY-MM` | same (throttled 60/min) | per-day breakdown + month total from the rollups; append `&format=csv` (or `json`) for the file download the dashboard's Export button uses ([DeviceReadingController.php:345-404](app/Http/Controllers/DeviceReadingController.php#L345-L404)) |

Valid ranges: `1h, 6h, 24h, today, 7d, 30d, all`
([DeviceReadingController.php:27](app/Http/Controllers/DeviceReadingController.php#L27)).
Note the routing subtlety documented at [api.php:52-54](routes/api.php#L52-L54):
literal sub-routes (`/readings/chart`) must be registered before parameterized
ones.

---

## 9. Configuration

**`.env`** (copy of [.env.example](.env.example)) — the values you'll actually
touch:

| Key(s) | Controls |
|---|---|
| `APP_URL`, `APP_DEBUG`, `APP_KEY` | base URL; debug pages (**must be false in prod**); encryption key (`php artisan key:generate`) |
| `DB_CONNECTION`, `DB_DATABASE`, … | sqlite (default dev) or mysql + credentials |
| `MQTT_HOST/PORT/USERNAME/PASSWORD` | broker connection |
| `MQTT_SUBSCRIBE_QOS` (1), `MQTT_CLEAN_SESSION` (false) | delivery guarantees — QoS 1 + persistent session is why messages survive consumer downtime |
| `MQTT_RETRY_DELAY` / `MQTT_RETRY_MAX_DELAY` | consumer reconnect backoff bounds |
| `METER_HEALTH_STALE_AFTER_SECONDS` (180) / `METER_HEALTH_DOWN_AFTER_SECONDS` (600) | when a silent meter counts as stale/down — feeds both UI badges and alerts |
| `QUEUE_CONNECTION=database` | queued jobs stored in the `jobs` table |
| `CACHE_STORE=redis`, `REDIS_CLIENT=predis`, `REDIS_HOST/PORT` | permission cache (Docker container `iot-redis`); `SESSION_DRIVER=database` |
| `AUTH_ALLOW_REGISTRATION` (true) | self-serve signup (new accounts get the `consumer` bundle) vs invite-only 404 |
| `BROADCAST_CONNECTION=reverb`, `REVERB_*`, `VITE_REVERB_*` | WebSockets (the `VITE_` copies are compiled into browser JS) |
| `MAIL_*` | email transport (`log` in dev) |

**[config/](config/)** — mostly stock Laravel reading those env vars. The two
custom files are [meter-health.php](config/meter-health.php) (thresholds) and
[mqtt-client.php](config/mqtt-client.php) (connection, QoS, timeouts,
auto-reconnect, backoff — every option commented in-file). After changing
config in production run `php artisan config:cache`, and remember **the MQTT
consumer and queue worker only read config at startup — restart them**.

---

## 10. Deployment pieces

The [deploy/](deploy/) folder holds process supervision for the MQTT consumer —
pick **one** mechanism:

- [deploy/supervisor/iot-mqtt-consumer.conf](deploy/supervisor/iot-mqtt-consumer.conf) — supervisord program entry
- [deploy/systemd/iot-mqtt-consumer.service](deploy/systemd/iot-mqtt-consumer.service) — systemd unit

Both auto-restart the consumer when it exits (including its intentional
every-50 000-messages self-restart). Production additionally needs a queue
worker under the same supervision, one cron line for the scheduler
(`* * * * * php /path/artisan schedule:run`), Reverb, nginx+php-fpm, MySQL and
Mosquitto. The step-by-step operational procedures — deploys, restarts, log
locations, health checks, common incidents — are in
[docs/OPERATIONS_RUNBOOK.md](docs/OPERATIONS_RUNBOOK.md), and the original
production rollout checklist in
[docs/DEPLOY_PARTS_1_2_CHECKLIST.md](docs/DEPLOY_PARTS_1_2_CHECKLIST.md).

Logging goes to `storage/logs/laravel.log` (config:
[config/logging.php](config/logging.php)); locally `php artisan pail` gives a
live tail (started automatically by `composer dev`).

---

## 11. Tests

Run all: `php artisan test` (or `composer test`). Config in
[phpunit.xml](phpunit.xml) — tests run against **in-memory SQLite**, so they
never touch your real data and need no setup.

- [tests/Feature/](tests/Feature/) — ~29 files exercising whole flows through
  HTTP or command invocation. The names are a map of the system's guarantees:
  `MeterPayloadProcessorTest` (idempotency, out-of-order, rollups),
  `RangeConsumptionTest` (the tiered window math),
  `AlertDeliveryTest`, `ThresholdAlertsTest`, `ConsumptionAlertsTest`,
  `NotificationPreferencesTest`, `MeterHealthAlertCommandTest`,
  `DeviceManagementApiTest`, `CloseMeterDayTest`, …
- [tests/Unit/](tests/Unit/) — isolated logic: device health/availability
  classification, broadcast payloads, MQTT config.
- [database/factories/](database/factories/) — fake-model builders used by
  tests (`Device::factory()->create()`).

**House rule visible throughout the git history: every behavioral change ships
with tests.** When you fix a bug here, add a test that would have caught it.

---

## 12. Recipes

Quick lookup table — *"I want to… → go to…"*:

| Task | Where |
|---|---|
| Add a page | route in [routes/web.php](routes/web.php) → method in a controller → Blade view in [resources/views/](resources/views/); add to [components/sidebar.blade.php](resources/views/components/sidebar.blade.php) |
| Add an API endpoint | [routes/api.php](routes/api.php) → controller method returning `response()->json(...)` |
| Add a DB column | `php artisan make:migration add_x_to_y_table` → edit the new file → `php artisan migrate` → add to the model's `$fillable`/`$casts` |
| Change stale/down timing | `.env`: `METER_HEALTH_STALE_AFTER_SECONDS` / `METER_HEALTH_DOWN_AFTER_SECONDS` |
| Accept a new telemetry field | migration on `meter_readings` + `latest_meter_states` → `MEASUREMENT_FIELDS` in [MeterPayloadValidator](app/Services/Meters/MeterPayloadValidator.php) → `$fillable` on both models → display in [meter.blade.php](resources/views/devices/dashboards/meter.blade.php) |
| Add a new alert type | new (or existing) scan command emitting `AlertOpened`/`AlertResolved` with a new `alert_type` string → schedule it in [routes/console.php](routes/console.php) → delivery works automatically; optionally add opt-in fields to `meter_alert_settings` + the settings screen |
| Change email wording | [AlertDigestNotification::toMail()](app/Notifications/AlertDigestNotification.php) |
| Change alert recipients logic | [EnqueueAlertForDelivery::recipientIds()](app/Listeners/EnqueueAlertForDelivery.php) |
| Change who can do what | [DevicePolicy](app/Policies/DevicePolicy.php), the two middleware, `AlertEvent::visibleTo()`, `Device::forUser()` (until FGAC replaces these) |
| Change a chart / KPI / the table | [meter.blade.php](resources/views/devices/dashboards/meter.blade.php) (frontend) and/or [DeviceReadingController](app/Http/Controllers/DeviceReadingController.php) (data) |
| Change consumption math | **stop** — read [RangeConsumption](app/Services/Meters/RangeConsumption.php)'s header comment and [MeterPayloadProcessor](app/Services/Meters/MeterPayloadProcessor.php) first; the rollups, the range service and the tests must stay in agreement |
| Add a second device type | insert with `type='sensor'` etc.; ingestion needs its own processor wired into the consumer; dashboard plugs into the `match` in [DeviceDashboardController::show()](app/Http/Controllers/DeviceDashboardController.php); alerts already device-agnostic |
| Debug "no data arriving" | is the consumer running? (`ps aux \| grep consume-meter`) → `storage/logs/laravel.log` → the meter's ingestion audit trail in `meter_ingestion_events` → topic string matches exactly? |
| Debug "no emails" | is `queue:work` running? rows stuck in `jobs`/`failed_jobs`? user's `notification_preferences` (severity floor, quiet hours)? `MAIL_MAILER=log` writes to the log instead of sending |
| Poke at data interactively | `php artisan tinker` → `Device::with('latestState')->get()` |
| See every registered route | `php artisan route:list` |

---

## 13. Glossary

| Term | Meaning |
|---|---|
| **MQTT** | lightweight pub/sub messaging protocol for IoT; clients publish to string *topics* (e.g. `meters/site1/kitchen/data`), subscribers receive matching messages via a central **broker** |
| **QoS 1** | MQTT delivery guarantee "at least once" — may redeliver, which is why ingestion is idempotent |
| **PZEM** | the energy-monitoring sensor module family in the meters; its cumulative Wh counter is the source of all consumption math |
| **kWh / unit** | kilowatt-hour, the billing unit of energy; this codebase says `units_kwh` |
| **Rollup** | pre-computed aggregate row (daily/monthly consumption) maintained so queries don't scan raw history |
| **Rollover** | banking the counter value when the hardware counter resets, so consumption never decreases |
| **Idempotent** | safe to apply twice with the same end result (readings keyed on device+ts) |
| **Hysteresis / debounce** | requiring N consecutive observations before changing state, to ignore transients (threshold alerts) |
| **Coalescing / digest** | merging many pending notifications into one message per user |
| **Eloquent** | Laravel's ORM (≈ Django ORM / SQLAlchemy) |
| **Blade** | Laravel's template engine (≈ Jinja2) |
| **Artisan** | Laravel's CLI (≈ `manage.py`) |
| **Middleware** | code wrapping request handling (auth checks etc.) |
| **Policy** | per-model authorization class (may user U edit device D?) |
| **Facade** | Laravel's static-looking service accessors (`Log::info()`, `DB::transaction()`, `MQTT::connection()`) — static syntax, real object underneath |
| **Sanctum** | Laravel's lightweight API auth (tokens + SPA cookie mode) |
| **Reverb / Echo** | Laravel's WebSocket server / its browser client library |
| **Carbon** | PHP's ergonomic datetime library (`now()->subHour()`) |
| **Composer / vendor/** | PHP's package manager / its installed-packages dir (≈ pip / site-packages) |
| **Vite** | frontend asset bundler (compiles JS/CSS) |
| **FGAC** | fine-grained access control — the planned permission system replacing role checks ([docs/FGAC_IMPLEMENTATION_PLAN.md](docs/FGAC_IMPLEMENTATION_PLAN.md)) |

---

*Companion documents:* [RUNNING_LOCALLY.md](RUNNING_LOCALLY.md) (setup),
[OPERATIONS_RUNBOOK.md](OPERATIONS_RUNBOOK.md) (production operations),
[erd.md](erd.md) (schema diagram), [use-case.md](use-case.md) (product
scenarios), [PENDING_WORK.md](PENDING_WORK.md) (what's next).
