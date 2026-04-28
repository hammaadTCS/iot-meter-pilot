<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Services\Meters\MeterAvailabilityProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;

#[RequiresPhpExtension('pdo_sqlite')]
class MeterAvailabilityProcessorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-04-21 15:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_offline_payload_marks_the_device_offline(): void
    {
        $device = $this->createMeter();

        $result = app(MeterAvailabilityProcessor::class)->process(
            $device->availability_topic,
            'offline',
        );

        $device->refresh();

        $this->assertTrue($result->wasStored());
        $this->assertSame('offline', $device->last_availability_status);
        $this->assertSame('2026-04-21 15:00:00', $device->last_availability_at?->toDateTimeString());
    }

    public function test_heartbeat_payload_updates_the_last_heartbeat_time(): void
    {
        $device = $this->createMeter();

        $result = app(MeterAvailabilityProcessor::class)->process(
            $device->availability_topic,
            json_encode([
                'status' => 'heartbeat',
                'message' => 'still alive',
            ], JSON_THROW_ON_ERROR),
        );

        $device->refresh();

        $this->assertTrue($result->wasStored());
        $this->assertSame('heartbeat', $device->last_availability_status);
        $this->assertSame('still alive', $device->last_availability_message);
        $this->assertSame('2026-04-21 15:00:00', $device->last_heartbeat_at?->toDateTimeString());
    }

    public function test_unknown_availability_payload_is_stored_as_unknown(): void
    {
        $device = $this->createMeter();

        $result = app(MeterAvailabilityProcessor::class)->process(
            $device->availability_topic,
            'mystery-state',
        );

        $device->refresh();

        $this->assertTrue($result->wasStored());
        $this->assertSame('unknown', $device->last_availability_status);
        $this->assertSame(
            'MQTT availability payload received, but the status could not be classified.',
            $device->last_availability_message,
        );
    }

    private function createMeter(array $attributes = []): Device
    {
        $mqttTopic = 'meters/'.fake()->unique()->slug().'/data';

        return Device::create(array_merge([
            'code' => 'meter-'.fake()->unique()->slug(),
            'name' => 'Meter '.fake()->unique()->word(),
            'type' => 'meter',
            'mqtt_topic' => $mqttTopic,
            'availability_topic' => Device::deriveAvailabilityTopic($mqttTopic),
            'is_active' => true,
        ], $attributes));
    }
}
