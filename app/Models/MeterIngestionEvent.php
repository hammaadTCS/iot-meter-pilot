<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeterIngestionEvent extends Model
{
    protected $fillable = [
        'device_id',
        'topic',
        'status',
        'error_code',
        'error_message',
        'payload_preview',
        'context',
        'received_at',
    ];

    protected $casts = [
        'context' => 'array',
        'received_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
