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

Since 2026-09-01 the four processes that must **keep** running are systemd user services.
They start at boot, restart on crash, and survive a closed terminal. You only run one
thing by hand.

### Supervised — you do not start these

```bash
systemctl --user status  iot-meter-consumer    # MQTT ingest -> meter_readings
systemctl --user status  iot-meter-queue       # job worker  -> alert delivery
systemctl --user status  iot-meter-scheduler   # every-minute scans, prunes, rollups
systemctl --user status  iot-meter-reverb      # websockets  -> live dashboard + bell

systemctl --user restart iot-meter-<name>      # restart one
journalctl --user -u iot-meter-<name> -f       # follow its logs
```

Unit files live in `~/.config/systemd/user/` (outside the repo, so not in git).
`loginctl enable-linger hammaad` is set, which is what lets them run without a login
session and start at boot.

**Why these are supervised rather than hand-run:** each had a failure mode that was
invisible. The consumer exits cleanly every 50,000 messages (~12 days) for heap
recycling and had nothing to restart it. The scheduler *is* the alarm — if it dies
silently, nothing is ever detected. Reverb dying discards every alert notification.
And `queue:listen --tries=1` destroyed a job on its first failure, which is how 574
notifications were lost while Reverb was down.

### Terminal 1 — interactive dev tools only
```bash
composer dev
```
Runs `php artisan serve` (http://127.0.0.1:8000), `pail` (live logs), and `npm run dev`
(Vite hot assets). It uses `--kill-others`, so anything inside it dies when one member
dies — which is exactly why the queue worker was moved out to systemd.

> Do **not** add `queue:listen`, `schedule:work`, `reverb:start`, or the MQTT consumer
> back into this bundle. They have units. Two workers on one queue compete, and the
> `composer dev` copy carries the old `--tries=1` semantics.

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
1. `composer dev` — the web app itself.
2. A meter silent for > 10 min **and** owned by the user you are logged in as.

That is all. The scheduler that detects the alert and the queue worker that delivers it
are both supervised services and are already running — previously they were the two
pieces most often forgotten.

Confirm with:
```bash
systemctl --user list-units 'iot-meter-*'   # expect 4 x active running
```
