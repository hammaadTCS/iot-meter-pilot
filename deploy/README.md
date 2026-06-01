# Production Process Setup

## Processes that must run permanently

| Process | Command | Purpose |
|---|---|---|
| MQTT Consumer | `mqtt:consume-meter` | Ingests meter readings from broker |
| Queue Worker | `queue:work` | Processes broadcast jobs |
| Reverb | `reverb:start` | WebSocket server for dashboard |
| Scheduler | `schedule:run` via cron | Health scans, pruning |

---

## Option A — Supervisor (recommended for shared/VPS servers)

```bash
# Install
sudo apt install supervisor

# Copy config
sudo cp deploy/supervisor/iot-mqtt-consumer.conf /etc/supervisor/conf.d/
# Edit the `command=` path to match your deploy path

# Activate
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start iot-mqtt-consumer:*

# Check status
sudo supervisorctl status
sudo tail -f /var/log/supervisor/iot-mqtt-consumer.log
```

---

## Option B — systemd (clean VPS / Ubuntu server)

```bash
# Edit WorkingDirectory= and ExecStart= paths first
sudo cp deploy/systemd/iot-mqtt-consumer.service /etc/systemd/system/

sudo systemctl daemon-reload
sudo systemctl enable iot-mqtt-consumer
sudo systemctl start iot-mqtt-consumer

# Logs
sudo journalctl -u iot-mqtt-consumer -f
```

---

## Cron (Laravel scheduler — required for health scans and pruning)

```bash
# Add to www-data crontab
* * * * * /usr/bin/php /var/www/iot-meter-pilot/artisan schedule:run >> /dev/null 2>&1
```

---

## Graceful restart after a deploy

```bash
# Supervisor
sudo supervisorctl restart iot-mqtt-consumer:*

# systemd
sudo systemctl restart iot-mqtt-consumer
```

The consumer handles `SIGTERM` gracefully: it finishes the current message, releases the lock, and exits cleanly. The supervisor/systemd immediately restarts it.
