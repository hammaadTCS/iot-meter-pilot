<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\LatestMeterState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;

/**
 * Covers the redesigned device card on the main dashboard: for meters it now
 * surfaces live Voltage / Power / current-month Units inline (read from the
 * eager-loaded latest state) so users don't have to open the live dashboard for
 * the basics. Non-meter devices keep the identity-only layout.
 */
#[RequiresPhpExtension('pdo_sqlite')]
class MainDashboardCardTest extends TestCase
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

    public function test_meter_card_shows_voltage_power_and_monthly_units(): void
    {
        $meter = Device::create([
            'code'       => 'meter-card',
            'name'       => 'Card Meter',
            'type'       => 'meter',
            'mqtt_topic' => 'meters/card',
            'is_active'  => true,
            'user_id'    => $this->user->id,
        ]);

        LatestMeterState::create([
            'device_id'         => $meter->id,
            'ts'                => 1750000000,
            'voltage'           => 230.0,
            'current'           => 1.2,
            'power'             => 276.0,
            'monthly_units_kwh' => 2.921,
            'received_at'       => now(),
        ]);

        $response = $this->get('/dashboard');

        $response->assertOk()
            ->assertSee('Voltage')   // metric labels
            ->assertSee('Power')
            ->assertSee('Month')
            ->assertSee('230.0')     // voltage, 1 dp
            ->assertSee('276')       // power, 0 dp
            ->assertSee('2.921')     // monthly units, 3 dp
            ->assertSee('kWh');
    }

    public function test_non_meter_card_has_no_live_metrics(): void
    {
        Device::create([
            'code'       => 'sensor-1',
            'name'       => 'Hallway Sensor',
            'type'       => 'sensor',
            'mqtt_topic' => 'sensors/1',
            'is_active'  => true,
            'user_id'    => $this->user->id,
        ]);

        $response = $this->get('/dashboard');

        // With no meter on the page, the metric labels must be absent entirely.
        $response->assertOk()
            ->assertDontSee('Voltage')
            ->assertDontSee('>Power<', false);
    }
}
