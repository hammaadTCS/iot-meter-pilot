<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('meters:scan-health')
    ->everyMinute()
    ->withoutOverlapping();

// Flush the alert coalescing buffer — one digest per user per minute.
Schedule::command('alerts:dispatch-digests')
    ->everyMinute()
    ->withoutOverlapping();

// Consumption alert detector (budgets, anomaly) — reads the rollups, hourly.
Schedule::command('alerts:scan-consumption')
    ->hourly()
    ->withoutOverlapping();

// Electrical threshold detector (voltage/power/pf) — reads latest state,
// per-minute with debounce so transients never flap.
Schedule::command('alerts:scan-thresholds')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('meters:prune-ingestion-events --days=30')
    ->daily()
    ->withoutOverlapping();

// Retention for the per-hour consumption rollup (simplified-dashboard data
// source). Day/month rollups are never pruned; windows older than this fall
// back to day buckets in the aggregate endpoint.
Schedule::command('meters:prune-hourly-consumption --days=180')
    ->dailyAt('01:10')
    ->withoutOverlapping();

// Retention for the alert/notification subsystem.
Schedule::command('alerts:prune')
    ->dailyAt('02:30')
    ->withoutOverlapping();

// Safety net: freeze daily consumption rows for days that have ended but whose
// device went silent before the next day's first reading closed them.
Schedule::command('meters:close-day')
    ->dailyAt('00:10')
    ->withoutOverlapping();

// Safety net: freeze monthly consumption rows for months that have ended but
// whose device went silent before the next month's first reading closed them.
Schedule::command('meters:close-month')
    ->dailyAt('00:15')
    ->withoutOverlapping();
