<?php

namespace Tests\Unit;

use App\Events\MeterReadingUpdated;
use App\Models\Device;
use App\Models\MeterReading;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MeterReadingUpdatedTest extends TestCase
{
    public function test_broadcast_payload_contains_dashboard_ready_reading_fields(): void
    {
        $lastSeenAt = Carbon::parse('2026-04-14 09:30:00');
        $createdAt = Carbon::parse('2026-04-14 09:29:45');
        $receivedAt = Carbon::parse('2026-04-14 09:29:58');

        $device = new Device;
        $device->setAttribute('id', 7);
        $device->setAttribute('code', 'meter-001');
        $device->setAttribute('name', 'Pilot Meter');
        $device->setAttribute('last_seen_at', $lastSeenAt);

        $reading = new MeterReading;
        $reading->setAttribute('id', 42);
        $reading->setAttribute('ts', 1776158985);
        $reading->setAttribute('voltage', '236.80');
        $reading->setAttribute('current', '1.230');
        $reading->setAttribute('power', '291.20');
        $reading->setAttribute('energy_computed_wh', '15.500');
        $reading->setAttribute('energy_pzem_wh', 16);
        $reading->setAttribute('frequency', '49.90');
        $reading->setAttribute('pf', '0.95');
        $reading->setAttribute('created_at', $createdAt);
        $reading->setAttribute('received_at', $receivedAt);

        $payload = (new MeterReadingUpdated($device, $reading))->broadcastWith();

        $this->assertSame(7, $payload['device_id']);
        $this->assertSame('meter-001', $payload['device_code']);
        $this->assertTrue($payload['latest_state_updated']);
        $this->assertSame(42, $payload['reading']['id']);
        $this->assertSame(1776158985, $payload['reading']['ts']);
        $this->assertSame($createdAt->timestamp, Carbon::parse($payload['reading']['created_at'])->timestamp);
        $this->assertSame($lastSeenAt->timestamp, Carbon::parse($payload['last_seen_at'])->timestamp);
        $this->assertSame($receivedAt->timestamp, Carbon::parse($payload['reading']['received_at'])->timestamp);
    }

    public function test_broadcast_payload_can_mark_reading_as_historical_only(): void
    {
        $device = new Device;
        $device->setAttribute('id', 7);
        $device->setAttribute('code', 'meter-001');
        $device->setAttribute('name', 'Pilot Meter');

        $reading = new MeterReading;
        $reading->setAttribute('id', 42);
        $reading->setAttribute('ts', 1776158985);

        $payload = (new MeterReadingUpdated($device, $reading, latestStateUpdated: false))->broadcastWith();

        $this->assertFalse($payload['latest_state_updated']);
    }
}
