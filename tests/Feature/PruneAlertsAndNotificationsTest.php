<?php

namespace Tests\Feature;

use App\Models\AlertEvent;
use App\Models\Device;
use App\Models\PendingAlertNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;

/**
 * alerts:prune keeps the delivery tables bounded — removing old read
 * notifications, drained buffer rows, and old resolved alerts, while never
 * touching unread notifications or open alerts.
 */
#[RequiresPhpExtension('pdo_sqlite')]
class PruneAlertsAndNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_prunes_old_read_notifications_but_keeps_unread_and_recent(): void
    {
        Carbon::setTestNow('2026-07-02 12:00:00');
        $user = User::factory()->create();

        $this->insertNotification($user, readAt: now()->subDays(40), createdAt: now()->subDays(40)); // old + read → prune
        $this->insertNotification($user, readAt: null, createdAt: now()->subDays(40));               // old + unread → keep
        $this->insertNotification($user, readAt: now(), createdAt: now());                            // recent read → keep

        $this->artisan('alerts:prune')->assertExitCode(0);

        $this->assertSame(2, DB::table('notifications')->count());
    }

    public function test_prunes_drained_buffer_and_old_resolved_alerts(): void
    {
        Carbon::setTestNow('2026-07-02 12:00:00');
        $user   = User::factory()->create();
        $device = Device::create([
            'code' => 'm-'.Str::random(6), 'name' => 'M', 'type' => 'meter',
            'mqtt_topic' => 'meters/'.Str::random(6), 'is_active' => true, 'user_id' => $user->id,
        ]);

        $oldResolved = $this->alert($device, 'resolved', resolvedAt: now()->subDays(120));
        $openAlert   = $this->alert($device, 'open', resolvedAt: null);

        PendingAlertNotification::create([
            'user_id' => $user->id, 'alert_event_id' => $openAlert->id,
            'transition' => 'opened', 'dispatched_at' => now()->subDays(10), // drained + old → prune
        ]);
        PendingAlertNotification::create([
            'user_id' => $user->id, 'alert_event_id' => $openAlert->id,
            'transition' => 'opened', 'dispatched_at' => null,               // still pending → keep
        ]);

        $this->artisan('alerts:prune')->assertExitCode(0);

        $this->assertDatabaseMissing('alert_events', ['id' => $oldResolved->id]);
        $this->assertDatabaseHas('alert_events', ['id' => $openAlert->id]);
        $this->assertSame(1, PendingAlertNotification::count());
    }

    private function insertNotification(User $user, ?Carbon $readAt, Carbon $createdAt): void
    {
        DB::table('notifications')->insert([
            'id'                => (string) Str::uuid(),
            'type'              => 'App\\Notifications\\AlertDigestNotification',
            'notifiable_type'   => $user->getMorphClass(),
            'notifiable_id'     => $user->id,
            'data'              => json_encode(['title' => 'x']),
            'read_at'           => $readAt,
            'created_at'        => $createdAt,
            'updated_at'        => $createdAt,
        ]);
    }

    private function alert(Device $device, string $status, ?Carbon $resolvedAt): AlertEvent
    {
        return AlertEvent::create([
            'device_id'    => $device->id,
            'device_type'  => 'meter',
            'alert_type'   => 'telemetry_down',
            'severity'     => 'critical',
            'status'       => $status,
            'message'      => 'x',
            'triggered_at' => now()->subDays(130),
            'resolved_at'  => $resolvedAt,
        ]);
    }
}
