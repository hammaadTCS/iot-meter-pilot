<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LatestMeterState extends Model
{
    protected $fillable = [
        'device_id',
        'ts',
        'voltage',
        'current',
        'power',
        'energy_computed_wh',
        'energy_pzem_wh',
        // Cached current-month consumption in kWh ("units"), kept here so the
        // dashboard snapshot stays a single-row read. The source of truth is the
        // meter_monthly_consumption table; this column mirrors its current row.
        'monthly_units_kwh',
        'frequency',
        'pf',
        'received_at',
    ];

    protected $casts = [
        'received_at' => 'datetime',
    ];

    /**
     * Each latest state belongs to one device.
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
