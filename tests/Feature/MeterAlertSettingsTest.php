<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\MeterAlertSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;

/**
 * The per-meter, opt-in alert configuration page: the owner can view and save
 * which triggers are on and their limits; blank fields mean "off"; non-owners
 * are forbidden.
 */
#[RequiresPhpExtension('pdo_sqlite')]
class MeterAlertSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_view_and_save_settings(): void
    {
        $this->withoutVite();
        $owner = User::factory()->create(['role' => 'user']);
        $meter = $this->meterFor($owner);
        $this->actingAs($owner);

        $this->get("/devices/{$meter->id}/alerts")
            ->assertOk()
            ->assertSee('Monthly budget')
            ->assertSee('Electrical safety');

        $this->patch("/devices/{$meter->id}/alerts", [
            'monthly_budget_kwh'      => 300,
            'monthly_budget_warn_pct' => 90,
            'daily_budget_kwh'        => '',      // blank → off
            'anomaly_enabled'         => '1',
            'anomaly_multiplier'      => 2.5,
            'voltage_high'            => 250,
            'voltage_low'             => '',      // blank → off
            'power_max_kw'            => 5,
            'pf_min'                  => 0.85,
            'offline_enabled'         => '1',
        ])->assertRedirect();

        $s = MeterAlertSetting::where('device_id', $meter->id)->firstOrFail();
        $this->assertEquals(300.0, (float) $s->monthly_budget_kwh);
        $this->assertSame(90, $s->monthly_budget_warn_pct);
        $this->assertNull($s->daily_budget_kwh);            // blank stored as off
        $this->assertTrue($s->anomaly_enabled);
        $this->assertEquals(250.0, (float) $s->voltage_high);
        $this->assertNull($s->voltage_low);
        $this->assertEquals(5.0, (float) $s->power_max_kw);
        $this->assertEquals(0.85, (float) $s->pf_min);
        $this->assertTrue($s->offline_enabled);
    }

    public function test_a_non_owner_is_forbidden(): void
    {
        $this->withoutVite();
        $meter = $this->meterFor(User::factory()->create(['role' => 'user']));
        $this->actingAs(User::factory()->create(['role' => 'user']));

        $this->get("/devices/{$meter->id}/alerts")->assertForbidden();
        $this->patch("/devices/{$meter->id}/alerts", ['monthly_budget_warn_pct' => 80, 'anomaly_multiplier' => 2])
            ->assertForbidden();
    }

    private function meterFor(User $owner): Device
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
