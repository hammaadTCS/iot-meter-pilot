<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\LatestMeterState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->user = User::factory()->create();
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
}
