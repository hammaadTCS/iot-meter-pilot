<?php

namespace Tests\Feature;

use App\Events\MeterAvailabilityUpdated;
use App\Events\MeterReadingUpdated;
use App\Models\Device;
use App\Models\MeterReading;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Telemetry used to broadcast on a public channel named "meters", carrying
 * every device's live power and monthly units. The Reverb app key is shipped
 * to every browser, so a public channel meant anyone could stream every
 * tenant's consumption — bypassing DevicePolicy and the meter.* slugs entirely.
 *
 * These tests pin the replacement: one private channel per device, authorized
 * against the same predicate the dashboard uses.
 */
class TelemetryBroadcastChannelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * phpunit.xml pins BROADCAST_CONNECTION=null, and the null broadcaster's
     * auth() is a no-op that answers 200 to everything — so the endpoint has to
     * be pointed at a real driver for these assertions to mean anything.
     *
     * Broadcast::channel() proxies to whichever driver is default at the time,
     * and routes/channels.php ran at boot against the null driver, so the file
     * is re-required here to register the callbacks on the new driver too.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);

        require base_path('routes/channels.php');
    }

    /** Pusher-protocol private-channel auth requires a socket id. */
    private function authorize(string $channel): \Illuminate\Testing\TestResponse
    {
        return $this->post('/broadcasting/auth', [
            'channel_name' => $channel,
            'socket_id' => '1234.5678',
        ]);
    }

    /** A user holding the built-ins plus the given meter slugs. */
    private function userWithMeterSlugs(array $slugs): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo([
            'dashboard.view', 'devices.view_own', 'alerts.view_own',
            'alerts.settings_own', 'api.devices.read', 'api.readings.read',
            ...$slugs,
        ]);

        return $user;
    }

    private function meterOwnedBy(User $user): Device
    {
        return Device::factory()->create([
            'user_id' => $user->id,
            'type'    => 'meter',
        ]);
    }

    public function test_reading_event_broadcasts_on_the_devices_private_channel(): void
    {
        $user = $this->userWithMeterSlugs(['meter.access', 'meter.live_data']);
        $device = $this->meterOwnedBy($user);
        $reading = MeterReading::create([
            'device_id' => $device->id,
            'ts' => 1_754_000_000,
            'power' => 120.5,
            'received_at' => now(),
            'raw_payload' => ['ts' => 1_754_000_000, 'power' => 120.5],
        ]);

        $channels = (new MeterReadingUpdated($device, $reading))->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame("private-device.{$device->id}", (string) $channels[0]);
    }

    public function test_availability_event_broadcasts_on_the_devices_private_channel(): void
    {
        $user = $this->userWithMeterSlugs(['meter.access', 'meter.live_data']);
        $device = $this->meterOwnedBy($user);

        $channels = (new MeterAvailabilityUpdated($device))->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame("private-device.{$device->id}", (string) $channels[0]);
    }

    public function test_owner_with_live_data_may_subscribe(): void
    {
        $user = $this->userWithMeterSlugs(['meter.access', 'meter.live_data']);
        $device = $this->meterOwnedBy($user);

        $this->actingAs($user);

        $this->authorize("private-device.{$device->id}")->assertOk();
    }

    public function test_another_users_device_channel_is_refused(): void
    {
        $owner = $this->userWithMeterSlugs(['meter.access', 'meter.live_data']);
        $device = $this->meterOwnedBy($owner);

        // A fully-equipped account that simply does not own this meter.
        $stranger = $this->userWithMeterSlugs(['meter.access', 'meter.live_data']);

        $this->actingAs($stranger);

        $this->authorize("private-device.{$device->id}")->assertForbidden();
    }

    public function test_owner_without_live_data_is_refused(): void
    {
        // Owns the meter and can see its dashboard, but the live-data section
        // is not granted — the socket must not hand them what the page won't.
        $user = $this->userWithMeterSlugs(['meter.access']);
        $device = $this->meterOwnedBy($user);

        $this->actingAs($user);

        $this->authorize("private-device.{$device->id}")->assertForbidden();
    }

    public function test_guests_are_refused(): void
    {
        $owner = $this->userWithMeterSlugs(['meter.access', 'meter.live_data']);
        $device = $this->meterOwnedBy($owner);

        $this->authorize("private-device.{$device->id}")->assertStatus(403);
    }
}
