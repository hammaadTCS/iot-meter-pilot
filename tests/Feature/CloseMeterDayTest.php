<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\MeterDailyConsumption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;

/**
 * Verifies meters:close-day finalises day rows whose day has already ended while
 * leaving the current day open — the safety net for devices that go silent
 * across a day boundary. Isolated test DB only.
 */
#[RequiresPhpExtension('pdo_sqlite')]
class CloseMeterDayTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_finalises_open_past_day_and_leaves_current_open(): void
    {
        Carbon::setTestNow('2026-06-30 00:10:00');
        $device = $this->createMeter();

        // A past day left open because the device went silent across midnight.
        $past = $this->openRow($device, '2026-06-28', 1000, 1500, 0.5);
        // The current day, legitimately still open.
        $today = $this->openRow($device, '2026-06-30', 1500, 1700, 0.2);

        $this->artisan('meters:close-day')->assertSuccessful();

        $this->assertNotNull($past->fresh()->finalized_at, 'ended day should be finalised');
        $this->assertNull($today->fresh()->finalized_at, 'current day stays open');
    }

    public function test_is_idempotent_and_does_not_reopen_finalised_rows(): void
    {
        Carbon::setTestNow('2026-06-30 00:10:00');
        $device = $this->createMeter();

        $past = $this->openRow($device, '2026-06-27', 1000, 1200, 0.2);

        $this->artisan('meters:close-day')->assertSuccessful();
        $firstFinalisedAt = $past->fresh()->finalized_at;

        // Re-running must not change an already-finalised row's timestamp.
        Carbon::setTestNow('2026-06-30 06:00:00');
        $this->artisan('meters:close-day')->assertSuccessful();

        $this->assertEquals($firstFinalisedAt, $past->fresh()->finalized_at);
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    private function openRow(Device $device, string $date, int $baseline, int $last, float $units): MeterDailyConsumption
    {
        return MeterDailyConsumption::create([
            'device_id'          => $device->id,
            'period_date'        => $date,
            'baseline_energy_wh' => $baseline,
            'last_energy_wh'     => $last,
            'rollover_wh'        => 0,
            'units_kwh'          => $units,
            'finalized_at'       => null,
        ]);
    }

    private function createMeter(): Device
    {
        return Device::create([
            'code'       => 'meter-'.fake()->unique()->slug(),
            'name'       => 'Meter '.fake()->unique()->word(),
            'type'       => 'meter',
            'mqtt_topic' => 'meters/'.fake()->unique()->slug(),
            'is_active'  => true,
        ]);
    }
}
