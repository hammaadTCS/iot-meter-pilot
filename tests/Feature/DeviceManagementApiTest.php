<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\LatestMeterState;
use App\Models\MeterReading;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;

#[RequiresPhpExtension('pdo_sqlite')]
class DeviceManagementApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // A provisioning archetype: field_engineer carries devices.create +
        // api.devices.write; deleting its own devices is a direct grant on
        // top (bundles stay lean — plan §3.2).
        $this->user = User::factory()->fieldEngineer()->create();
        $this->user->givePermissionTo('devices.delete_own');
        $this->actingAs($this->user, 'sanctum');
    }

    public function test_device_can_be_created_from_the_management_api(): void
    {
        $response = $this->postJson('/api/devices', [
            'code' => 'meter-new',
            'name' => 'New Meter',
            'type' => 'meter',
            'mqtt_topic' => 'meters/new-meter',
            'is_active' => true,
        ]);

        $response
            ->assertCreated()
            ->assertJsonFragment([
                'code' => 'meter-new',
                'name' => 'New Meter',
            ]);

        $this->assertDatabaseHas('devices', [
            'code' => 'meter-new',
            'mqtt_topic' => 'meters/new-meter',
            'availability_topic' => 'meters/new-meter/status',
        ]);
    }

    public function test_deleting_a_device_cascades_its_history_and_latest_state(): void
    {
        $device = Device::create([
            'code' => 'meter-delete',
            'name' => 'Delete Me',
            'type' => 'meter',
            'mqtt_topic' => 'meters/delete-me',
            'is_active' => true,
            'user_id' => $this->user->id,
        ]);

        $reading = MeterReading::create([
            'device_id' => $device->id,
            'ts' => 1776158985,
            'voltage' => 236.8,
            'current' => 1.23,
            'power' => 291.2,
            'energy_computed_wh' => 15.5,
            'energy_pzem_wh' => 16,
            'frequency' => 49.9,
            'pf' => 0.95,
            'raw_payload' => [
                'ts' => 1776158985,
            ],
        ]);

        $latestState = LatestMeterState::create([
            'device_id' => $device->id,
            'ts' => 1776158985,
            'voltage' => 236.8,
            'current' => 1.23,
            'power' => 291.2,
            'energy_computed_wh' => 15.5,
            'energy_pzem_wh' => 16,
            'frequency' => 49.9,
            'pf' => 0.95,
            'received_at' => now(),
        ]);

        $response = $this->deleteJson('/api/devices/'.$device->id);

        $response
            ->assertOk()
            ->assertJsonFragment([
                'message' => 'Device deleted successfully.',
            ]);

        $this->assertDatabaseMissing('devices', ['id' => $device->id]);
        $this->assertDatabaseMissing('meter_readings', ['id' => $reading->id]);
        $this->assertDatabaseMissing('latest_meter_states', ['id' => $latestState->id]);
    }
}
