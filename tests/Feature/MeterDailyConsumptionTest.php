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
 * Exercises MeterPayloadProcessor::updateDailyConsumption() through the public
 * process() entry point: baseline seeding, in-day accumulation, day
 * rollover/chaining + finalisation, counter-reset rollover, out-of-order
 * skipping, and energy-less skipping. Mirrors MeterMonthlyConsumptionTest one
 * granularity down. Runs on the isolated test DB only.
 */
#[RequiresPhpExtension('pdo_sqlite')]
class MeterDailyConsumptionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_first_reading_seeds_baseline_and_starts_at_zero(): void
    {
        Carbon::setTestNow('2026-06-30 10:00:00');
        $device = $this->createMeter();

        $this->send($device, ts: 1000, energy: 10000);

        $row = $this->day($device, '2026-06-30');
        $this->assertNotNull($row);
        $this->assertSame(10000, $row->baseline_energy_wh);
        $this->assertSame(10000, $row->last_energy_wh);
        $this->assertSame(0, $row->rollover_wh);
        $this->assertEquals(0.0, (float) $row->units_kwh);
    }

    public function test_subsequent_readings_accumulate_units(): void
    {
        Carbon::setTestNow('2026-06-30 10:00:00');
        $device = $this->createMeter();

        $this->send($device, ts: 1000, energy: 10000);
        $this->send($device, ts: 1100, energy: 10500); // +500 Wh

        $this->assertEquals(0.5, (float) $this->day($device, '2026-06-30')->units_kwh);
    }

    public function test_new_day_chains_baseline_and_finalises_previous(): void
    {
        $device = $this->createMeter();

        Carbon::setTestNow('2026-06-28 10:00:00');
        $this->send($device, ts: 1000, energy: 5000);
        $this->send($device, ts: 1100, energy: 5800); // day-1 units = 0.8

        Carbon::setTestNow('2026-06-29 10:00:00');
        $this->send($device, ts: 1200, energy: 6000); // day 2

        $d1 = $this->day($device, '2026-06-28');
        $d2 = $this->day($device, '2026-06-29');

        $this->assertEquals(0.8, (float) $d1->units_kwh);
        $this->assertNotNull($d1->finalized_at, 'previous day should be finalised');

        $this->assertSame(5800, $d2->baseline_energy_wh, 'day 2 baseline = day 1 final reading');
        $this->assertEquals(0.2, (float) $d2->units_kwh);
        $this->assertNull($d2->finalized_at, 'current day stays open');
    }

    public function test_counter_reset_is_absorbed_by_rollover(): void
    {
        Carbon::setTestNow('2026-06-30 10:00:00');
        $device = $this->createMeter();

        $this->send($device, ts: 1000, energy: 10000);
        $this->send($device, ts: 1100, energy: 10500);
        $this->send($device, ts: 1200, energy: 300); // reset! banks 10500

        $row = $this->day($device, '2026-06-30');
        $this->assertSame(10500, $row->rollover_wh);
        $this->assertEquals(0.8, (float) $row->units_kwh); // (300 - 10000 + 10500)/1000
    }

    public function test_out_of_order_reading_does_not_change_daily_aggregate(): void
    {
        Carbon::setTestNow('2026-06-30 10:00:00');
        $device = $this->createMeter();

        $this->send($device, ts: 2000, energy: 10500);
        $before = $this->day($device, '2026-06-30')->units_kwh;

        $result = $this->send($device, ts: 1000, energy: 99999); // older ts → not promoted

        $this->assertFalse($result->latestStateUpdated);
        $this->assertEquals($before, $this->day($device, '2026-06-30')->units_kwh);
    }

    public function test_reading_without_pzem_energy_does_not_create_a_day_row(): void
    {
        Carbon::setTestNow('2026-06-30 10:00:00');
        $device = $this->createMeter();

        app(MeterPayloadProcessor::class)->process(
            $device->mqtt_topic,
            json_encode(['ts' => 1000, 'voltage' => 230.0], JSON_THROW_ON_ERROR),
        );

        $this->assertSame(0, MeterDailyConsumption::where('device_id', $device->id)->count());
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
