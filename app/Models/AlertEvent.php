<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A device-agnostic alert record.
 *
 * Meters are the first (currently only) producer — telemetry_stale /
 * telemetry_down opened and resolved by ScanMeterHealth — but the table is
 * device-agnostic so other device types feed the same record and the same
 * delivery pipeline (AlertOpened / AlertResolved -> coalescer -> digest).
 */
class AlertEvent extends Model
{
    protected $table = 'alert_events';

    protected $fillable = [
        'device_id',
        'device_type',
        'alert_type',
        'severity',
        'status',
        'message',
        'context',
        'triggered_at',
        'resolved_at',
        'notified_at',
    ];

    protected $casts = [
        'context'      => 'array',
        'triggered_at' => 'datetime',
        'resolved_at'  => 'datetime',
        'notified_at'  => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * Visibility scope — mirrors device visibility, so the alerts console shows a
     * user only their own devices' alerts and an admin the whole fleet. This is
     * the single seam FGAC (Part 3) later swaps from role checks to
     * alerts.view_any / alerts.view_own without touching callers.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdminOrAbove()) {
            return $query;
        }

        return $query->whereHas('device', fn (Builder $q) => $q->where('user_id', $user->id));
    }
}
