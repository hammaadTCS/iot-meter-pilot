<?php

namespace App\Notifications\Channels;

use Illuminate\Notifications\Channels\BroadcastChannel;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A broadcast channel that cannot take a notification down with it.
 *
 * WHY THIS EXISTS
 * ---------------
 * Between 2026-08-01 and 2026-08-04, 454 queued notification jobs failed. Every
 * one was the same:
 *
 *   BroadcastException: cURL error 7 … 127.0.0.1:8081 (connection refused)
 *
 * Reverb was not running. Laravel sends a notification's channels inside ONE
 * queued job, so a throw from the broadcast leg failed the whole job — even
 * though the `database` leg (the notification bell, which is what users
 * actually read) had already succeeded.
 *
 * That is the wrong priority order. Live push is a cosmetic enhancement: it
 * makes the bell update without a refresh. Alert delivery is the product. A
 * websocket server being down must never stop an outage alert from reaching the
 * customer who needs it.
 *
 * WHAT THIS CHANGES
 * -----------------
 * Broadcast failures are logged and swallowed. Every other channel proceeds
 * normally, the job succeeds, and nothing is retried. The user still gets the
 * bell item and the email; they just do not get the live push for that one
 * notification.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO
 * ----------------------------------
 * It does not silence the failure — it logs at `warning` with the notifiable
 * and notification class attached, so error tracking still surfaces a Reverb
 * outage. The goal is to stop a degraded dependency becoming an outage, not to
 * hide it.
 *
 * Registered in AppServiceProvider by binding it over BroadcastChannel::class,
 * which is what Illuminate's ChannelManager::createBroadcastDriver() resolves —
 * so `via()` keeps returning the plain 'broadcast' string and no notification
 * needs to know this exists.
 */
class ResilientBroadcastChannel extends BroadcastChannel
{
    public function send($notifiable, Notification $notification): mixed
    {
        try {
            return parent::send($notifiable, $notification);
        } catch (Throwable $e) {
            // Degraded, not failed. The notification's other channels have
            // already been delivered or are still to come; neither should be
            // rolled back because the websocket server is unreachable.
            Log::warning('Broadcast notification channel failed; delivery continues on other channels.', [
                'notification' => $notification::class,
                'notifiable' => $notifiable::class,
                'notifiable_id' => $notifiable->getKey(),
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
