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

        $this->assertDatabaseHas('meter_alert_events', [
            'device_id' => $device->id,
            'alert_type' => 'telemetry_stale',
            'severity' => 'warning',
            'status' => 'open',
        ]);

        $this->artisan('meters:scan-health')
            ->assertExitCode(0);

        $this->assertDatabaseCount('meter_alert_events', 1);

        $device->forceFill(['last_seen_at' => now()])->save();

        $this->artisan('meters:scan-health')
            ->assertExitCode(0);

        $this->assertDatabaseHas('meter_alert_events', [
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

        $this->assertDatabaseHas('meter_alert_events', [
            'device_id' => $device->id,
            'alert_type' => 'telemetry_stale',
            'status' => 'resolved',
        ]);

        $this->assertDatabaseHas('meter_alert_events', [
            'device_id' => $device->id,
            'alert_type' => 'telemetry_down',
            'severity' => 'critical',
            'status' => 'open',
        ]);
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
