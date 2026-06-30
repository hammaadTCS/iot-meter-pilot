<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\User;
use App\Services\Meters\MeterPayloadProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;

/**
 * HTTP-layer tests for GET /api/devices/{device}/readings/consumption:
 * authorization (owner vs non-owner), window validation, and that it returns
 * the figure produced by RangeConsumption. The consumption maths itself is
 * exhaustively covered by RangeConsumptionTest.
 */
#[RequiresPhpExtension('pdo_sqlite')]
class DeviceReadingConsumptionTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-06-30 12:00:00');
        $this->user = User::factory()->create();
        $this->actingAs($this->user, 'sanctum');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_returns_units_for_the_owner(): void
    {
        $device = $this->createMeter($this->user);

        $this->send($device, ts: 1000, energy: 1000);
        $this->send($device, ts: 1100, energy: 1500); // +500 Wh within the last hour

        $response = $this->getJson("/api/devices/{$device->id}/readings/consumption?range=1h");

        $response->assertOk()
            ->assertJson([
                'units_kwh'     => 0.5,
                'reading_count' => 2,
            ]);
    }

    public function test_forbids_a_non_owner(): void
    {
        $owner = User::factory()->create();
        $device = $this->createMeter($owner); // owned by someone else

        $this->getJson("/api/devices/{$device->id}/readings/consumption?range=1h")
            ->assertForbidden();
    }

    public function test_invalid_custom_range_returns_422(): void
    {
        $device = $this->createMeter($this->user);

        // to is before from → invalid.
        $this->getJson("/api/devices/{$device->id}/readings/consumption?from=2026-06-10T00:00:00&to=2026-06-09T00:00:00")
            ->assertStatus(422);
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    private function send(Device $device, int $ts, int $energy)
    {
        return app(MeterPayloadProcessor::class)->process(
            $device->mqtt_topic,
            json_encode([
                'ts' => $ts,
                'voltage' => 230.0,
                'power' => 100.0,
                'energy_pzem_wh' => $energy,
            ], JSON_THROW_ON_ERROR),
        );
    }

    private function createMeter(User $owner): Device
    {
        return Device::create([
            'code'       => 'meter-'.fake()->unique()->slug(),
            'name'       => 'Meter '.fake()->unique()->word(),
            'type'       => 'meter',
            'mqtt_topic' => 'meters/'.fake()->unique()->slug(),
            'is_active'  => true,
            'user_id'    => $owner->id,
        ]);
    }
}
