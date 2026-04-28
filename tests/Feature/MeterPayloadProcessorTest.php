<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\LatestMeterState;
use App\Services\Meters\MeterPayloadProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;

#[RequiresPhpExtension('pdo_sqlite')]
class MeterPayloadProcessorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-04-21 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_invalid_json_marks_a_payload_issue_without_storing_telemetry(): void
    {
        $device = $this->createMeter();

        $result = app(MeterPayloadProcessor::class)->process(
            $device->mqtt_topic,
            '{"ts":1776753600',
        );

        $device->refresh();

        $this->assertSame('payload_issue', $result->status);
        $this->assertSame('invalid_json', $device->last_error_code);
        $this->assertSame('Payload error: MQTT message is not valid JSON.', $device->last_error_message);
        $this->assertTrue($device->hasActiveIssue());
        $this->assertSame('2026-04-21 12:00:00', $device->last_message_at?->toDateTimeString());
        $this->assertNull($device->last_seen_at);
        $this->assertDatabaseCount('meter_readings', 0);
        $this->assertDatabaseHas('meter_ingestion_events', [
            'device_id' => $device->id,
            'topic' => $device->mqtt_topic,
            'status' => 'invalid_json',
            'error_code' => 'invalid_json',
        ]);
    }

    public function test_missing_ts_marks_a_payload_issue_without_storing_telemetry(): void
    {
        $device = $this->createMeter();

        $result = app(MeterPayloadProcessor::class)->process(
            $device->mqtt_topic,
            json_encode([
                'voltage' => 220.4,
                'current' => 0.22,
            ], JSON_THROW_ON_ERROR),
        );

        $device->refresh();

        $this->assertSame('payload_issue', $result->status);
        $this->assertSame('missing_ts', $device->last_error_code);
        $this->assertTrue($device->hasActiveIssue());
        $this->assertDatabaseCount('meter_readings', 0);
        $this->assertDatabaseHas('meter_ingestion_events', [
            'device_id' => $device->id,
            'topic' => $device->mqtt_topic,
            'status' => 'payload_invalid',
            'error_code' => 'missing_ts',
        ]);
    }

    public function test_unknown_topic_is_audited_without_storing_telemetry(): void
    {
        $result = app(MeterPayloadProcessor::class)->process(
            'meters/not-registered',
            json_encode([
                'ts' => 1776753600,
                'power' => 21.5,
            ], JSON_THROW_ON_ERROR),
        );

        $this->assertSame('ignored_unknown_topic', $result->status);
        $this->assertDatabaseCount('meter_readings', 0);
        $this->assertDatabaseHas('meter_ingestion_events', [
            'device_id' => null,
            'topic' => 'meters/not-registered',
            'status' => 'unknown_topic',
        ]);
    }

    public function test_valid_payload_recovers_device_and_stores_telemetry(): void
    {
        $device = $this->createMeter([
            'last_message_at' => now()->subMinute(),
            'last_error_code' => 'missing_ts',
            'last_error_message' => 'Payload error: missing required `ts` timestamp.',
            'last_error_context' => ['topic' => 'meters/recovery'],
            'last_error_at' => now()->subMinute(),
        ]);

        $result = app(MeterPayloadProcessor::class)->process(
            $device->mqtt_topic,
            json_encode([
                'ts' => 1776753600,
                'voltage' => 220.4,
                'current' => 0.22,
                'power' => 21.5,
            ], JSON_THROW_ON_ERROR),
        );

        $device->refresh();

        $this->assertTrue($result->wasStored());
        $this->assertFalse($device->hasActiveIssue());
        $this->assertSame('2026-04-21 12:00:00', $device->last_seen_at?->toDateTimeString());
        $this->assertSame('2026-04-21 12:00:00', $device->last_message_at?->toDateTimeString());
        $this->assertSame('2026-04-21 12:00:00', $device->last_recovered_at?->toDateTimeString());
        $this->assertSame('missing_ts', $device->last_error_code);

        $this->assertDatabaseHas('meter_readings', [
            'device_id' => $device->id,
            'ts' => 1776753600,
            'received_at' => '2026-04-21 12:00:00',
        ]);

        $this->assertDatabaseHas('latest_meter_states', [
            'device_id' => $device->id,
            'ts' => 1776753600,
            'received_at' => '2026-04-21 12:00:00',
        ]);

        $this->assertDatabaseHas('meter_ingestion_events', [
            'device_id' => $device->id,
            'topic' => $device->mqtt_topic,
            'status' => 'stored',
        ]);
    }

    public function test_duplicate_payload_is_audited_without_creating_extra_history(): void
    {
        $device = $this->createMeter();
        $message = json_encode([
            'ts' => 1776753600,
            'voltage' => 220.4,
            'current' => 0.22,
            'power' => 21.5,
        ], JSON_THROW_ON_ERROR);

        app(MeterPayloadProcessor::class)->process($device->mqtt_topic, $message);
        $result = app(MeterPayloadProcessor::class)->process($device->mqtt_topic, $message);

        $this->assertTrue($result->wasStored());
        $this->assertDatabaseCount('meter_readings', 1);
        $this->assertDatabaseHas('meter_ingestion_events', [
            'device_id' => $device->id,
            'topic' => $device->mqtt_topic,
            'status' => 'duplicate',
        ]);
    }

    public function test_out_of_order_payload_is_stored_without_replacing_latest_state(): void
    {
        $device = $this->createMeter();

        LatestMeterState::create([
            'device_id' => $device->id,
            'ts' => 1776753600,
            'voltage' => 230.0,
            'current' => 1.2,
            'power' => 276.0,
            'energy_computed_wh' => 500.0,
            'energy_pzem_wh' => 501,
            'frequency' => 50.0,
            'pf' => 0.95,
            'received_at' => now()->subMinute(),
        ]);

        $result = app(MeterPayloadProcessor::class)->process(
            $device->mqtt_topic,
            json_encode([
                'ts' => 1776750000,
                'voltage' => 210.0,
                'current' => 0.8,
                'power' => 168.0,
            ], JSON_THROW_ON_ERROR),
        );

        $latestState = LatestMeterState::where('device_id', $device->id)->first();

        $this->assertTrue($result->wasStored());
        $this->assertFalse($result->latestStateUpdated);

        $this->assertDatabaseHas('meter_readings', [
            'device_id' => $device->id,
            'ts' => 1776750000,
            'received_at' => '2026-04-21 12:00:00',
        ]);

        $this->assertSame(1776753600, $latestState->ts);
        $this->assertEquals(276.0, (float) $latestState->power);
        $this->assertDatabaseHas('meter_ingestion_events', [
            'device_id' => $device->id,
            'topic' => $device->mqtt_topic,
            'status' => 'out_of_order',
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
