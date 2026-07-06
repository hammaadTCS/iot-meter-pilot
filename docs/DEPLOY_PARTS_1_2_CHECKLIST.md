# Ship It — Deploy Parts 1 & 2 (runbook)

Everything built (Part 1 consumption/reporting + Part 2 alert delivery A/B) is code-complete and tested
but **not yet live**. This is the runbook to ship it safely and prove it with one real meter. Do **staging
first**, then repeat on production.

## Why ordering matters (read first)
- The **MQTT consumer** now writes to a NEW table `meter_daily_consumption` (via `MeterPayloadProcessor`).
  The new consumer code must not run before that table exists → **migrate before deploying the consumer.**
- `ScanMeterHealth` was renamed from `meter_alert_events` → `alert_events`. Old scheduled code breaks if it
  runs after the rename but before the new code deploys → **pause the scheduler across the migrate+deploy window.**
- The consumer does **not** touch alert tables; the scheduler does. So we stop both briefly, migrate, deploy,
  then restart.

## 0. Pre-flight (staging)
- [ ] Back up the database.
- [ ] Confirm current code is committed and the deploy artifact is ready.
- [ ] `.env` set:
  - `MAIL_MAILER=ses` (or `postmark`) + credentials — **not `log`** (mail won't send otherwise).
  - `QUEUE_CONNECTION=database` (fine for now; Redis is a later trigger — see roadmap).
  - `BROADCAST_CONNECTION=reverb` and **Reverb is running** (realtime bell).

## 1. Quiesce
- [ ] Pause the scheduler (comment the `schedule:run` cron, or maintenance mode).
- [ ] Stop the MQTT consumer process.

## 2. Migrate
- [ ] `php artisan migrate` — creates `meter_daily_consumption`, renames `meter_alert_events → alert_events`
      (+ `device_type`, `notified_at`), creates `notifications`, `notification_preferences`,
      `pending_alert_notifications`.
- [ ] Sanity: `php artisan migrate:status` shows all green; `alert_events` exists, `meter_alert_events` gone.

## 3. Backfill (one-time, idempotent)
- [ ] `php artisan meters:backfill-daily-consumption` — rebuild per-day units from history (Part 1).
- [ ] If the **monthly** rollup was created by this deploy (i.e. not previously live):
      `php artisan meters:backfill-monthly-consumption`.

## 4. Deploy the new code
- [ ] Ship the release (new `MeterPayloadProcessor`, `ScanMeterHealth`, delivery pipeline, UI).
- [ ] `php artisan config:cache && php artisan route:cache && php artisan event:cache` (if you cache these).
- [ ] `npm run build` (Range Units card, daily-breakdown panel, bell markup, Tailwind utilities).

## 5. Restart the moving parts
- [ ] Start the MQTT consumer (now maintains the daily rollup).
- [ ] Resume the scheduler. Confirm it registers: `php artisan schedule:list` shows
      `meters:scan-health` (every min), `alerts:dispatch-digests` (every min), `alerts:prune` (daily),
      `meters:close-day` / `meters:close-month`.
- [ ] Start a **supervised queue worker**: `php artisan queue:work --queue=default --tries=3`
      (supervisor/systemd, auto-restart). The listener + notifications are queued — **without a worker,
      no alert is ever delivered.**

## 6. Smoke test (prove it end-to-end)
- [ ] **Consumption**: open a meter dashboard → the **Range Units** card and **Daily Breakdown** panel
      populate; download a daily CSV.
- [ ] **Alert**: pick a test meter and let it go silent >10 min (or temporarily lower the down threshold).
      Within a couple of minutes:
  - [ ] the **bell** shows an unread notification (and increments live if you keep the page open);
  - [ ] a real **email** arrives (check the mailbox / provider dashboard);
  - [ ] `/alerts` lists the alert;
  - [ ] recover the meter → alert flips to **resolved**, and a repeat scan does **not** re-email (anti-spam).
- [ ] **Preferences**: `/settings/notifications` → turn email off, re-trigger → only the bell updates, no email.

## 7. Rollback (if needed)
- This release's migrations are reversible **as a batch**: `php artisan migrate:rollback` reverses only the
  migrations this deploy applied (daily-rollup, `alert_events` rename, notifications/preferences/pending) —
  verified clean. The rename's `down()` restores `meter_alert_events`; the rest `dropIfExists`.
- **Do NOT run `migrate:reset` / a full from-scratch rollback** — an *unrelated pre-existing* migration
  (`add_user_id_to_devices_table`) has a SQLite/MySQL quirk dropping a column that's in a composite index, and
  it will abort a full reset. It has nothing to do with this release and never runs in a normal deploy.
- Backfills are derived from history, so re-running later is always safe.

## Done = value realized
Once this is green on production, Parts 1 & 2 are actually delivering. Only then move to the next build.
