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
