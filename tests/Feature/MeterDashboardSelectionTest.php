<?php

namespace Tests\Feature;

use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;

#[RequiresPhpExtension('pdo_sqlite')]
class MeterDashboardSelectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_dashboard_uses_explicitly_selected_meter(): void
    {
        $firstMeter = $this->createMeter([
            'code' => 'meter-alpha',
            'name' => 'Meter Alpha',
        ]);

        $secondMeter = $this->createMeter([
            'code' => 'meter-bravo',
            'name' => 'Meter Bravo',
        ]);

        $response = $this->get('/?device='.$secondMeter->id);

        $response
            ->assertOk()
            ->assertViewHas('device', fn (Device $device) => $device->is($secondMeter))
            ->assertViewHas('devices', fn ($devices) => $devices->count() === 2);
    }

    public function test_dashboard_falls_back_to_configured_pilot_meter_when_no_query_param_is_present(): void
    {
        $fallbackMeter = $this->createMeter([
            'code' => 'meter-fallback',
            'name' => 'Fallback Meter',
        ]);

        $this->createMeter([
            'code' => 'meter-other',
            'name' => 'Other Meter',
        ]);

        $this->setMeterDeviceCode($fallbackMeter->code);

        $response = $this->get('/');

        $response
            ->assertOk()
            ->assertViewHas('device', fn (Device $device) => $device->is($fallbackMeter));
    }

    public function test_dashboard_shows_empty_state_when_no_active_meters_exist(): void
    {
        $response = $this->get('/');

        $response
            ->assertOk()
            ->assertSee('No meters configured yet');
    }

    private function createMeter(array $attributes = []): Device
    {
        $defaults = [
            'code' => 'meter-'.fake()->unique()->slug(),
            'name' => 'Meter '.fake()->unique()->word(),
            'type' => 'meter',
            'mqtt_topic' => 'meters/'.fake()->unique()->slug(),
            'is_active' => true,
        ];

        return Device::create(array_merge($defaults, $attributes));
    }

    private function setMeterDeviceCode(string $code): void
    {
        putenv("METER_DEVICE_CODE={$code}");
        $_ENV['METER_DEVICE_CODE'] = $code;
        $_SERVER['METER_DEVICE_CODE'] = $code;
    }
}
