<?php

namespace Tests\Feature;

use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;

#[RequiresPhpExtension('pdo_sqlite')]
class MeterHealthAlertCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-04-27 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_scan_opens_one_stale_alert_and_resolves_it_after_recovery(): void
    {
        $device = $this->createMeter([
            'last_seen_at' => now()->subMinutes(4),
        ]);

        $this->artisan('meters:scan-health')
            ->assertExitCode(0);

        $this->assertDatabaseHas('alert_events', [
            'device_id' => $device->id,
            'alert_type' => 'telemetry_stale',
            'severity' => 'warning',
            'status' => 'open',
        ]);

        $this->artisan('meters:scan-health')
            ->assertExitCode(0);

        $this->assertDatabaseCount('alert_events', 1);

        $device->forceFill(['last_seen_at' => now()])->save();

        $this->artisan('meters:scan-health')
            ->assertExitCode(0);

        $this->assertDatabaseHas('alert_events', [
            'device_id' => $device->id,
            'alert_type' => 'telemetry_stale',
            'status' => 'resolved',
            'resolved_at' => '2026-04-27 12:00:00',
        ]);
    }

    public function test_scan_promotes_stale_alert_to_down_alert(): void
    {
        $device = $this->createMeter([
            'last_seen_at' => now()->subMinutes(4),
        ]);

        $this->artisan('meters:scan-health')
            ->assertExitCode(0);

        $device->forceFill(['last_seen_at' => now()->subMinutes(12)])->save();

        $this->artisan('meters:scan-health')
            ->assertExitCode(0);

        $this->assertDatabaseHas('alert_events', [
            'device_id' => $device->id,
            'alert_type' => 'telemetry_stale',
            'status' => 'resolved',
        ]);

        $this->assertDatabaseHas('alert_events', [
            'device_id' => $device->id,
            'alert_type' => 'telemetry_down',
            'severity' => 'critical',
            'status' => 'open',
        ]);
    }

    public function test_offline_alerts_can_be_opted_out_per_meter(): void
    {
        $device = $this->createMeter([
            'last_seen_at' => now()->subMinutes(12), // down territory
        ]);
        \App\Models\MeterAlertSetting::create(['device_id' => $device->id, 'offline_enabled' => false]);

        $this->artisan('meters:scan-health')->assertExitCode(0);

        $this->assertDatabaseCount('alert_events', 0);
    }

    public function test_opting_out_resolves_an_already_open_offline_alert(): void
    {
        $device = $this->createMeter([
            'last_seen_at' => now()->subMinutes(12),
        ]);

        $this->artisan('meters:scan-health'); // opens telemetry_down
        $this->assertDatabaseHas('alert_events', ['device_id' => $device->id, 'status' => 'open']);

        \App\Models\MeterAlertSetting::create(['device_id' => $device->id, 'offline_enabled' => false]);
        $this->artisan('meters:scan-health');

        $this->assertDatabaseMissing('alert_events', ['device_id' => $device->id, 'status' => 'open']);
    }

    private function createMeter(array $attributes = []): Device
    {
        return Device::create(array_merge([
            'code' => 'meter-'.fake()->unique()->slug(),
            'name' => 'Meter '.fake()->unique()->word(),
            'type' => 'meter',
            'mqtt_topic' => 'meters/'.fake()->unique()->slug(),
            'is_active' => true,
        ], $attributes));
    }
}
