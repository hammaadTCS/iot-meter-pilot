# Process Setup

Four processes must run permanently. **systemd is the only supported mechanism** —
supervisor configs were removed on 2026-09-02 because keeping two supervisors with
conflicting paths was itself a failure mode (`PENDING_WORK.md` §0 #7).

| Process | Command | If it dies |
|---|---|---|
| MQTT consumer | `mqtt:consume-meter` | No new readings arrive; the rest of the app keeps working |
| Queue worker | `queue:work` | **Alerts are never delivered** — rows pile up in `jobs` |
| Scheduler | `schedule:work` | **Nothing is ever detected.** No health scans, no digests, no pruning |
| Reverb | `reverb:start` | Live dashboard and bell push stop; **every broadcast notification job fails** |

Two things here are not obvious and both caused real incidents:

**`Restart=always`, never `on-failure`.** The consumer exits *cleanly* every 50,000
messages (`--restart-after`) so its PHP heap is recycled; the queue worker does the same
hourly via `--max-time=3600`. Those are **successful** exits. `Restart=on-failure` would
leave both stopped. Before supervision existed, the consumer stopped roughly every
12 days and was restarted by hand, which read as a series of one-offs rather than a
pattern.

**The scheduler is a service, not a cron line.** A dead cron entry fails silently and
the scheduler is what *creates* every alert, so its death would be invisible.

---

## Local development (this repository's own machine)

User units, already installed and running. They are versioned in
[`local/`](systemd/local/) so a rebuilt machine can reproduce them exactly.

```bash
# install (paths inside assume /home/hammaad/iot-meter-pilot)
cp deploy/systemd/local/*.service ~/.config/systemd/user/
systemctl --user daemon-reload
systemctl --user enable --now iot-meter-consumer iot-meter-queue \
                              iot-meter-scheduler iot-meter-reverb

# REQUIRED: without this, user services stop at logout and never start at boot
loginctl enable-linger "$USER"
```

```bash
systemctl --user list-units 'iot-meter-*'        # expect 4 x active running
systemctl --user restart iot-meter-consumer
journalctl --user -u iot-meter-consumer -f
```

`composer dev` runs **only** `serve`, `pail` and `vite`. Do not add the queue worker,
scheduler, Reverb or the consumer back into it: they have units, two workers on one
queue compete, and `composer dev` uses `--kill-others` so one crash would take the
others down with it.

## Production (system units)

Templates in [`systemd/`](systemd/), written for `www-data` under
`/var/www/iot-meter-pilot`. **Edit `User=`, `WorkingDirectory=` and `ExecStart=` to
match the deploy path before installing.**

```bash
sudo cp deploy/systemd/iot-meter-*.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now iot-meter-consumer iot-meter-queue \
                            iot-meter-scheduler iot-meter-reverb
sudo journalctl -u iot-meter-consumer -f
```

> The consumer unit was named `iot-mqtt-consumer.service` before 2026-09-02. It is now
> `iot-meter-consumer.service`, matching the other three and the running set.

### Bound the journal before you rely on it

All four log to journald, which defaults to **10% of the filesystem** — about 11.7 GB
on a 117 GB disk. On the development machine an unbounded log filled the disk and took
the consumer down with it.

```bash
sudo mkdir -p /etc/systemd/journald.conf.d
sudo sh -c 'printf "[Journal]\nSystemMaxUse=200M\n" > /etc/systemd/journald.conf.d/99-size-limit.conf'
sudo systemctl restart systemd-journald
```

## Graceful restart after a deploy

```bash
sudo systemctl restart iot-meter-consumer     # or systemctl --user, locally
```

Every unit handles `SIGTERM`: the consumer finishes the message in hand and releases its
`flock`; the queue worker finishes its current job. `TimeoutStopSec` allows for that
before systemd escalates.
