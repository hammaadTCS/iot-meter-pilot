<?php

namespace App\Notifications;

use App\Models\NotificationPreference;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One notification carrying a user's coalesced alerts for the current digest
 * window — a single alert or a whole outage, always one message per user.
 *
 * Queued (`ShouldQueue`) so mail/SMTP latency never blocks the dispatcher.
 * Channels are decided per-user by NotificationPreference: `database` +
 * `broadcast` drive the bell (always on), `mail` is gated by the user's severity
 * floor and quiet hours.
 *
 * Carries plain arrays (not Eloquent models) so queue serialization is trivial
 * and robust.
 */
class AlertDigestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int, array{device_id:int, device_name:string, device_type:string, alert_type:string, severity:string, message:string, transition:string, at:?string}>  $items
     */
    public function __construct(
        public array $items,
        public string $highestSeverity,
    ) {
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return NotificationPreference::forUser($notifiable)->channelsFor($this->highestSeverity, now());
    }

    public function toMail(object $notifiable): MailMessage
    {
        $count    = count($this->items);
        $opened   = collect($this->items)->where('transition', 'opened')->count();
        $resolved = $count - $opened;

        $subject = $count === 1
            ? $this->summaryLine($this->items[0])
            : "{$count} device alerts ({$opened} new, {$resolved} resolved)";

        $mail = (new MailMessage)
            ->subject('[IoT] ' . $subject)
            ->greeting($count > 1 ? 'Device alerts' : 'Device alert');

        // Cap the body so a mass event doesn't produce a giant email.
        foreach (array_slice($this->items, 0, 20) as $item) {
            $mail->line($this->summaryLine($item));
        }
        if ($count > 20) {
            $mail->line('…and ' . ($count - 20) . ' more.');
        }

        return $mail->action('View alerts', url('/alerts'));
    }

    /** Payload the bell reads (stored in the notifications table). */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title'            => $this->title(),
            'count'            => count($this->items),
            'highest_severity' => $this->highestSeverity,
            'items'            => $this->items,
            'url'              => url('/alerts'),
        ];
    }

    /** Compact realtime payload pushed to the bell over the user's private channel. */
    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage([
            'title'            => $this->title(),
            'count'            => count($this->items),
            'highest_severity' => $this->highestSeverity,
        ]);
    }

    private function title(): string
    {
        $count = count($this->items);

        return $count === 1 ? $this->summaryLine($this->items[0]) : "{$count} device alerts";
    }

    /** @param array{device_name:string, message:string, transition:string} $item */
    private function summaryLine(array $item): string
    {
        $prefix = $item['transition'] === 'resolved' ? 'Recovered' : 'Alert';

        return "{$prefix} — {$item['device_name']}: {$item['message']}";
    }
}
