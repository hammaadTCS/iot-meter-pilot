<?php

namespace Tests\Feature;

use App\Models\AlertEvent;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;

/**
 * The alerts console visibility model: a user sees only their own devices'
 * alerts; an admin sees the whole fleet. This reuses AlertEvent::visibleTo(),
 * the same rule as device visibility.
 */
#[RequiresPhpExtension('pdo_sqlite')]
class AlertsConsoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_sees_only_their_own_device_alerts(): void
    {
        $this->withoutVite();

        $owner = User::factory()->create(['role' => 'user']);
        $other = User::factory()->create(['role' => 'user']);

        $this->alertFor($this->meterFor($owner), 'Own meter is down');
        $this->alertFor($this->meterFor($other), 'Another owners meter');

        $this->actingAs($owner)->get('/alerts')
            ->assertOk()
            ->assertSee('Own meter is down')
            ->assertDontSee('Another owners meter');
    }

    public function test_an_admin_sees_the_whole_fleet(): void
    {
        $this->withoutVite();

        $admin = User::factory()->create(['role' => 'admin']);
        $someone = User::factory()->create(['role' => 'user']);

        $this->alertFor($this->meterFor($someone), 'Fleet meter alert');

        $this->actingAs($admin)->get('/alerts')
            ->assertOk()
            ->assertSee('Fleet meter alert');
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

    private function alertFor(Device $device, string $message): AlertEvent
    {
        return AlertEvent::create([
            'device_id'    => $device->id,
            'device_type'  => 'meter',
            'alert_type'   => 'telemetry_down',
            'severity'     => 'critical',
            'status'       => 'open',
            'message'      => $message,
            'triggered_at' => now(),
        ]);
    }
}
