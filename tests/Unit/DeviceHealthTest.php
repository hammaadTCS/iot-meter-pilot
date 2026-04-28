<?php

namespace Tests\Unit;

use App\Models\Device;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DeviceHealthTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_enabled_device_without_telemetry_is_reported_as_never_seen(): void
    {
        Carbon::setTestNow('2026-04-21 12:00:00');

        $device = new Device();
        $device->forceFill([
            'code' => 'meter-never-seen',
            'name' => 'Never Seen Meter',
            'type' => 'meter',
            'mqtt_topic' => 'meters/never-seen',
            'is_active' => true,
            'last_seen_at' => null,
        ]);

        $snapshot = $device->healthSnapshot();

        $this->assertSame('never_seen', $snapshot['status']);
        $this->assertSame('Never Seen', $snapshot['label']);
        $this->assertSame('No telemetry has been received from this meter yet.', $snapshot['message']);
        $this->assertNull($snapshot['seconds_since_last_seen']);
    }

    public function test_health_snapshot_transitions_from_online_to_stale_to_down(): void
    {
        Carbon::setTestNow('2026-04-21 12:00:00');

        $device = new Device();
        $device->forceFill([
            'code' => 'meter-health',
            'name' => 'Health Meter',
            'type' => 'meter',
            'mqtt_topic' => 'meters/health',
            'is_active' => true,
        ]);

        $device->last_seen_at = now()->subSeconds(45);
        $onlineSnapshot = $device->healthSnapshot();

        $this->assertSame('online', $onlineSnapshot['status']);
        $this->assertSame('Online', $onlineSnapshot['label']);
        $this->assertSame('Meter is live. Telemetry was received 45s ago.', $onlineSnapshot['message']);

        $device->last_seen_at = now()->subMinutes(4);
        $staleSnapshot = $device->healthSnapshot();

        $this->assertSame('stale', $staleSnapshot['status']);
        $this->assertSame('Stale', $staleSnapshot['label']);
        $this->assertSame('Telemetry is delayed. Last reading was 4m ago.', $staleSnapshot['message']);

        $device->last_seen_at = now()->subMinutes(12);
        $downSnapshot = $device->healthSnapshot();

        $this->assertSame('down', $downSnapshot['status']);
        $this->assertSame('Down', $downSnapshot['label']);
        $this->assertSame('Meter appears down. No telemetry has been received for 12m.', $downSnapshot['message']);
    }

    public function test_disabled_device_is_reported_as_disabled_even_without_telemetry(): void
    {
        Carbon::setTestNow('2026-04-21 12:00:00');

        $device = new Device();
        $device->forceFill([
            'code' => 'meter-disabled',
            'name' => 'Disabled Meter',
            'type' => 'meter',
            'mqtt_topic' => 'meters/disabled',
            'is_active' => false,
            'last_seen_at' => null,
        ]);

        $snapshot = $device->healthSnapshot();

        $this->assertSame('disabled', $snapshot['status']);
        $this->assertSame('Disabled', $snapshot['label']);
        $this->assertSame('Monitoring is disabled for this meter.', $snapshot['message']);
    }

    public function test_recovered_issue_is_only_shown_for_a_fresh_immediate_recovery(): void
    {
        Carbon::setTestNow('2026-04-21 12:00:00');

        $device = new Device();
        $device->forceFill([
            'code' => 'meter-recovery',
            'name' => 'Recovery Meter',
            'type' => 'meter',
            'mqtt_topic' => 'meters/recovery',
            'is_active' => true,
            'last_error_code' => 'missing_ts',
            'last_error_message' => 'Payload error: missing required `ts` timestamp.',
            'last_error_at' => now()->subMinutes(2),
            'last_recovered_at' => now()->subSeconds(40),
            'last_seen_at' => now()->subSeconds(40),
        ]);

        $recoveredSnapshot = $device->issueSnapshot();

        $this->assertSame('recovered', $recoveredSnapshot['status']);
        $this->assertSame('Recovered', $recoveredSnapshot['label']);

        $device->last_seen_at = now()->subSeconds(10);
        $stillOnlineSnapshot = $device->issueSnapshot();

        $this->assertSame('ok', $stillOnlineSnapshot['status']);
        $this->assertSame('No Issue', $stillOnlineSnapshot['label']);

        $device->last_error_at = now()->subMinutes(15);
        $device->last_seen_at = now()->subMinutes(12);
        $device->last_recovered_at = now()->subMinutes(12);
        $downSnapshot = $device->issueSnapshot();

        $this->assertSame('ok', $downSnapshot['status']);
        $this->assertSame('No Issue', $downSnapshot['label']);
    }
}
