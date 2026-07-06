<?php

namespace App\Console\Commands;

use App\Models\AlertEvent;
use App\Models\PendingAlertNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Retention for the alert/notification subsystem, so none of its tables grow
 * unbounded as more devices produce more alerts (mirrors the existing
 * meters:prune-ingestion-events pattern). Scheduled daily.
 *
 *   - read notifications   older than --notification-days (bell history)
 *   - drained buffer rows   older than --pending-days       (delivery mechanics)
 *   - resolved alerts       older than --alert-days          (closed audit trail)
 *
 * Open alerts and unread notifications are never pruned.
 */
class PruneAlertsAndNotifications extends Command
{
    protected $signature = 'alerts:prune
                            {--notification-days=30 : Delete read notifications older than this}
                            {--pending-days=7 : Delete dispatched buffer rows older than this}
                            {--alert-days=90 : Delete resolved alerts older than this}';

    protected $description = 'Prune read notifications, drained buffer rows, and old resolved alerts';

    public function handle(): int
    {
        $now = now();

        $notifications = DB::table('notifications')
            ->whereNotNull('read_at')
            ->where('created_at', '<', $now->copy()->subDays((int) $this->option('notification-days')))
            ->delete();

        $pending = PendingAlertNotification::query()
            ->whereNotNull('dispatched_at')
            ->where('dispatched_at', '<', $now->copy()->subDays((int) $this->option('pending-days')))
            ->delete();

        $alerts = AlertEvent::query()
            ->where('status', 'resolved')
            ->whereNotNull('resolved_at')
            ->where('resolved_at', '<', $now->copy()->subDays((int) $this->option('alert-days')))
            ->delete();

        $this->info("Pruned {$notifications} notification(s), {$pending} buffer row(s), {$alerts} resolved alert(s).");
        Log::info('Alert/notification prune complete', [
            'notifications' => $notifications,
            'pending'       => $pending,
            'alerts'        => $alerts,
        ]);

        return self::SUCCESS;
    }
}
