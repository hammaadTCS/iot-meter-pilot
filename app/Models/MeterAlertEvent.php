<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeterAlertEvent extends Model
{
    protected $fillable = [
        'device_id',
        'alert_type',
        'severity',
        'status',
        'message',
        'context',
        'triggered_at',
        'resolved_at',
    ];

    protected $casts = [
        'context' => 'array',
        'triggered_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
