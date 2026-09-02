<?php

/*
|--------------------------------------------------------------------------
| System health thresholds
|--------------------------------------------------------------------------
|
| Read by ScanSystemHealth, which opens and resolves AlertEvent rows with
| device_id = NULL through the same delivery pipeline as meter alerts.
|
| These exist because the machine has run out of disk twice in one month and
| on both occasions it was discovered by something breaking. Nothing watched
| the resource that caused both outages.
|
| Every threshold is env-overridable so a test (or a temporary investigation)
| can force a condition without editing code.
|
*/

return [

    /*
     | Filesystem to watch. The app, the database and the logs all share one
     | volume here, so a single path covers every consumer of it.
     */
    'disk' => [
        'path' => env('SYSTEM_HEALTH_DISK_PATH', base_path()),

        // Percent of the volume USED. 80 gives weeks of notice at the observed
        // growth rate; 90 is the point where a large write can still fail.
        'warning_percent' => (int) env('SYSTEM_HEALTH_DISK_WARNING', 80),
        'critical_percent' => (int) env('SYSTEM_HEALTH_DISK_CRITICAL', 90),
    ],

    /*
     | Supervised units that must be active. Checked with
     | `systemctl --user is-active`. Set to an empty list to disable the check
     | on machines without systemd - the test suite does exactly that.
     */
    'services' => array_values(array_filter(explode(',', (string) env(
        'SYSTEM_HEALTH_SERVICES',
        'iot-meter-consumer,iot-meter-queue,iot-meter-scheduler,iot-meter-reverb',
    )))),

    /*
     | Depth of the failed_jobs table. 574 jobs accumulated over four days
     | without anyone noticing, because nothing ever looked.
     */
    'failed_jobs' => [
        'warning' => (int) env('SYSTEM_HEALTH_FAILED_JOBS_WARNING', 100),
        'critical' => (int) env('SYSTEM_HEALTH_FAILED_JOBS_CRITICAL', 1000),
    ],

    /*
     | Log directories and the ceiling each is expected to stay under, in bytes.
     | A sink over its ceiling means a rotation or prune has stopped working -
     | which is how a single log file reached 16.9 GB.
     |
     | storage/logs is generous because the frozen legacy laravel.log still
     | lives there; lower it once that file is removed.
     */
    'log_paths' => [
        'storage/logs' => (int) env('SYSTEM_HEALTH_LOGS_LIMIT_BYTES', 20 * 1024 * 1024 * 1024),
        'storage/pail' => (int) env('SYSTEM_HEALTH_PAIL_LIMIT_BYTES', 512 * 1024 * 1024),
    ],

];
