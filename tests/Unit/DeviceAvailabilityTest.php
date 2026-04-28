<?php

namespace Tests\Unit;

use App\Models\Device;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DeviceAvailabilityTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_explicit_offline_signal_beats_older_telemetry(): void
    {
        Carbon::setTestNow('2026-04-21 15:00:00');

        $device = new Device();
        $device->forceFill([
            'code' => 'meter-offline',
            'name' => 'Offline Meter',
            'type' => 'meter',
            'mqtt_topic' => 'meters/offline/data',
            'availability_topic' => 'meters/offline/status',
            'is_active' => true,
            'last_seen_at' => now()->subMinutes(2),
            'last_availability_status' => 'offline',
            'last_availability_message' => 'MQTT availability reported this meter offline.',
            'last_availability_at' => now()->subMinute(),
        ]);

        $snapshot = $device->availabilitySnapshot();

        $this->assertSame('offline', $snapshot['status']);
        $this->assertSame('Offline', $snapshot['label']);
    }

    public function test_online_availability_becomes_silent_when_telemetry_is_down(): void
    {
        Carbon::setTestNow('2026-04-21 15:00:00');

        $device = new Device();
        $device->forceFill([
            'code' => 'meter-silent',
            'name' => 'Silent Meter',
            'type' => 'meter',
            'mqtt_topic' => 'meters/silent/data',
            'availability_topic' => 'meters/silent/status',
            'is_active' => true,
            'last_seen_at' => now()->subMinutes(12),
            'last_availability_status' => 'online',
            'last_availability_message' => 'MQTT availability reports this meter online.',
            'last_availability_at' => now()->subMinute(),
            'last_heartbeat_at' => now()->subMinute(),
        ]);

        $snapshot = $device->availabilitySnapshot();

        $this->assertSame('silent', $snapshot['status']);
        $this->assertSame('Silent', $snapshot['label']);
    }

    public function test_newer_telemetry_supersedes_a_stale_offline_signal(): void
    {
        Carbon::setTestNow('2026-04-21 15:00:00');

        $device = new Device();
        $device->forceFill([
            'code' => 'meter-back-online',
            'name' => 'Back Online Meter',
            'type' => 'meter',
            'mqtt_topic' => 'meters/back-online/data',
            'availability_topic' => 'meters/back-online/status',
            'is_active' => true,
            'last_seen_at' => now()->subSeconds(20),
            'last_availability_status' => 'offline',
            'last_availability_message' => 'MQTT availability reported this meter offline.',
            'last_availability_at' => now()->subMinute(),
        ]);

        $snapshot = $device->availabilitySnapshot();

        $this->assertSame('online', $snapshot['status']);
        $this->assertSame('Online', $snapshot['label']);
    }
}
