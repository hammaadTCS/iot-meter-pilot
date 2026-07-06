<?php

namespace App\Listeners;

use App\Events\AlertOpened;
use App\Events\AlertResolved;
use App\Models\AlertEvent;
use App\Models\NotificationPreference;
use App\Models\PendingAlertNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Turns an alert transition into buffered per-recipient delivery rows.
 *
 * Queued (`ShouldQueue`) so recipient resolution never slows the health scan.
 * It does NOT send anything — it only decides *who* should hear about the alert
 * and drops a row per recipient into pending_alert_notifications. The scheduled
 * DispatchAlertDigests job later collapses those per user into one notification,
 * which is what keeps a correlated burst from becoming a mail storm.
 *
 * Recipients:
 *   - the device **owner** — always (their own devices are not a firehose);
 *   - **fleet operators** (fleet_scope = 'all') whose min_severity the alert
 *     meets — so an admin can watch the whole fleet without being paged on
 *     every low-severity blip.
 */
class EnqueueAlertForDelivery implements ShouldQueue
{
    public function handle(AlertOpened|AlertResolved $event): void
    {
        $alert = $event->alertEvent;
        $alert->loadMissing('device');

        $transition = $event instanceof AlertOpened
            ? PendingAlertNotification::TRANSITION_OPENED
            : PendingAlertNotification::TRANSITION_RESOLVED;

        foreach ($this->recipientIds($alert) as $userId) {
            PendingAlertNotification::create([
                'user_id'        => $userId,
                'alert_event_id' => $alert->id,
                'transition'     => $transition,
            ]);
        }
    }

    /**
     * @return list<int> distinct recipient user ids
     */
    private function recipientIds(AlertEvent $alert): array
    {
        $ids = [];

        // Owner — always.
        if ($alert->device && $alert->device->user_id) {
            $ids[] = (int) $alert->device->user_id;
        }

        // Fleet operators who opted in and whose severity floor this alert meets.
        NotificationPreference::query()
            ->where('fleet_scope', 'all')
            ->get()
            ->each(function (NotificationPreference $pref) use ($alert, &$ids) {
                if ($pref->allowsSeverity($alert->severity)) {
                    $ids[] = (int) $pref->user_id;
                }
            });

        return array_values(array_unique($ids));
    }
}
