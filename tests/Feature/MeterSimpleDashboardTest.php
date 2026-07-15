<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\MeterHourlyConsumption;
use App\Models\User;
use App\Services\Meters\MeterPayloadProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;

/**
 * The simplified consumer dashboard (product decision 2026-07-14):
 *  - meter.full_dashboard / meter.charts → full operator dashboard; everyone
 *    else with meter.access → simplified view (4 KPIs + hour/day history).
 *  - The hourly rollup is maintained at ingestion with the same
 *    baseline-chaining rules as the daily one, plus V/W average accumulators.
 *  - The aggregate endpoint serves hour buckets for ≤48h windows and day
 *    buckets beyond, and raw readings are refused to non-full users.
 */
#[RequiresPhpExtension('pdo_sqlite')]
class MeterSimpleDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function createMeter(User $owner): Device
    {
        return Device::factory()->create([
            'user_id' => $owner->id,
            'type'    => 'meter',
        ]);
    }

    /** Push one MQTT payload through the real ingestion pipeline. */
    private function send(Device $device, int $ts, int $energy, float $voltage = 230.0, float $power = 400.0): void
    {
        app(MeterPayloadProcessor::class)->process($device->mqtt_topic, json_encode([
            'ts'             => $ts,
            'voltage'        => $voltage,
            'current'        => 1.8,
            'power'          => $power,
            'energy_pzem_wh' => $energy,
            'frequency'      => 50.0,
            'pf'             => 0.95,
        ]));
    }

    private function hour(Device $device, string $periodStart): ?MeterHourlyConsumption
    {
        return MeterHourlyConsumption::where('device_id', $device->id)
            ->where('period_start', $periodStart)
            ->first();
    }

    // ── Hourly rollup lifecycle ──────────────────────────────────────────

    public function test_hourly_rollup_chains_baselines_and_accumulates_averages(): void
    {
        $consumer = User::factory()->consumer()->create();
        $device = $this->createMeter($consumer);

        Carbon::setTestNow('2026-07-14 10:10:00');
        $this->send($device, ts: 1000, energy: 10000, voltage: 228.0, power: 380.0);
        Carbon::setTestNow('2026-07-14 10:40:00');
        $this->send($device, ts: 1100, energy: 10400, voltage: 232.0, power: 420.0);

        Carbon::setTestNow('2026-07-14 11:05:00');
        $this->send($device, ts: 1200, energy: 10700, voltage: 230.0, power: 500.0);

        $h10 = $this->hour($device, '2026-07-14 10:00:00');
        $h11 = $this->hour($device, '2026-07-14 11:00:00');

        // Hour 10: baseline seeds at its own first reading → 0.4 kWh consumed.
        $this->assertSame(10000, $h10->baseline_energy_wh);
        $this->assertSame(10400, $h10->last_energy_wh);
        $this->assertEquals(0.4, (float) $h10->units_kwh);
        $this->assertNotNull($h10->finalized_at, 'previous hour is finalised when the next opens');

        // Exact sum/count accumulators → true means.
        $this->assertSame(2, $h10->voltage_count);
        $this->assertEquals(230.0, $h10->averageVoltage()); // (228+232)/2
        $this->assertEquals(400.0, $h10->averagePower());   // (380+420)/2

        // Hour 11 chains its baseline from hour 10's final counter.
        $this->assertSame(10400, $h11->baseline_energy_wh);
        $this->assertEquals(0.3, (float) $h11->units_kwh);
        $this->assertNull($h11->finalized_at, 'hour in progress stays open');
    }

    public function test_hourly_rollup_absorbs_counter_resets(): void
    {
        $consumer = User::factory()->consumer()->create();
        $device = $this->createMeter($consumer);

        Carbon::setTestNow('2026-07-14 10:10:00');
        $this->send($device, ts: 1000, energy: 5000);
        Carbon::setTestNow('2026-07-14 10:30:00');
        $this->send($device, ts: 1100, energy: 200); // hardware reset

        $row = $this->hour($device, '2026-07-14 10:00:00');
        $this->assertSame(5000, $row->rollover_wh, 'pre-reset total banked');
        // (200 − 5000 + 5000) / 1000 = 0.2 kWh — consumption never negative.
        $this->assertEquals(0.2, (float) $row->units_kwh);
    }

    // ── Aggregate endpoint ───────────────────────────────────────────────

    public function test_aggregate_serves_hour_buckets_for_short_windows(): void
    {
        $consumer = User::factory()->consumer()->create();
        $device = $this->createMeter($consumer);

        Carbon::setTestNow('2026-07-14 10:10:00');
        $this->send($device, ts: 1000, energy: 10000, voltage: 228.0, power: 380.0);
        Carbon::setTestNow('2026-07-14 10:40:00');
        $this->send($device, ts: 1100, energy: 10400, voltage: 232.0, power: 420.0);
        Carbon::setTestNow('2026-07-14 11:05:00');
        $this->send($device, ts: 1200, energy: 10700);

        Carbon::setTestNow('2026-07-14 12:00:00');

        $response = $this->actingAs($consumer, 'sanctum')
            ->getJson("/api/devices/{$device->id}/readings/aggregate?range=today")
            ->assertOk()
            ->json();

        $this->assertSame('hour', $response['bucket']);
        $this->assertCount(2, $response['buckets']);
        $this->assertSame('2026-07-14 10:00:00', $response['buckets'][0]['period']);
        $this->assertEquals(0.4, $response['buckets'][0]['units_kwh']);
        $this->assertEquals(230.0, $response['buckets'][0]['avg_voltage']);
        $this->assertEquals(400.0, $response['buckets'][0]['avg_power']);
    }

    public function test_aggregate_serves_day_buckets_for_long_windows(): void
    {
        $consumer = User::factory()->consumer()->create();
        $device = $this->createMeter($consumer);

        Carbon::setTestNow('2026-07-12 10:00:00');
        $this->send($device, ts: 1000, energy: 10000, voltage: 228.0, power: 380.0);
        Carbon::setTestNow('2026-07-12 18:00:00');
        $this->send($device, ts: 1100, energy: 11000, voltage: 232.0, power: 420.0);
        Carbon::setTestNow('2026-07-13 09:00:00');
        $this->send($device, ts: 1200, energy: 11500);

        Carbon::setTestNow('2026-07-14 12:00:00');

        $response = $this->actingAs($consumer, 'sanctum')
            ->getJson("/api/devices/{$device->id}/readings/aggregate?range=7d")
            ->assertOk()
            ->json();

        $this->assertSame('day', $response['bucket']);
        $this->assertSame('2026-07-12', $response['buckets'][0]['period']);
        $this->assertEquals(1.0, $response['buckets'][0]['units_kwh']);
        // Day averages derive from the hour accumulators: Σsum/Σcount.
        $this->assertEquals(230.0, $response['buckets'][0]['avg_voltage']);
        $this->assertSame('2026-07-13', $response['buckets'][1]['period']);
        $this->assertEquals(0.5, $response['buckets'][1]['units_kwh']);
    }

    public function test_aggregate_accepts_an_open_ended_custom_window(): void
    {
        $consumer = User::factory()->consumer()->create();
        $device = $this->createMeter($consumer);

        Carbon::setTestNow('2026-07-14 10:10:00');
        $this->send($device, ts: 1000, energy: 10000);
        Carbon::setTestNow('2026-07-14 11:05:00');
        $this->send($device, ts: 1100, energy: 10700);

        Carbon::setTestNow('2026-07-14 12:00:00');

        // `from` without `to` → up to now (live): both hours come back, and
        // the reported window end is open (null).
        $response = $this->actingAs($consumer, 'sanctum')
            ->getJson("/api/devices/{$device->id}/readings/aggregate?from=2026-07-14%2000:00:00")
            ->assertOk()
            ->json();

        $this->assertSame('hour', $response['bucket']);
        $this->assertNull($response['to']);
        $this->assertCount(2, $response['buckets']);

        // A malformed `from` is still rejected.
        $this->actingAs($consumer, 'sanctum')
            ->getJson("/api/devices/{$device->id}/readings/aggregate?from=not-a-date")
            ->assertStatus(422);
    }

    public function test_aggregate_enforces_meter_history_and_raw_readings_stay_operator_only(): void
    {
        $consumer = User::factory()->consumer()->create();
        $device = $this->createMeter($consumer);

        $this->actingAs($consumer, 'sanctum');

        // Consumer bundle carries meter.history → aggregates allowed…
        $this->getJson("/api/devices/{$device->id}/readings/aggregate?range=today")->assertOk();
        // …but raw minute-level rows are refused (full dashboard only).
        $this->getJson("/api/devices/{$device->id}/readings?range=1h")->assertForbidden();

        // Without meter.history the aggregate feed is refused too.
        $bare = User::factory()->create();
        $bare->givePermissionTo([
            'dashboard.view', 'devices.view_own', 'alerts.view_own',
            'alerts.settings_own', 'api.devices.read', 'api.readings.read',
            'meter.access', 'meter.live_data',
        ]);
        $bareDevice = $this->createMeter($bare);

        $this->actingAs($bare, 'sanctum')
            ->getJson("/api/devices/{$bareDevice->id}/readings/aggregate?range=today")
            ->assertForbidden();

        // A prosumer (meter.full_dashboard in the bundle) keeps raw access.
        $prosumer = User::factory()->prosumer()->create();
        $prosumerDevice = $this->createMeter($prosumer);
        $this->actingAs($prosumer, 'sanctum')
            ->getJson("/api/devices/{$prosumerDevice->id}/readings?range=1h")
            ->assertOk();
    }

    // ── View routing ─────────────────────────────────────────────────────

    public function test_consumer_gets_the_simplified_dashboard(): void
    {
        $consumer = User::factory()->consumer()->create();
        $device = $this->createMeter($consumer);

        $html = $this->actingAs($consumer)
            ->get(route('devices.dashboard', $device))
            ->assertOk()
            ->getContent();

        // The four consumer tiles + the collapsed history expander…
        $this->assertStringContainsString('id="kpi-daily-units"', $html);
        $this->assertStringContainsString('id="historyExpander"', $html);
        // …and none of the full dashboard's raw sections.
        $this->assertStringNotContainsString('id="chartVC"', $html);
        $this->assertStringNotContainsString('<tbody id="readings-body">', $html);
        $this->assertStringNotContainsString('id="kpi-freq"', $html);
    }

    public function test_full_dashboard_holders_keep_the_operator_view(): void
    {
        // Via the prosumer bundle…
        $prosumer = User::factory()->prosumer()->create();
        $html = $this->actingAs($prosumer)
            ->get(route('devices.dashboard', $this->createMeter($prosumer)))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('id="chartVC"', $html);
        $this->assertStringNotContainsString('id="historyExpander"', $html);

        // …via a direct meter.full_dashboard grant…
        $upgraded = User::factory()->consumer()->create();
        $upgraded->givePermissionTo('meter.full_dashboard');
        $this->actingAs($upgraded)
            ->get(route('devices.dashboard', $this->createMeter($upgraded)))
            ->assertOk()
            ->assertSee('readings-body', false);

        // …and via the pre-existing meter.charts opt-in (implies full).
        $optIn = User::factory()->consumer()->create();
        $optIn->givePermissionTo('meter.charts');
        $this->actingAs($optIn)
            ->get(route('devices.dashboard', $this->createMeter($optIn)))
            ->assertOk()
            ->assertSee('id="chartVC"', false);
    }
}
