<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\LatestMeterState;
use App\Models\MeterMonthlyConsumption;
use App\Services\Meters\MeterPayloadProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;

/**
 * Exercises MeterPayloadProcessor::updateMonthlyConsumption() through the public
 * process() entry point: baseline seeding, in-month accumulation, month
 * rollover/chaining + finalisation, counter-reset rollover, out-of-order
 * skipping, energy-less skipping, and the cached latest-state figure.
 */
#[RequiresPhpExtension('pdo_sqlite')]
class MeterMonthlyConsumptionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_first_reading_seeds_baseline_and_starts_at_zero(): void
    {
        Carbon::setTestNow('2026-06-05 10:00:00');
        $device = $this->createMeter();

        $this->send($device, ts: 1000, energy: 10000);

        $row = $this->month($device, '2026-06-01');
        $this->assertNotNull($row);
        $this->assertSame(10000, $row->baseline_energy_wh);
        $this->assertSame(10000, $row->last_energy_wh);
        $this->assertSame(0, $row->rollover_wh);
        $this->assertEquals(0.0, (float) $row->units_kwh);
    }

    public function test_subsequent_readings_accumulate_units(): void
    {
        Carbon::setTestNow('2026-06-05 10:00:00');
        $device = $this->createMeter();

        $this->send($device, ts: 1000, energy: 10000);
        $result = $this->send($device, ts: 1100, energy: 10500); // +500 Wh

        $row = $this->month($device, '2026-06-01');
        $this->assertEquals(0.5, (float) $row->units_kwh);

        // The figure is cached on the latest state and surfaced on the result.
        $this->assertEquals(0.5, (float) LatestMeterState::where('device_id', $device->id)->value('monthly_units_kwh'));
        $this->assertEquals(0.5, $result->monthlyUnitsKwh);
    }

    public function test_new_month_chains_baseline_and_finalises_previous(): void
    {
        $device = $this->createMeter();

        Carbon::setTestNow('2026-05-10 10:00:00');
        $this->send($device, ts: 1000, energy: 5000);
        $this->send($device, ts: 1100, energy: 5800); // May units = 0.8

        Carbon::setTestNow('2026-06-02 10:00:00');
        $this->send($device, ts: 1200, energy: 6000); // June

        $may  = $this->month($device, '2026-05-01');
        $june = $this->month($device, '2026-06-01');

        $this->assertEquals(0.8, (float) $may->units_kwh);
        $this->assertNotNull($may->finalized_at, 'previous month should be finalised');

        $this->assertSame(5800, $june->baseline_energy_wh, 'June baseline = May final reading');
        $this->assertEquals(0.2, (float) $june->units_kwh);
        $this->assertNull($june->finalized_at, 'current month stays open');
    }

    public function test_counter_reset_is_absorbed_by_rollover_and_never_negative(): void
    {
        Carbon::setTestNow('2026-06-05 10:00:00');
        $device = $this->createMeter();

        $this->send($device, ts: 1000, energy: 10000);
        $this->send($device, ts: 1100, energy: 10500);
        $this->send($device, ts: 1200, energy: 300); // reset! banks 10500

        $row = $this->month($device, '2026-06-01');
        // units = (300 - 10000 + 10500) / 1000 = 0.8
        $this->assertSame(10500, $row->rollover_wh);
        $this->assertSame(300, $row->last_energy_wh);
        $this->assertEquals(0.8, (float) $row->units_kwh);
        $this->assertGreaterThanOrEqual(0, (float) $row->units_kwh);
    }

    public function test_out_of_order_reading_does_not_change_monthly_aggregate(): void
    {
        Carbon::setTestNow('2026-06-05 10:00:00');
        $device = $this->createMeter();

        $this->send($device, ts: 2000, energy: 10500);
        $before = $this->month($device, '2026-06-01')->units_kwh;

        // Older device timestamp → not promoted → must not touch the aggregate.
        $result = $this->send($device, ts: 1000, energy: 99999);

        $this->assertFalse($result->latestStateUpdated);
        $this->assertEquals($before, $this->month($device, '2026-06-01')->units_kwh);
    }

    public function test_reading_without_pzem_energy_does_not_create_a_month_row(): void
    {
        Carbon::setTestNow('2026-06-05 10:00:00');
        $device = $this->createMeter();

        // Valid payload (voltage present) but no energy_pzem_wh.
        app(MeterPayloadProcessor::class)->process(
            $device->mqtt_topic,
            json_encode(['ts' => 1000, 'voltage' => 230.0], JSON_THROW_ON_ERROR),
        );

        $this->assertSame(0, MeterMonthlyConsumption::where('device_id', $device->id)->count());
        $this->assertNull(LatestMeterState::where('device_id', $device->id)->value('monthly_units_kwh'));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

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

    private function month(Device $device, string $periodStart): ?MeterMonthlyConsumption
    {
        return MeterMonthlyConsumption::where('device_id', $device->id)
            ->whereDate('period_start', $periodStart)
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
