<?php

namespace App\Events;

use App\Models\AlertEvent;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when an open device alert clears (recovery). Delivered in-app so the bell
 * and alerts console reflect the recovery; whether it also emails is a per-user
 * preference decided downstream.
 */
class AlertResolved
{
    use Dispatchable, SerializesModels;

    public function __construct(public AlertEvent $alertEvent)
    {
    }
}
