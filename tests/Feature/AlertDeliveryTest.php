<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\NotificationPreference;
use App\Models\PendingAlertNotification;
use App\Models\User;
use App\Notifications\AlertDigestNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;

/**
 * End-to-end alert delivery: scan → transition event → queued listener buffers
 * per recipient → DispatchAlertDigests coalesces per user into one notification.
 * The headline guarantee is anti-spam: a burst becomes one digest per user, and
 * a drained buffer is never resent.
 */
#[RequiresPhpExtension('pdo_sqlite')]
class AlertDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-07-02 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_scan_buffers_a_pending_notification_for_the_owner(): void
    {
        $user = User::factory()->create();
        $this->staleMeter($user);

        $this->artisan('meters:scan-health')->assertExitCode(0);

        $this->assertDatabaseHas('pending_alert_notifications', [
            'user_id'       => $user->id,
            'transition'    => 'opened',
            'dispatched_at' => null,
        ]);
    }

    public function test_a_burst_coalesces_into_one_digest_per_user(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $this->staleMeter($user);
        $this->staleMeter($user);
        $this->staleMeter($user); // three meters, one owner — a mini "outage"

        $this->artisan('meters:scan-health')->assertExitCode(0);
        $this->assertSame(3, PendingAlertNotification::where('user_id', $user->id)->whereNull('dispatched_at')->count());

        $this->artisan('alerts:dispatch-digests')->assertExitCode(0);

        // Exactly ONE notification, carrying all three alerts.
        Notification::assertSentToTimes($user, AlertDigestNotification::class, 1);
        Notification::assertSentTo($user, AlertDigestNotification::class, fn (AlertDigestNotification $n) => count($n->items) === 3);
    }

    public function test_a_drained_buffer_is_not_resent(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $this->staleMeter($user);

        $this->artisan('meters:scan-health');
        $this->artisan('alerts:dispatch-digests');
        $this->artisan('alerts:dispatch-digests'); // second flush — nothing left

        Notification::assertSentToTimes($user, AlertDigestNotification::class, 1);
        $this->assertDatabaseMissing('pending_alert_notifications', [
            'user_id'       => $user->id,
            'dispatched_at' => null,
        ]);
    }

    public function test_recovery_is_buffered_as_resolved(): void
    {
        Notification::fake();

        $user  = User::factory()->create();
        $meter = $this->staleMeter($user);

        $this->artisan('meters:scan-health');       // opens → buffered
        $this->artisan('alerts:dispatch-digests');  // drains

        $meter->forceFill(['last_seen_at' => now()])->save();
        $this->artisan('meters:scan-health');        // recovers → buffered as resolved

        $this->assertDatabaseHas('pending_alert_notifications', [
            'user_id'    => $user->id,
            'transition' => 'resolved',
        ]);
    }

    public function test_preferences_gate_mail_by_severity_and_quiet_hours(): void
    {
        $user = User::factory()->create();

        $pref = new NotificationPreference([
            'user_id'           => $user->id,
            'mail_enabled'      => true,
            'database_enabled'  => true,
            'broadcast_enabled' => true,
            'min_severity'      => 'critical', // mail only for critical
            'fleet_scope'       => 'own',
        ]);

        // A warning is below the mail floor — in-app stays, mail drops.
        $warning = $pref->channelsFor('warning', now());
        $this->assertContains('database', $warning);
        $this->assertContains('broadcast', $warning);
        $this->assertNotContains('mail', $warning);

        // Critical meets the floor — mail included.
        $this->assertContains('mail', $pref->channelsFor('critical', now()));

        // Quiet hours suppress mail (but keep the in-app record).
        $pref->quiet_hours_start = '22:00';
        $pref->quiet_hours_end   = '06:00';
        $quiet = $pref->channelsFor('critical', Carbon::parse('2026-07-02 23:00'));
        $this->assertNotContains('mail', $quiet);
        $this->assertContains('database', $quiet);
    }

    private function staleMeter(User $owner): Device
    {
        return Device::create([
            'code'         => 'meter-'.fake()->unique()->slug(),
            'name'         => 'Meter '.fake()->unique()->word(),
            'type'         => 'meter',
            'mqtt_topic'   => 'meters/'.fake()->unique()->slug(),
            'is_active'    => true,
            'user_id'      => $owner->id,
            'last_seen_at' => now()->subMinutes(4), // telemetry_stale → warning
        ]);
    }
}
