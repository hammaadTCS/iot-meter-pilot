<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\LatestMeterState;
use App\Models\MeterMonthlyConsumption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;

/**
 * Covers the dashboard presentation of the Monthly Units feature: the former
 * "Computed Energy" KPI card is now "Monthly Units" (kWh), and the PZEM card
 * renders its watt-hour counter converted to kWh.
 */
#[RequiresPhpExtension('pdo_sqlite')]
class MeterDashboardUnitsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->user = User::factory()->consumer()->create();
        $this->actingAs($this->user);
    }

    public function test_dashboard_shows_monthly_units_card_and_pzem_in_kwh(): void
    {
        $meter = Device::create([
            'code'       => 'meter-units',
            'name'       => 'Units Meter',
            'type'       => 'meter',
            'mqtt_topic' => 'meters/units',
            'is_active'  => true,
            'user_id'    => $this->user->id,
        ]);

        // 656581 Wh -> 656.581 kWh ; monthly units 2.921 kWh.
        LatestMeterState::create([
            'device_id'         => $meter->id,
            'ts'                => 1750000000,
            'voltage'           => 230.0,
            'current'           => 1.2,
            'power'             => 276.0,
            'energy_pzem_wh'    => 656581,
            'monthly_units_kwh' => 2.921,
            'frequency'         => 50.0,
            'pf'                => 0.95,
            'received_at'       => now(),
        ]);

        $response = $this->get('/devices/'.$meter->id.'/dashboard');

        $response->assertOk()
            ->assertSee('Monthly Units')        // new card label
            ->assertDontSee('Computed Energy')  // old card removed
            ->assertSee('id="kpi-monthly-units"', false)
            ->assertSee('2.921')                // monthly units value
            ->assertSee('656.581');             // PZEM Wh rendered as kWh
    }

    public function test_monthly_consumption_panel_renders_chart_with_history(): void
    {
        $meter = $this->createActiveMeter('meter-monthly', 'meters/monthly');

        // Two settled months of history; the chart is rendered client-side from
        // the bootstrapped MONTHLY_DATA, so we assert the panel scaffolding plus
        // the units figures that get serialised into that payload.
        MeterMonthlyConsumption::create([
            'device_id'    => $meter->id,
            'period_start' => '2026-05-01',
            'units_kwh'    => 98.100,
        ]);
        MeterMonthlyConsumption::create([
            'device_id'    => $meter->id,
            'period_start' => '2026-06-01',
            'units_kwh'    => 12.500,
        ]);

        $response = $this->get('/devices/'.$meter->id.'/dashboard');

        // Units assertions use the trailing-zero-free substring so they hold on
        // both SQLite (REAL → "12.5") and MySQL (decimal → "12.500").
        $response->assertOk()
            ->assertSee('Monthly Consumption')            // panel title
            ->assertSee('id="chartMonthly"', false)       // canvas present (has data)
            ->assertDontSee('No monthly consumption recorded yet.')
            ->assertSee('2026-06-01')                     // current month present in MONTHLY_DATA
            ->assertSee('2026-05-01')                     // prior month present in MONTHLY_DATA
            ->assertSee('12.5')                           // current month units
            ->assertSee('98.1');                          // prior month units
    }

    public function test_monthly_consumption_panel_shows_empty_state_without_history(): void
    {
        $meter = $this->createActiveMeter('meter-no-months', 'meters/no-months');

        $response = $this->get('/devices/'.$meter->id.'/dashboard');

        $response->assertOk()
            ->assertSee('Monthly Consumption')                  // panel still rendered
            ->assertSee('No monthly consumption recorded yet.') // empty-state copy
            ->assertDontSee('id="chartMonthly"', false);        // canvas omitted
    }

    public function test_dashboard_seeds_range_units_card_for_the_default_hour(): void
    {
        $meter = $this->createActiveMeter('meter-range', 'meters/range');

        // Two readings within the last hour → 0.500 kWh in the default 1h seed window.
        DB::table('meter_readings')->insert([
            ['device_id' => $meter->id, 'ts' => 1000, 'energy_pzem_wh' => 1000, 'raw_payload' => '{}',
             'received_at' => now()->subMinutes(40), 'created_at' => now()->subMinutes(40), 'updated_at' => now()->subMinutes(40)],
            ['device_id' => $meter->id, 'ts' => 1100, 'energy_pzem_wh' => 1500, 'raw_payload' => '{}',
             'received_at' => now()->subMinutes(10), 'created_at' => now()->subMinutes(10), 'updated_at' => now()->subMinutes(10)],
        ]);

        $response = $this->get('/devices/'.$meter->id.'/dashboard');

        $response->assertOk()
            ->assertSee('Range Units')                 // new KPI card label
            ->assertSee('id="kpi-range-units"', false) // card present
            ->assertSee('0.500')                       // server-seeded 1h consumption
            ->assertSee('Daily Breakdown')             // monthly report panel
            ->assertSee('id="dailyMonthSelect"', false)
            ->assertSee('Monthly total:');
    }

    /**
     * Create an active meter owned by the test user. Kept small so each test
     * declares only the data it actually asserts on.
     */
    private function createActiveMeter(string $code, string $topic): Device
    {
        return Device::create([
            'code'       => $code,
            'name'       => 'Meter '.$code,
            'type'       => 'meter',
            'mqtt_topic' => $topic,
            'is_active'  => true,
            'user_id'    => $this->user->id,
        ]);
    }
}
