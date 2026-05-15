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

`queue:work` is included for future queued jobs/broadcasting. It is safe to run even when the current workload is light.

`schedule:work` runs scheduled Laravel tasks. The project schedules:

```bash
php artisan meters:scan-health
```

once per minute, with Laravel's `withoutOverlapping()` guard.

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

Alert records are stored in:

```text
meter_alert_events
```

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

