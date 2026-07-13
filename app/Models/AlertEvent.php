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
     * Visibility scope — mirrors device visibility: alerts.view_any sees the
     * whole fleet, alerts.view_own (built-in) sees own devices' alerts only.
     * This was the seam left for FGAC; swapped to permissions 2026-07-10.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->can('alerts.view_any')) {
            return $query;
        }

        if (! $user->can('alerts.view_own')) {
            return $query->whereRaw('1 = 0'); // fully stripped account sees nothing
        }

        return $query->whereHas('device', fn (Builder $q) => $q->where('user_id', $user->id));
    }
}
