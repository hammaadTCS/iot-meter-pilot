<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The debounce counters for one (device, threshold-check) pair — see the
 * create_meter_threshold_states migration and alerts:scan-thresholds. Purely a
 * detector-internal bookkeeping row; it never reaches the UI.
 */
class MeterThresholdState extends Model
{
    protected $fillable = [
        'device_id',
        'check_key',
        'breach_streak',
        'clear_streak',
    ];

    protected $casts = [
        'breach_streak' => 'integer',
        'clear_streak'  => 'integer',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
