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
 * GET /api/devices/{device}/consumption/daily — the Daily Breakdown report:
 * per-day units + the monthly total, read from the pre-aggregated rollups.
 * Covers the JSON shape, the CSV export, month validation, and authorization.
 */
#[RequiresPhpExtension('pdo_sqlite')]
class DailyConsumptionReportTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-06-30 12:00:00');
        $this->user = User::factory()->consumer()->create();
        $this->actingAs($this->user, 'sanctum');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Ingest a month of readings across three days (the live hook fills the rollups). */
    private function seedMonth(Device $device): void
    {
        Carbon::setTestNow('2026-06-10 10:00:00');
        $this->send($device, ts: 1000, energy: 1000);
        Carbon::setTestNow('2026-06-10 20:00:00');
        $this->send($device, ts: 1100, energy: 1300); // day 10 → 0.3

        Carbon::setTestNow('2026-06-20 10:00:00');
        $this->send($device, ts: 1200, energy: 1800); // day 20 → 0.5

        Carbon::setTestNow('2026-06-30 10:00:00');
        $this->send($device, ts: 1300, energy: 2100); // day 30 → 0.3
    }

    public function test_returns_daily_breakdown_and_monthly_total_for_owner(): void
    {
        $device = $this->createMeter($this->user);
        $this->seedMonth($device);

        // No ?month → defaults to the current month (2026-06).
        $this->getJson("/api/devices/{$device->id}/consumption/daily")
            ->assertOk()
            ->assertJsonPath('month', '2026-06')
            ->assertJsonPath('total_units_kwh', 1.1)        // monthly total = 2100-1000
            ->assertJsonCount(3, 'days')
            ->assertJsonPath('days.0.date', '2026-06-10')
            ->assertJsonPath('days.0.units_kwh', 0.3)
            ->assertJsonPath('days.1.units_kwh', 0.5)
            ->assertJsonPath('days.2.units_kwh', 0.3);      // daily rows sum to the monthly total
    }

    public function test_csv_export_lists_daily_rows_and_a_total(): void
    {
        $device = $this->createMeter($this->user);
        $this->seedMonth($device);

        $response = $this->get("/api/devices/{$device->id}/consumption/daily?month=2026-06&format=csv");

        $response->assertOk();
        $content = $response->streamedContent();
        $this->assertStringContainsString('date,units_kwh', $content);
        $this->assertStringContainsString('2026-06-10', $content);
        $this->assertStringContainsString('TOTAL 2026-06', $content);
    }

    public function test_invalid_month_returns_422(): void
    {
        $device = $this->createMeter($this->user);

        $this->getJson("/api/devices/{$device->id}/consumption/daily?month=2026-13")
            ->assertStatus(422);
    }

    public function test_forbids_a_non_owner(): void
    {
        $device = $this->createMeter(User::factory()->consumer()->create());

        $this->getJson("/api/devices/{$device->id}/consumption/daily")
            ->assertForbidden();
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
