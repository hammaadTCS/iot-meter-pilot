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
