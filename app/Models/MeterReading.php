<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeterReading extends Model
{
    protected $fillable = [
        'device_id',
        'ts',
        'voltage',
        'current',
        'power',
        'energy_computed_wh',
        'energy_pzem_wh',
        'frequency',
        'pf',
        'raw_payload',
    ];

    protected $casts = [
        'raw_payload' => 'array',
    ];

    /**
     * Each reading belongs to one device.
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
