<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Meter Health Thresholds
    |--------------------------------------------------------------------------
    |
    | A meter is considered:
    | - stale after it has missed a few expected telemetry intervals
    | - down after it has been silent for a longer period
    |
    | These defaults are intentionally conservative for a pilot deployment and
    | can be overridden with environment variables later if needed.
    |
    */

    'stale_after_seconds' => env('METER_HEALTH_STALE_AFTER_SECONDS', 180),
    'down_after_seconds' => env('METER_HEALTH_DOWN_AFTER_SECONDS', 600),
];
