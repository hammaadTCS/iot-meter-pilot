# Running the project locally

The full app is **several long-running processes**. The convenience script `composer dev` starts only some of
them — the IoT-specific ones (scheduler, Reverb, MQTT) must be started separately. Missing the **scheduler**
is what stops alerts/notifications from ever appearing.

---

## One-time setup (first checkout, or after pulling new migrations)

```bash
composer setup      # composer install → copy .env → key:generate → migrate → npm install → npm run build
```

Then make sure `.env` has (you already do, since it was running):
- `DB_CONNECTION=mysql` + `DB_HOST/DB_DATABASE/DB_USERNAME/DB_PASSWORD`
- `QUEUE_CONNECTION=database`
- `CACHE_STORE=redis` + `REDIS_CLIENT=predis` (Redis required since the FGAC phases — see below)
- `SESSION_DRIVER=database`
- `AUTH_ALLOW_REGISTRATION=true` (self-serve signups get the `consumer` bundle; `false` = invite-only)
- `BROADCAST_CONNECTION=reverb` + the `REVERB_*` keys
- your MQTT broker settings (see `config/mqtt-client.php` / `MQTT_*`)
- `MAIL_MAILER=log` is fine if you don't want email (the bell doesn't need mail)

### Redis (required — permission cache)

Runs as a Docker container. First time:
```bash
docker run -d --name iot-redis --restart unless-stopped -p 127.0.0.1:6379:6379 redis:7-alpine
```
After a reboot it auto-starts (`--restart unless-stopped`); if stopped manually: `docker start iot-redis`.
Health check: `docker exec iot-redis redis-cli ping` → `PONG`.

After pulling changes that add migrations, re-run:
```bash
php artisan migrate
# permission catalog + bundles (idempotent, safe to re-run):
php artisan db:seed --class=PermissionSeeder
php artisan db:seed --class=SuperAdminSeeder
# one-time bridge if your users still only have the legacy role column:
php artisan db:seed --class=MigrateRolesToPermissionsSeeder
# one-time, if you have historical readings and just added the rollups:
php artisan meters:backfill-daily-consumption
php artisan meters:backfill-monthly-consumption
php artisan meters:backfill-hourly-consumption   # simplified-dashboard hour buckets (last 180d)
# optional demo users:
php artisan db:seed
```

---

## Running it — the processes

You need these going. Easiest is a few terminals (or a process manager). Each stays running.

### Terminal 1 — web app + queue + assets (the `composer dev` bundle)
```bash
composer dev
```
This runs, concurrently: `php artisan serve` (http://127.0.0.1:8000), `queue:listen` (the job worker),
`pail` (live logs), and `npm run dev` (Vite hot assets).

> If you prefer them separate: `php artisan serve` · `php artisan queue:work` · `npm run dev`.

### Terminal 2 — the scheduler  ⬅ REQUIRED for alerts/notifications
```bash
php artisan schedule:work
```
Fires every minute: `meters:scan-health` (opens/resolves alerts), `alerts:dispatch-digests` (sends the bell
notifications), plus `meters:close-day`, `meters:close-month`, `alerts:prune`.
**Without this, no alert is ever detected and the bell never updates.**

### Terminal 3 — Reverb (WebSocket server) — realtime
```bash
php artisan reverb:start
```
Powers live dashboard updates and the realtime bell push. Optional for basic use — the bell still populates on
page load/refresh without it — but required for *live* updates.

### Terminal 4 — MQTT consumer — ingest real meter data
```bash
php artisan mqtt:consume-meter
```
Consumes meter telemetry from the broker into `meter_readings` / `latest_meter_states` (and maintains the
daily/monthly rollups). Without it, no new readings arrive (the rest of the app still runs).

---

## "Is it working?" — quick checks

```bash
# Alerts pipeline (worker must be running; or prepend QUEUE_CONNECTION=sync to run inline):
php artisan meters:scan-health          # opens alerts for stale(>3m)/down(>10m) meters
php artisan alerts:dispatch-digests     # writes the bell notifications

# Inspect:
php artisan schedule:list               # scheduled commands + next-due times
php artisan tinker --execute="echo 'jobs='.DB::table('jobs')->count().' notifications='.DB::table('notifications')->count().PHP_EOL;"
```

**Bell reminder:** notifications go to the **device owner**. If a meter has `user_id = NULL`, or you're logged
in as a different user, that user's bell stays empty by design. Assign the meter an owner and view the bell as
that user.

---

## Minimum to see the notification bell work
1. `composer dev` (Terminal 1 — gives you the app + queue worker)
2. `php artisan schedule:work` (Terminal 2 — **the piece that was missing**)
3. A meter that's been silent > 10 min **and** owned by the user you're logged in as.

Reverb (Terminal 3) and the MQTT consumer (Terminal 4) add realtime push and live data respectively.
