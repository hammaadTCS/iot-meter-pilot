<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\LatestMeterState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;

#[RequiresPhpExtension('pdo_sqlite')]
class MeterHealthViewsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Carbon::setTestNow('2026-04-21 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_management_page_shows_enabled_and_runtime_health_separately(): void
    {
        $this->createMeter([
            'code' => 'meter-online',
            'name' => 'Online Meter',
            'last_seen_at' => now()->subSeconds(40),
            'is_active' => true,
        ]);

        $this->createMeter([
            'code' => 'meter-never-seen',
            'name' => 'Never Seen Meter',
            'last_seen_at' => null,
            'is_active' => true,
        ]);

        $this->createMeter([
            'code' => 'meter-down',
            'name' => 'Down Meter',
            'last_seen_at' => now()->subMinutes(12),
            'is_active' => true,
        ]);

        $this->createMeter([
            'code' => 'meter-issue',
            'name' => 'Issue Meter',
            'last_seen_at' => now()->subMinutes(2),
            'last_message_at' => now()->subSeconds(15),
            'last_error_code' => 'missing_ts',
            'last_error_message' => 'Payload error: missing required `ts` timestamp.',
            'last_error_at' => now()->subSeconds(15),
            'is_active' => true,
        ]);

        $this->createMeter([
            'code' => 'meter-offline-availability',
            'name' => 'Offline Availability Meter',
            'last_seen_at' => now()->subMinutes(4),
            'last_availability_status' => 'offline',
            'last_availability_message' => 'MQTT availability reported this meter offline.',
            'last_availability_at' => now()->subSeconds(10),
            'is_active' => true,
        ]);

        $this->createMeter([
            'code' => 'meter-disabled',
            'name' => 'Disabled Meter',
            'last_seen_at' => null,
            'is_active' => false,
        ]);

        $response = $this->get('/devices/manage');

        $response
            ->assertOk()
            ->assertSee('Enabled')
            ->assertSee('Availability')
            ->assertSee('Health')
            ->assertSee('Issue')
            ->assertSee('Last Seen')
            ->assertSee('Offline')
            ->assertSee('Online')
            ->assertSee('Never Seen')
            ->assertSee('Down')
            ->assertSee('Payload Error')
            ->assertSee('Disabled');
    }

    public function test_dashboard_shows_initial_down_health_banner_for_silent_meter(): void
    {
        $meter = $this->createMeter([
            'code' => 'meter-silent',
            'name' => 'Silent Meter',
            'last_seen_at' => now()->subMinutes(12),
            'is_active' => true,
        ]);

        $response = $this->get('/?device='.$meter->id);

        $response
            ->assertOk()
            ->assertSee('Health')
            ->assertSee('Down')
            ->assertSee('Meter appears down. No telemetry has been received for 12m.');
    }

    public function test_dashboard_uses_latest_state_received_at_for_initial_current_timestamp(): void
    {
        $meter = $this->createMeter([
            'code' => 'meter-current-state',
            'name' => 'Current State Meter',
            'last_seen_at' => Carbon::parse('2026-04-21 11:54:00'),
            'is_active' => true,
        ]);

        $latestState = LatestMeterState::create([
            'device_id' => $meter->id,
            'ts' => 1776762780,
            'voltage' => 220.40,
            'current' => 0.226,
            'power' => 21.50,
            'energy_computed_wh' => 1379.510,
            'energy_pzem_wh' => 77661,
            'frequency' => 49.80,
            'pf' => 0.43,
            'received_at' => Carbon::parse('2026-04-21 11:53:00'),
        ]);

        $latestState->timestamps = false;
        $latestState->forceFill([
            'created_at' => Carbon::parse('2026-04-18 09:00:00'),
            'updated_at' => Carbon::parse('2026-04-21 11:53:00'),
        ])->save();
        $latestState->timestamps = true;

        $response = $this->get('/?device='.$meter->id);

        $response
            ->assertOk()
            ->assertSee('21 Apr 2026')
            ->assertSee('11:53:00')
            ->assertDontSee('18 Apr 2026');
    }

    public function test_dashboard_shows_initial_offline_availability_banner(): void
    {
        $meter = $this->createMeter([
            'code' => 'meter-offline',
            'name' => 'Offline Meter',
            'last_seen_at' => now()->subMinutes(2),
            'last_availability_status' => 'offline',
            'last_availability_message' => 'MQTT availability reported this meter offline.',
            'last_availability_at' => now()->subSeconds(5),
            'is_active' => true,
        ]);

        $response = $this->get('/?device='.$meter->id);

        $response
            ->assertOk()
            ->assertSee('Availability')
            ->assertSee('Offline')
            ->assertSee('MQTT availability reported this meter offline.');
    }

    public function test_management_page_does_not_show_recovered_for_a_meter_that_is_down_again(): void
    {
        $this->createMeter([
            'code' => 'meter-recovered-down',
            'name' => 'Recovered Then Down Meter',
            'last_seen_at' => now()->subMinutes(12),
            'last_error_code' => 'missing_ts',
            'last_error_message' => 'Payload error: missing required `ts` timestamp.',
            'last_error_at' => now()->subMinutes(15),
            'last_recovered_at' => now()->subMinutes(12),
            'is_active' => true,
        ]);

        $response = $this->get('/devices/manage');

        $response
            ->assertOk()
            ->assertSee('Recovered Then Down Meter')
            ->assertSee('No Issue')
            ->assertDontSee('Valid telemetry resumed');
    }

    private function createMeter(array $attributes = []): Device
    {
        $defaults = [
            'code' => 'meter-'.fake()->unique()->slug(),
            'name' => 'Meter '.fake()->unique()->word(),
            'type' => 'meter',
            'mqtt_topic' => 'meters/'.fake()->unique()->slug(),
            'is_active' => true,
            'last_seen_at' => null,
        ];

        return Device::create(array_merge($defaults, $attributes));
    }
}
