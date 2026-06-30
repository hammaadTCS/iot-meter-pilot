<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\MeterMonthlyConsumption;
use App\Services\Meters\MeterPayloadProcessor;
use App\Services\Meters\RangeConsumption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;

/**
 * Exercises RangeConsumption::unitsForWindow() — the single source of truth for
 * "units consumed over a window" — directly against readings ingested through
 * the real MeterPayloadProcessor.
 *
 * The headline test is reconciliation: for a device's first month, the service
 * must return exactly the figure the incremental monthly aggregate stored, so
 * the Range Units KPI, exports, and reports can never disagree with the
 * Monthly Units card.
 */
#[RequiresPhpExtension('pdo_sqlite')]
class RangeConsumptionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_empty_window_returns_zero(): void
    {
        Carbon::setTestNow('2026-06-05 10:00:00');
        $device = $this->createMeter();
        $this->send($device, ts: 1000, energy: 10000);

        // Window entirely after the only reading.
        $result = RangeConsumption::unitsForWindow(
            $device->id,
            Carbon::parse('2026-06-06 00:00:00'),
            Carbon::parse('2026-06-07 00:00:00'),
        );

        $this->assertEquals(0.0, $result['units_kwh']);
        $this->assertSame(0, $result['reading_count']);
    }

    public function test_single_reading_returns_zero(): void
    {
        Carbon::setTestNow('2026-06-05 10:00:00');
        $device = $this->createMeter();
        $this->send($device, ts: 1000, energy: 10000);

        $result = RangeConsumption::unitsForWindow($device->id, Carbon::parse('2026-06-01'), null);

        $this->assertEquals(0.0, $result['units_kwh']);
        $this->assertSame(1, $result['reading_count']);
    }

    public function test_monotonic_increase_yields_last_minus_first(): void
    {
        $device = $this->createMeter();

        Carbon::setTestNow('2026-06-05 10:00:00');
        $this->send($device, ts: 1000, energy: 10000);
        Carbon::setTestNow('2026-06-05 10:30:00');
        $this->send($device, ts: 1100, energy: 10500);
        Carbon::setTestNow('2026-06-05 11:00:00');
        $this->send($device, ts: 1200, energy: 11000);

        $result = RangeConsumption::unitsForWindow($device->id, Carbon::parse('2026-06-01'), null);

        // (11000 - 10000) / 1000 = 1.0
        $this->assertEquals(1.0, $result['units_kwh']);
        $this->assertSame(3, $result['reading_count']);
    }

    public function test_window_end_excludes_later_readings(): void
    {
        $device = $this->createMeter();

        Carbon::setTestNow('2026-06-05 10:00:00');
        $this->send($device, ts: 1000, energy: 10000);
        Carbon::setTestNow('2026-06-05 11:00:00');
        $this->send($device, ts: 1100, energy: 10500);
        Carbon::setTestNow('2026-06-05 12:00:00');
        $this->send($device, ts: 1200, energy: 11000);

        // Window 10:00–11:30 includes only the first two readings → 0.5 kWh.
        $result = RangeConsumption::unitsForWindow(
            $device->id,
            Carbon::parse('2026-06-05 10:00:00'),
            Carbon::parse('2026-06-05 11:30:00'),
        );

        $this->assertEquals(0.5, $result['units_kwh']);
        $this->assertSame(2, $result['reading_count']);
    }

    public function test_mid_window_reset_is_absorbed_and_never_negative(): void
    {
        $device = $this->createMeter();

        Carbon::setTestNow('2026-06-05 10:00:00');
        $this->send($device, ts: 1000, energy: 10000);
        Carbon::setTestNow('2026-06-05 10:30:00');
        $this->send($device, ts: 1100, energy: 10500);
        Carbon::setTestNow('2026-06-05 11:00:00');
        $this->send($device, ts: 1200, energy: 300); // reset! banks 10500

        $result = RangeConsumption::unitsForWindow($device->id, Carbon::parse('2026-06-01'), null);

        // (300 - 10000 + 10500) / 1000 = 0.8
        $this->assertEquals(0.8, $result['units_kwh']);
        $this->assertGreaterThanOrEqual(0, $result['units_kwh']);
        $this->assertSame(3, $result['reading_count']);
    }

    public function test_null_energy_rows_are_ignored(): void
    {
        $device = $this->createMeter();

        Carbon::setTestNow('2026-06-05 10:00:00');
        $this->send($device, ts: 1000, energy: 10000);

        // A valid reading carrying no PZEM energy — must not count, must not break the walk.
        Carbon::setTestNow('2026-06-05 10:30:00');
        app(MeterPayloadProcessor::class)->process(
            $device->mqtt_topic,
            json_encode(['ts' => 1050, 'voltage' => 230.0], JSON_THROW_ON_ERROR),
        );

        Carbon::setTestNow('2026-06-05 11:00:00');
        $this->send($device, ts: 1100, energy: 10800);

        $result = RangeConsumption::unitsForWindow($device->id, Carbon::parse('2026-06-01'), null);

        $this->assertEquals(0.8, $result['units_kwh']);
        $this->assertSame(2, $result['reading_count']); // the null-energy row is excluded
    }

    public function test_reconciles_with_monthly_aggregate_for_first_month(): void
    {
        $device = $this->createMeter();

        Carbon::setTestNow('2026-06-05 10:00:00');
        $this->send($device, ts: 1000, energy: 10000);
        Carbon::setTestNow('2026-06-10 10:00:00');
        $this->send($device, ts: 1100, energy: 10500);
        Carbon::setTestNow('2026-06-20 10:00:00');
        $this->send($device, ts: 1200, energy: 11000);

        $monthly = MeterMonthlyConsumption::where('device_id', $device->id)
            ->whereDate('period_start', '2026-06-01')
            ->first();

        $result = RangeConsumption::unitsForWindow(
            $device->id,
            Carbon::parse('2026-06-01 00:00:00'),
            Carbon::parse('2026-06-30 23:59:59'),
        );

        // For a device's first month the self-contained baseline (= first reading
        // in the window) equals the monthly aggregate's baseline, so they match exactly.
        $this->assertEquals(1.0, (float) $monthly->units_kwh);
        $this->assertEquals((float) $monthly->units_kwh, $result['units_kwh']);
        $this->assertSame(3, $result['reading_count']);
    }

    // ── tiered path (multi-day windows that exercise the daily rollup) ─────────

    public function test_multi_day_window_uses_interior_buckets_and_matches_self_contained(): void
    {
        $device = $this->createMeter();

        Carbon::setTestNow('2026-06-27 08:00:00');
        $this->send($device, ts: 1000, energy: 1000);
        Carbon::setTestNow('2026-06-27 20:00:00');
        $this->send($device, ts: 1100, energy: 1300);

        Carbon::setTestNow('2026-06-28 10:00:00');
        $this->send($device, ts: 1200, energy: 1600);

        Carbon::setTestNow('2026-06-29 10:00:00');
        $this->send($device, ts: 1300, energy: 2000);

        Carbon::setTestNow('2026-06-30 09:00:00');
        $this->send($device, ts: 1400, energy: 2200);
        Carbon::setTestNow('2026-06-30 15:00:00');
        $this->send($device, ts: 1500, energy: 2500);

        // Window starts mid day-1 (after 08:00) and ends mid day-4 (before 15:00).
        // In-window readings: 1300, 1600, 2000, 2200 → self-contained 2200-1300 = 0.9 kWh.
        // This drives: first partial day + interior buckets (28th, 29th) + anchored last partial.
        $result = RangeConsumption::unitsForWindow(
            $device->id,
            Carbon::parse('2026-06-27 12:00:00'),
            Carbon::parse('2026-06-30 12:00:00'),
        );

        $this->assertEquals(0.9, $result['units_kwh']);
        $this->assertSame(4, $result['reading_count']);
    }

    public function test_silent_interior_day_reconciles(): void
    {
        $device = $this->createMeter();

        Carbon::setTestNow('2026-06-27 09:00:00');
        $this->send($device, ts: 1000, energy: 1000);
        Carbon::setTestNow('2026-06-27 21:00:00');
        $this->send($device, ts: 1100, energy: 1400);

        // Day 28 is silent — no readings, no bucket. Consumption across the gap
        // is carried by day 29's chained baseline.
        Carbon::setTestNow('2026-06-29 09:00:00');
        $this->send($device, ts: 1200, energy: 2000);
        Carbon::setTestNow('2026-06-29 21:00:00');
        $this->send($device, ts: 1300, energy: 2300);

        Carbon::setTestNow('2026-06-30 12:00:00');
        $this->send($device, ts: 1400, energy: 2500);

        // In-window (06-27 12:00 → 06-30 12:00): 1400, 2000, 2300, 2500 → 2500-1400 = 1.1 kWh.
        $result = RangeConsumption::unitsForWindow(
            $device->id,
            Carbon::parse('2026-06-27 12:00:00'),
            Carbon::parse('2026-06-30 12:00:00'),
        );

        $this->assertEquals(1.1, $result['units_kwh']);
        $this->assertSame(4, $result['reading_count']);
    }

    public function test_reset_within_the_last_partial_day_is_handled(): void
    {
        $device = $this->createMeter();

        Carbon::setTestNow('2026-06-27 13:00:00');
        $this->send($device, ts: 1000, energy: 5000);
        Carbon::setTestNow('2026-06-28 10:00:00');
        $this->send($device, ts: 1100, energy: 5500);
        Carbon::setTestNow('2026-06-29 10:00:00');
        $this->send($device, ts: 1200, energy: 6000);
        Carbon::setTestNow('2026-06-30 08:00:00');
        $this->send($device, ts: 1300, energy: 6300);
        Carbon::setTestNow('2026-06-30 10:00:00');
        $this->send($device, ts: 1400, energy: 200); // reset on the end day — banks 6300

        // Self-contained raw walk: 200 - 5000 + 6300 = 1500 Wh = 1.5 kWh, 5 readings.
        // The anchored last-partial-day walk must bank the reset just like a raw walk.
        $result = RangeConsumption::unitsForWindow(
            $device->id,
            Carbon::parse('2026-06-27 12:00:00'),
            Carbon::parse('2026-06-30 12:00:00'),
        );

        $this->assertEquals(1.5, $result['units_kwh']);
        $this->assertSame(5, $result['reading_count']);
    }

    public function test_window_starting_on_empty_day_falls_back_and_ignores_prior_data(): void
    {
        $device = $this->createMeter();

        Carbon::setTestNow('2026-06-27 10:00:00');
        $this->send($device, ts: 1000, energy: 1000);
        Carbon::setTestNow('2026-06-27 20:00:00');
        $this->send($device, ts: 1100, energy: 1300); // prior data, before the window

        // Day 28 silent.
        Carbon::setTestNow('2026-06-29 10:00:00');
        $this->send($device, ts: 1200, energy: 1800);
        Carbon::setTestNow('2026-06-30 10:00:00');
        $this->send($device, ts: 1300, energy: 2100);

        // Window starts on the empty day 28 with readings only on day 27 (before
        // the window). Self-contained must IGNORE the day-27 readings: first
        // in-window = 1800, last = 2100 → 0.3 kWh. This proves the empty-start-day
        // fallback prevents the interior bucket from mis-anchoring to 1300.
        $result = RangeConsumption::unitsForWindow(
            $device->id,
            Carbon::parse('2026-06-28 00:00:00'),
            Carbon::parse('2026-06-30 12:00:00'),
        );

        $this->assertEquals(0.3, $result['units_kwh']);
        $this->assertSame(2, $result['reading_count']);
    }

    // ── helpers (mirroring MeterMonthlyConsumptionTest) ────────────────────────

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
