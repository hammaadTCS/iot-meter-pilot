<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Device extends Model
{
    /**
     * Fields we allow mass assignment on.
     */
    protected $fillable = [
        'code',
        'name',
        'type',
        'mqtt_topic',
        'is_active',
        'last_seen_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_seen_at' => 'datetime',
    ];

    /**
     * A device has many historical readings.
     */
    public function readings(): HasMany
    {
        return $this->hasMany(MeterReading::class);
    }

    /**
     * A device has one latest/current state.
     */
    public function latestState(): HasOne
    {
        return $this->hasOne(LatestMeterState::class);
    }
}
