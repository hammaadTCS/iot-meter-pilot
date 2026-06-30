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

Schedule::command('meters:prune-ingestion-events --days=30')
    ->daily()
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
