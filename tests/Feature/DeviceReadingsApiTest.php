<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;

#[RequiresPhpExtension('pdo_sqlite')]
class DeviceReadingsApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-04-21 12:00:00');
        $this->user = User::factory()->create();
        $this->actingAs($this->user, 'sanctum');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_readings_endpoint_filters_by_receive_time_with_legacy_fallback(): void
    {
        $device = $this->createMeter();

        $legacyReadingId = $this->insertReading($device->id, [
            'ts' => 1776752100,
            'created_at' => '2026-04-21 11:35:00',
            'updated_at' => '2026-04-21 11:35:00',
            'received_at' => null,
            'power' => 11.10,
        ]);

        $recentReceiveId = $this->insertReading($device->id, [
            'ts' => 1776000000,
            'created_at' => '2026-04-10 09:00:00',
            'updated_at' => '2026-04-21 11:40:00',
            'received_at' => '2026-04-21 11:40:00',
            'power' => 22.20,
        ]);

        $this->insertReading($device->id, [
            'ts' => 1775900000,
            'created_at' => '2026-04-09 09:00:00',
            'updated_at' => '2026-04-09 09:00:00',
            'received_at' => '2026-04-09 09:00:00',
            'power' => 33.30,
        ]);

        $response = $this->getJson("/api/devices/{$device->id}/readings?range=1h");

        $response->assertOk();

        $rows = $response->json();

        $this->assertCount(2, $rows);
        $this->assertSame($legacyReadingId, $rows[0]['id']);
        $this->assertSame('2026-04-21 11:35:00', $rows[0]['created_at']);
        $this->assertSame($recentReceiveId, $rows[1]['id']);
        $this->assertSame('2026-04-21 11:40:00', $rows[1]['created_at']);
    }

    public function test_readings_endpoint_incremental_cursor_uses_receive_time_not_only_row_id(): void
    {
        $device = $this->createMeter();

        $recentReceiveId = $this->insertReading($device->id, [
            'ts' => 1776001234,
            'created_at' => '2026-04-10 09:00:00',
            'updated_at' => '2026-04-21 11:58:00',
            'received_at' => '2026-04-21 11:58:00',
            'power' => 44.40,
        ]);

        $response = $this->getJson(
            "/api/devices/{$device->id}/readings?range=1h&after_received_at=2026-04-21%2011:50:00&after_id=999"
        );

        $response->assertOk();

        $rows = $response->json();

        $this->assertCount(1, $rows);
        $this->assertSame($recentReceiveId, $rows[0]['id']);
        $this->assertSame('2026-04-21 11:58:00', $rows[0]['created_at']);
    }

    private function createMeter(): Device
    {
        return Device::create([
            'code' => 'meter-'.fake()->unique()->slug(),
            'name' => 'Meter '.fake()->unique()->word(),
            'type' => 'meter',
            'mqtt_topic' => 'meters/'.fake()->unique()->slug(),
            'is_active' => true,
            'user_id' => $this->user->id,
        ]);
    }

    private function insertReading(int $deviceId, array $overrides): int
    {
        return (int) DB::table('meter_readings')->insertGetId(array_merge([
            'device_id' => $deviceId,
            'ts' => 1776750000,
            'received_at' => '2026-04-21 11:30:00',
            'voltage' => 220.10,
            'current' => 0.123,
            'power' => 10.10,
            'energy_computed_wh' => 100.100,
            'energy_pzem_wh' => 101,
            'frequency' => 49.90,
            'pf' => 0.91,
            'raw_payload' => json_encode(['source' => 'test'], JSON_THROW_ON_ERROR),
            'created_at' => '2026-04-21 11:30:00',
            'updated_at' => '2026-04-21 11:30:00',
        ], $overrides));
    }
}
