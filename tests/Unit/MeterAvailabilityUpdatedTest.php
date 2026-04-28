<?php

namespace Tests\Unit;

use App\Events\MeterAvailabilityUpdated;
use App\Models\Device;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MeterAvailabilityUpdatedTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_broadcast_payload_contains_dashboard_ready_availability_fields(): void
    {
        Carbon::setTestNow('2026-04-21 15:12:00');

        $device = new Device();
        $device->setAttribute('id', 12);
        $device->setAttribute('code', 'meter-availability');
        $device->setAttribute('name', 'Availability Meter');
        $device->setAttribute('mqtt_topic', 'meters/availability/data');
        $device->setAttribute('availability_topic', 'meters/availability/status');
        $device->setAttribute('is_active', true);
        $device->setAttribute('last_seen_at', Carbon::parse('2026-04-21 14:59:40'));
        $device->setAttribute('last_availability_status', 'offline');
        $device->setAttribute('last_availability_message', 'MQTT availability reported this meter offline.');
        $device->setAttribute('last_availability_at', Carbon::parse('2026-04-21 14:59:55'));

        $payload = (new MeterAvailabilityUpdated($device))->broadcastWith();

        $this->assertSame(12, $payload['device_id']);
        $this->assertSame('meter-availability', $payload['device_code']);
        $this->assertSame('offline', $payload['availability']['status']);
        $this->assertSame('meters/availability/status', $payload['availability']['topic']);
        $this->assertSame('down', $payload['health']['status']);
    }
}
