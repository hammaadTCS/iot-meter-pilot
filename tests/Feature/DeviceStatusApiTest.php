<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\LatestMeterState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;

#[RequiresPhpExtension('pdo_sqlite')]
class DeviceStatusApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-04-21 12:00:00');
        $this->user = User::factory()->create();
        $this->actingAs($this->user, 'sanctum');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_status_endpoint_returns_health_issue_and_current_snapshot(): void
    {
        $device = Device::create([
            'code' => 'meter-status',
            'name' => 'Status Meter',
            'type' => 'meter',
            'mqtt_topic' => 'meters/status',
            'availability_topic' => 'meters/status/state',
            'is_active' => true,
            'last_seen_at' => now()->subSeconds(30),
            'last_message_at' => now()->subSeconds(5),
            'last_error_code' => 'missing_ts',
            'last_error_message' => 'Payload error: missing required `ts` timestamp.',
            'last_error_context' => ['topic' => 'meters/status'],
            'last_error_at' => now()->subSeconds(5),
            'last_availability_status' => 'online',
            'last_availability_message' => 'MQTT availability reports this meter online.',
            'last_availability_at' => now()->subSeconds(5),
            'last_heartbeat_at' => now()->subSeconds(5),
            'user_id' => $this->user->id,
        ]);

        LatestMeterState::create([
            'device_id' => $device->id,
            'ts' => 1776753570,
            'voltage' => 220.4,
            'current' => 0.22,
            'power' => 21.5,
            'energy_computed_wh' => 1379.510,
            'energy_pzem_wh' => 77661,
            'frequency' => 49.80,
            'pf' => 0.43,
            'received_at' => now()->subSeconds(30),
        ]);

        $response = $this->getJson("/api/devices/{$device->id}/status");

        $response
            ->assertOk()
            ->assertJsonPath('device_id', $device->id)
            ->assertJsonPath('health.status', 'online')
            ->assertJsonPath('availability.status', 'online')
            ->assertJsonPath('availability.topic', 'meters/status/state')
            ->assertJsonPath('issue.status', 'error')
            ->assertJsonPath('issue.code', 'missing_ts')
            ->assertJsonPath('issue.has_issue', true)
            ->assertJsonPath('current_snapshot.power', 21.5);
    }

    public function test_status_endpoint_hides_recovered_issue_once_the_meter_is_down_again(): void
    {
        $device = Device::create([
            'code' => 'meter-status-recovered',
            'name' => 'Recovered Then Down Status Meter',
            'type' => 'meter',
            'mqtt_topic' => 'meters/status-recovered',
            'availability_topic' => 'meters/status-recovered/status',
            'is_active' => true,
            'last_seen_at' => now()->subMinutes(12),
            'last_error_code' => 'missing_ts',
            'last_error_message' => 'Payload error: missing required `ts` timestamp.',
            'last_error_context' => ['topic' => 'meters/status-recovered'],
            'last_error_at' => now()->subMinutes(15),
            'last_recovered_at' => now()->subMinutes(12),
            'last_availability_status' => 'online',
            'last_availability_message' => 'MQTT availability reports this meter online.',
            'last_availability_at' => now()->subMinutes(2),
            'last_heartbeat_at' => now()->subMinutes(2),
            'user_id' => $this->user->id,
        ]);

        $response = $this->getJson("/api/devices/{$device->id}/status");

        $response
            ->assertOk()
            ->assertJsonPath('health.status', 'down')
            ->assertJsonPath('availability.status', 'silent')
            ->assertJsonPath('issue.status', 'ok')
            ->assertJsonPath('issue.has_issue', false);
    }
}
