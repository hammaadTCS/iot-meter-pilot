<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;

#[RequiresPhpExtension('pdo_sqlite')]
class MeterDashboardSelectionTest extends TestCase
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

    public function test_dashboard_shows_correct_meter_via_route_model_binding(): void
    {
        $firstMeter = $this->createMeter([
            'code' => 'meter-alpha',
            'name' => 'Meter Alpha',
        ]);

        $secondMeter = $this->createMeter([
            'code' => 'meter-bravo',
            'name' => 'Meter Bravo',
        ]);

        // Each meter now has its own URL — no query param picker
        $response = $this->get('/devices/'.$secondMeter->id.'/dashboard');

        $response
            ->assertOk()
            ->assertViewHas('device', fn (Device $device) => $device->is($secondMeter));
    }

    public function test_dashboard_is_scoped_to_a_single_device_by_url(): void
    {
        $meter = $this->createMeter([
            'code' => 'meter-fallback',
            'name' => 'Fallback Meter',
        ]);

        $response = $this->get('/devices/'.$meter->id.'/dashboard');

        $response
            ->assertOk()
            ->assertViewHas('device', fn (Device $device) => $device->is($meter));
    }

    public function test_dashboard_requires_device_to_be_accessible_by_user(): void
    {
        $otherUser = User::factory()->consumer()->create();
        $otherMeter = $this->createMeter([
            'code'    => 'meter-other',
            'name'    => 'Other User Meter',
            'user_id' => $otherUser->id,
        ]);

        // Regular user cannot access another user's device
        $response = $this->get('/devices/'.$otherMeter->id.'/dashboard');
        $response->assertForbidden();
    }

    public function test_devices_index_redirects_from_old_manage_url(): void
    {
        $response = $this->get('/devices/manage');
        $response->assertRedirect('/devices');
    }

    private function createMeter(array $attributes = []): Device
    {
        $defaults = [
            'code'        => 'meter-'.fake()->unique()->slug(),
            'name'        => 'Meter '.fake()->unique()->word(),
            'type'        => 'meter',
            'mqtt_topic'  => 'meters/'.fake()->unique()->slug(),
            'is_active'   => true,
            'user_id'     => $this->user->id,
        ];

        return Device::create(array_merge($defaults, $attributes));
    }
}
