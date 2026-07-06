<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One buffered "an alert should be delivered to this user" record, awaiting the
 * next digest flush (DispatchAlertDigests). Grouping these per user is what
 * collapses a correlated burst into a single notification.
 */
class PendingAlertNotification extends Model
{
    public const TRANSITION_OPENED = 'opened';
    public const TRANSITION_RESOLVED = 'resolved';

    protected $fillable = [
        'user_id',
        'alert_event_id',
        'transition',
        'dispatched_at',
    ];

    protected $casts = [
        'dispatched_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function alertEvent(): BelongsTo
    {
        return $this->belongsTo(AlertEvent::class);
    }
}
