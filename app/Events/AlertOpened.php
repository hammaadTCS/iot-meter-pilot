<?php

namespace App\Events;

use App\Models\AlertEvent;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a device alert first opens (a genuine state transition, not a repeat
 * scan). This is the delivery trigger — the enqueue listener turns it into a
 * buffered, per-user notification. Detectors emit this; delivery never needs to
 * know which device type produced it.
 */
class AlertOpened
{
    use Dispatchable, SerializesModels;

    public function __construct(public AlertEvent $alertEvent)
    {
    }
}
