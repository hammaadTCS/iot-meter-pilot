<?php

namespace Tests\Feature;

use App\Models\AlertEvent;
use App\Models\Device;
use App\Models\LatestMeterState;
use App\Models\MeterAlertSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;

/**
 * alerts:scan-thresholds — the electrical threshold detector. The load-bearing
 * behaviour is hysteresis: an alert opens only after DEBOUNCE(3) consecutive
 * breaching scans, resolves only after 3 clear scans, transients never flap,
 * and stale (offline) readings are never judged.
 */
#[RequiresPhpExtension('pdo_sqlite')]
class ThresholdAlertsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-03 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_breach_opens_only_after_three_consecutive_scans(): void
    {
        $meter = $this->meterWithState(voltage: 255.0); // above the 250 limit
        MeterAlertSetting::create(['device_id' => $meter->id, 'voltage_high' => 250]);

        $this->artisan('alerts:scan-thresholds');
        $this->artisan('alerts:scan-thresholds');
        $this->assertSame(0, $this->openCount($meter, 'threshold_voltage_high'), 'must not open before debounce');

        $this->artisan('alerts:scan-thresholds'); // 3rd consecutive breach

        $this->assertSame(1, $this->openCount($meter, 'threshold_voltage_high'));
        $this->assertDatabaseHas('alert_events', [
            'device_id' => $meter->id, 'alert_type' => 'threshold_voltage_high',
            'severity' => 'critical', 'status' => 'open',
        ]);
    }

    public function test_a_transient_spike_never_opens(): void
    {
        $meter = $this->meterWithState(voltage: 255.0);
        MeterAlertSetting::create(['device_id' => $meter->id, 'voltage_high' => 250]);

        $this->artisan('alerts:scan-thresholds');
        $this->artisan('alerts:scan-thresholds');           // 2 breaches…
        $meter->latestState->update(['voltage' => 230.0]);  // …then back to normal
        $this->artisan('alerts:scan-thresholds');           // streak resets
        $meter->latestState->update(['voltage' => 255.0]);
        $this->artisan('alerts:scan-thresholds');
        $this->artisan('alerts:scan-thresholds');           // only 2 consecutive again

        $this->assertSame(0, $this->openCount($meter, 'threshold_voltage_high'));
    }

    public function test_recovery_resolves_after_three_clear_scans(): void
    {
        $meter = $this->meterWithState(voltage: 255.0);
        MeterAlertSetting::create(['device_id' => $meter->id, 'voltage_high' => 250]);

        foreach (range(1, 3) as $i) {
            $this->artisan('alerts:scan-thresholds');
        }
        $this->assertSame(1, $this->openCount($meter, 'threshold_voltage_high'));

        $meter->latestState->update(['voltage' => 230.0]);
        $this->artisan('alerts:scan-thresholds');
        $this->artisan('alerts:scan-thresholds');
        $this->assertSame(1, $this->openCount($meter, 'threshold_voltage_high'), 'must not resolve before debounce');

        $this->artisan('alerts:scan-thresholds'); // 3rd clear scan

        $this->assertSame(0, $this->openCount($meter, 'threshold_voltage_high'));
        $this->assertDatabaseHas('alert_events', [
            'device_id' => $meter->id, 'alert_type' => 'threshold_voltage_high', 'status' => 'resolved',
        ]);
    }

    public function test_stale_readings_are_not_judged(): void
    {
        // Breaching voltage but the reading is 30 minutes old → offline territory.
        $meter = $this->meterWithState(voltage: 255.0, receivedAt: now()->subMinutes(30));
        MeterAlertSetting::create(['device_id' => $meter->id, 'voltage_high' => 250]);

        foreach (range(1, 5) as $i) {
            $this->artisan('alerts:scan-thresholds');
        }

        $this->assertSame(0, AlertEvent::where('device_id', $meter->id)->count());
    }

    public function test_power_limit_compares_kw_against_watt_reading(): void
    {
        $meter = $this->meterWithState(voltage: 230.0, power: 5500.0); // 5.5 kW
        MeterAlertSetting::create(['device_id' => $meter->id, 'power_max_kw' => 5]);

        foreach (range(1, 3) as $i) {
            $this->artisan('alerts:scan-thresholds');
        }

        $this->assertSame(1, $this->openCount($meter, 'threshold_power_max'));
    }

    public function test_low_pf_opens_and_unconfigured_checks_stay_silent(): void
    {
        $meter = $this->meterWithState(voltage: 255.0, pf: 0.70); // voltage breaching too, but not configured
        MeterAlertSetting::create(['device_id' => $meter->id, 'pf_min' => 0.85]);

        foreach (range(1, 3) as $i) {
            $this->artisan('alerts:scan-thresholds');
        }

        $this->assertSame(1, $this->openCount($meter, 'threshold_pf_min'));
        $this->assertSame(0, $this->openCount($meter, 'threshold_voltage_high'), 'unconfigured check must not alert');
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    private function openCount(Device $meter, string $type): int
    {
        return AlertEvent::where('device_id', $meter->id)
            ->where('alert_type', $type)->where('status', 'open')->count();
    }

    private function meterWithState(float $voltage, float $power = 100.0, float $pf = 0.95, ?Carbon $receivedAt = null): Device
    {
        $owner = User::factory()->create();

        $meter = Device::create([
            'code' => 'meter-'.fake()->unique()->slug(), 'name' => 'Meter '.fake()->unique()->word(),
            'type' => 'meter', 'mqtt_topic' => 'meters/'.fake()->unique()->slug(), 'is_active' => true,
            'user_id' => $owner->id,
        ]);

        LatestMeterState::create([
            'device_id'   => $meter->id,
            'ts'          => 1751500000,
            'voltage'     => $voltage,
            'power'       => $power,
            'pf'          => $pf,
            'received_at' => $receivedAt ?? now(),
        ]);

        return $meter->load('latestState');
    }
}
