<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\MeterDailyConsumption;
use App\Services\Meters\MeterPayloadProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;

/**
 * Verifies meters:backfill-daily-consumption rebuilds per-day rows from raw
 * history: day-to-day baseline chaining, in-day accumulation, counter-reset
 * rollover, past-day finalisation, and the current day left open. Runs entirely
 * on the isolated test database (RefreshDatabase) — never touches real data.
 */
#[RequiresPhpExtension('pdo_sqlite')]
class BackfillDailyConsumptionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_backfill_chains_daily_baselines_and_finalises_past_days(): void
    {
        $device = $this->createMeter();

        // Day 1 — two readings → 0.5 kWh consumed, baseline 1000.
        Carbon::setTestNow('2026-06-28 08:00:00');
        $this->send($device, ts: 1000, energy: 1000);
        Carbon::setTestNow('2026-06-28 20:00:00');
        $this->send($device, ts: 1100, energy: 1500);

        // Day 2 — one reading → baseline chains from day 1's final (1500), 0.3 kWh.
        Carbon::setTestNow('2026-06-29 09:00:00');
        $this->send($device, ts: 1200, energy: 1800);

        // Day 3 (current) — baseline chains from day 2 (1800), 0.2 kWh, stays open.
        Carbon::setTestNow('2026-06-30 10:00:00');
        $this->send($device, ts: 1300, energy: 2000);

        // Now run the backfill with "today" = 2026-06-30.
        $this->artisan('meters:backfill-daily-consumption')->assertSuccessful();

        $d1 = $this->day($device, '2026-06-28');
        $d2 = $this->day($device, '2026-06-29');
        $d3 = $this->day($device, '2026-06-30');

        $this->assertSame(1000, $d1->baseline_energy_wh);
        $this->assertEquals(0.5, (float) $d1->units_kwh);
        $this->assertNotNull($d1->finalized_at, 'past day should be finalised');

        $this->assertSame(1500, $d2->baseline_energy_wh, 'day 2 baseline = day 1 final reading');
        $this->assertEquals(0.3, (float) $d2->units_kwh);
        $this->assertNotNull($d2->finalized_at);

        $this->assertSame(1800, $d3->baseline_energy_wh, 'day 3 baseline = day 2 final reading');
        $this->assertEquals(0.2, (float) $d3->units_kwh);
        $this->assertNull($d3->finalized_at, 'current day stays open');
    }

    public function test_backfill_absorbs_counter_reset_within_a_day(): void
    {
        $device = $this->createMeter();

        Carbon::setTestNow('2026-06-30 08:00:00');
        $this->send($device, ts: 1000, energy: 10000);
        Carbon::setTestNow('2026-06-30 09:00:00');
        $this->send($device, ts: 1100, energy: 10500);
        Carbon::setTestNow('2026-06-30 10:00:00');
        $this->send($device, ts: 1200, energy: 300); // reset — banks 10500

        $this->artisan('meters:backfill-daily-consumption')->assertSuccessful();

        $row = $this->day($device, '2026-06-30');
        // (300 - 10000 + 10500) / 1000 = 0.8
        $this->assertSame(10500, $row->rollover_wh);
        $this->assertEquals(0.8, (float) $row->units_kwh);
    }

    public function test_backfill_is_idempotent(): void
    {
        $device = $this->createMeter();

        Carbon::setTestNow('2026-06-30 08:00:00');
        $this->send($device, ts: 1000, energy: 1000);
        Carbon::setTestNow('2026-06-30 09:00:00');
        $this->send($device, ts: 1100, energy: 1600);

        $this->artisan('meters:backfill-daily-consumption')->assertSuccessful();
        $this->artisan('meters:backfill-daily-consumption')->assertSuccessful();

        // Re-running must not duplicate rows or change the figure.
        $this->assertSame(1, MeterDailyConsumption::where('device_id', $device->id)->count());
        $this->assertEquals(0.6, (float) $this->day($device, '2026-06-30')->units_kwh);
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

    private function day(Device $device, string $periodDate): ?MeterDailyConsumption
    {
        return MeterDailyConsumption::where('device_id', $device->id)
            ->whereDate('period_date', $periodDate)
            ->first();
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
