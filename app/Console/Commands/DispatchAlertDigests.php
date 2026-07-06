<?php

namespace App\Console\Commands;

use App\Models\AlertEvent;
use App\Models\PendingAlertNotification;
use App\Models\User;
use App\Notifications\AlertDigestNotification;
use Illuminate\Console\Command;

/**
 * Flush the coalescing buffer: for each user with undispatched pending alerts,
 * send ONE AlertDigestNotification (a single alert or a whole outage collapsed
 * into one message). Scheduled every minute.
 *
 * Efficiency: iterates distinct user ids and loads only one user's pending rows
 * at a time (bounded memory even during a mass event); the notification itself
 * is queued, so this command never blocks on mail.
 */
class DispatchAlertDigests extends Command
{
    protected $signature = 'alerts:dispatch-digests';

    protected $description = 'Send buffered alert notifications, coalesced per user into one digest';

    private const SEVERITY_RANK = ['warning' => 1, 'critical' => 2];

    public function handle(): int
    {
        $userIds = PendingAlertNotification::query()
            ->whereNull('dispatched_at')
            ->distinct()
            ->pluck('user_id');

        $sent = 0;
        foreach ($userIds as $userId) {
            if ($this->dispatchForUser((int) $userId)) {
                $sent++;
            }
        }

        $this->info("Alert digests dispatched to {$sent} user(s).");

        return self::SUCCESS;
    }

    private function dispatchForUser(int $userId): bool
    {
        $rows = PendingAlertNotification::query()
            ->where('user_id', $userId)
            ->whereNull('dispatched_at')
            ->with('alertEvent.device')
            ->get();

        if ($rows->isEmpty()) {
            return false;
        }

        $markIds  = $rows->pluck('id');
        $alertIds = $rows->pluck('alert_event_id')->unique();
        $user     = User::find($userId);

        // User removed since enqueue → just drain the buffer, nothing to send.
        if (! $user) {
            PendingAlertNotification::whereIn('id', $markIds)->update(['dispatched_at' => now()]);

            return false;
        }

        // One item per (alert, transition); skip any whose alert row has vanished.
        $items = $rows
            ->filter(fn (PendingAlertNotification $r) => $r->alertEvent !== null)
            ->unique(fn (PendingAlertNotification $r) => $r->alert_event_id . '-' . $r->transition)
            ->map(fn (PendingAlertNotification $r) => [
                'device_id'   => (int) $r->alertEvent->device_id,
                'device_name' => $r->alertEvent->device?->name ?? ('Device #' . $r->alertEvent->device_id),
                'device_type' => (string) $r->alertEvent->device_type,
                'alert_type'  => (string) $r->alertEvent->alert_type,
                'severity'    => (string) $r->alertEvent->severity,
                'message'     => (string) $r->alertEvent->message,
                'transition'  => (string) $r->transition,
                'at'          => optional($r->alertEvent->triggered_at)->toDateTimeString(),
            ])
            ->values()
            ->all();

        if ($items !== []) {
            $user->notify(new AlertDigestNotification($items, $this->highestSeverity($items)));
        }

        // Drain the buffer and stamp the alerts as notified (idempotency guard).
        PendingAlertNotification::whereIn('id', $markIds)->update(['dispatched_at' => now()]);
        AlertEvent::whereIn('id', $alertIds)->whereNull('notified_at')->update(['notified_at' => now()]);

        return $items !== [];
    }

    /** @param array<int, array{severity:string}> $items */
    private function highestSeverity(array $items): string
    {
        $rank = 0;
        $severity = 'warning';

        foreach ($items as $item) {
            $r = self::SEVERITY_RANK[$item['severity']] ?? 1;
            if ($r >= $rank) {
                $rank = $r;
                $severity = $item['severity'];
            }
        }

        return $severity;
    }
}
