# Operations Runbook

## Purpose

This runbook describes the runtime processes needed for the IoT Meter Pilot and how to keep them alive in production-like environments.

## Required Runtime Processes

For the full live application, run:

```bash
php artisan serve
npm run dev
php artisan reverb:start
php artisan mqtt:consume-meter
```

For production, replace `php artisan serve` and `npm run dev` with your web server and built frontend assets.

## Production Process List

Recommended supervised processes:

```text
php-fpm or web server
php artisan reverb:start
php artisan mqtt:consume-meter
php artisan queue:work
php artisan schedule:work
```


`schedule:work` runs scheduled Laravel tasks (all guarded by `withoutOverlapping()`).
The current schedule (`php artisan schedule:list` is authoritative):

```text
every minute   meters:scan-health              open/resolve offline health alerts
every minute   alerts:dispatch-digests         flush the per-user notification digests
every minute   alerts:scan-thresholds          voltage/power/pf threshold alerts (debounced)
hourly         alerts:scan-consumption         budget + usage-anomaly alerts (from rollups)
daily 00:10    meters:close-day                finalise ended daily consumption rows
daily 00:15    meters:close-month              finalise ended monthly consumption rows
daily 00:00    meters:prune-ingestion-events   retention for the ingestion audit log
daily 01:10    meters:prune-hourly-consumption retention (180d) for the hourly rollup (simplified dashboard)
daily 02:30    alerts:prune                    retention for notifications/alerts/buffer
```

`queue:work` is REQUIRED (not optional): the alert delivery listener and all
notifications are queued — without a worker, no alert is ever delivered.

## MQTT Consumer Lock

`php artisan mqtt:consume-meter` now uses a local file lock:

```text
storage/framework/mqtt-consumer.lock
```

Why this exists:

- prevents two local consumers from processing the same topics
- avoids MQTT client-id fights
- avoids noisy duplicate processing

If another instance is already running, the second command exits safely.

Important limitation:

- this is a single-host lock
- if you deploy multiple app servers, run only one MQTT consumer instance per topic group or use a distributed lock strategy

## Health Alert Scanner

Manual run:

```bash
php artisan meters:scan-health
```

Scan one device:

```bash
php artisan meters:scan-health --device=1
```

What it does:

- opens `telemetry_stale` alerts when a meter becomes stale
- opens `telemetry_down` alerts when a meter becomes down
- resolves stale/down alerts when telemetry recovers
- avoids creating duplicate open alerts for the same device and alert type

Alert records are stored in the device-agnostic `alert_events` table (see
`docs/erd.md`). Delivery to users (bell + email digests) is handled by the
queued pipeline and `alerts:dispatch-digests`, not by this scanner.

## Supervisor Example

Example file:

```ini
[program:iot-meter-reverb]
command=php /home/hammaad/iot-meter-pilot/artisan reverb:start
directory=/home/hammaad/iot-meter-pilot
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
redirect_stderr=true
stdout_logfile=/home/hammaad/iot-meter-pilot/storage/logs/reverb-supervisor.log

[program:iot-meter-mqtt]
command=php /home/hammaad/iot-meter-pilot/artisan mqtt:consume-meter
directory=/home/hammaad/iot-meter-pilot
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
redirect_stderr=true
stdout_logfile=/home/hammaad/iot-meter-pilot/storage/logs/mqtt-supervisor.log

[program:iot-meter-scheduler]
command=php /home/hammaad/iot-meter-pilot/artisan schedule:work
directory=/home/hammaad/iot-meter-pilot
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
redirect_stderr=true
stdout_logfile=/home/hammaad/iot-meter-pilot/storage/logs/scheduler-supervisor.log

[program:iot-meter-queue]
command=php /home/hammaad/iot-meter-pilot/artisan queue:work --sleep=1 --tries=3 --timeout=90
directory=/home/hammaad/iot-meter-pilot
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
redirect_stderr=true
stdout_logfile=/home/hammaad/iot-meter-pilot/storage/logs/queue-supervisor.log
```

Adjust `user` and paths for your server.

## systemd Example

MQTT consumer service:

```ini
[Unit]
Description=IoT Meter MQTT Consumer
After=network.target

[Service]
WorkingDirectory=/home/hammaad/iot-meter-pilot
ExecStart=/usr/bin/php artisan mqtt:consume-meter
Restart=always
RestartSec=5
User=www-data

[Install]
WantedBy=multi-user.target
```

Scheduler service:

```ini
[Unit]
Description=IoT Meter Laravel Scheduler
After=network.target

[Service]
WorkingDirectory=/home/hammaad/iot-meter-pilot
ExecStart=/usr/bin/php artisan schedule:work
Restart=always
RestartSec=5
User=www-data

[Install]
WantedBy=multi-user.target
```

## Deployment Checklist

Run after pulling changes:

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Restart supervised workers after deployment:

```bash
php artisan queue:restart
```

Then restart the supervised services for:

```text
reverb
mqtt consumer
scheduler
```

