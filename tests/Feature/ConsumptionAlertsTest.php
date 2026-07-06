<?php

namespace Tests\Feature;

use App\Models\AlertEvent;
use App\Models\Device;
use App\Models\MeterAlertSetting;
use App\Models\MeterDailyConsumption;
use App\Models\MeterMonthlyConsumption;
use App\Models\PendingAlertNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;

/**
 * alerts:scan-consumption — budget alerts derived from the rollups: warning at
 * the warn %, promotion to critical at 100%, auto-resolve when usage falls back,
 * daily-budget breach, and integration into the delivery buffer.
 */
#[RequiresPhpExtension('pdo_sqlite')]
class ConsumptionAlertsTest extends TestCase
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

    public function test_monthly_budget_warns_then_promotes_then_resolves(): void
    {
        $meter = $this->meter();
        MeterAlertSetting::create([
            'device_id' => $meter->id, 'monthly_budget_kwh' => 100, 'monthly_budget_warn_pct' => 80,
        ]);
        $month = MeterMonthlyConsumption::create([
            'device_id' => $meter->id, 'period_start' => '2026-07-01', 'units_kwh' => 85, // 85% → warning
        ]);

        $this->artisan('alerts:scan-consumption')->assertExitCode(0);
        $this->assertDatabaseHas('alert_events', [
            'device_id' => $meter->id, 'alert_type' => 'consumption_budget', 'severity' => 'warning', 'status' => 'open',
        ]);
        // It also entered the delivery buffer (owner is a recipient).
        $this->assertGreaterThan(0, PendingAlertNotification::count());

        // Usage rises past 100% → promote to critical, old warning resolved, one open.
        $month->update(['units_kwh' => 105]);
        $this->artisan('alerts:scan-consumption');
        $this->assertDatabaseHas('alert_events', ['alert_type' => 'consumption_budget', 'severity' => 'critical', 'status' => 'open']);
        $this->assertDatabaseHas('alert_events', ['alert_type' => 'consumption_budget', 'severity' => 'warning', 'status' => 'resolved']);
        $this->assertSame(1, AlertEvent::where('device_id', $meter->id)->where('alert_type', 'consumption_budget')->where('status', 'open')->count());

        // Usage falls below the warn line (e.g. new month) → resolve, nothing open.
        $month->update(['units_kwh' => 5]);
        $this->artisan('alerts:scan-consumption');
        $this->assertSame(0, AlertEvent::where('device_id', $meter->id)->where('alert_type', 'consumption_budget')->where('status', 'open')->count());
    }

    public function test_daily_budget_opens_when_exceeded(): void
    {
        $meter = $this->meter();
        MeterAlertSetting::create(['device_id' => $meter->id, 'daily_budget_kwh' => 12]);
        MeterDailyConsumption::create(['device_id' => $meter->id, 'period_date' => '2026-07-03', 'units_kwh' => 13.5]);

        $this->artisan('alerts:scan-consumption');

        $this->assertDatabaseHas('alert_events', [
            'device_id' => $meter->id, 'alert_type' => 'consumption_daily', 'severity' => 'warning', 'status' => 'open',
        ]);
    }

    public function test_anomaly_opens_when_today_far_exceeds_baseline(): void
    {
        $meter = $this->meter();
        MeterAlertSetting::create([
            'device_id' => $meter->id, 'anomaly_enabled' => true, 'anomaly_multiplier' => 2.0,
        ]);

        // 4 completed days of ~2 kWh baseline, today at 5 kWh (2.5×) → warning.
        foreach (['2026-06-29', '2026-06-30', '2026-07-01', '2026-07-02'] as $day) {
            MeterDailyConsumption::create(['device_id' => $meter->id, 'period_date' => $day, 'units_kwh' => 2.0]);
        }
        MeterDailyConsumption::create(['device_id' => $meter->id, 'period_date' => '2026-07-03', 'units_kwh' => 5.0]);

        $this->artisan('alerts:scan-consumption');

        $this->assertDatabaseHas('alert_events', [
            'device_id' => $meter->id, 'alert_type' => 'consumption_anomaly', 'severity' => 'warning', 'status' => 'open',
        ]);
    }

    public function test_anomaly_needs_enough_history(): void
    {
        $meter = $this->meter();
        MeterAlertSetting::create([
            'device_id' => $meter->id, 'anomaly_enabled' => true, 'anomaly_multiplier' => 2.0,
        ]);

        // Only 2 prior days (< 3 required) — even a huge today must NOT alert.
        MeterDailyConsumption::create(['device_id' => $meter->id, 'period_date' => '2026-07-01', 'units_kwh' => 2.0]);
        MeterDailyConsumption::create(['device_id' => $meter->id, 'period_date' => '2026-07-02', 'units_kwh' => 2.0]);
        MeterDailyConsumption::create(['device_id' => $meter->id, 'period_date' => '2026-07-03', 'units_kwh' => 50.0]);

        $this->artisan('alerts:scan-consumption');

        $this->assertSame(0, AlertEvent::where('device_id', $meter->id)->where('alert_type', 'consumption_anomaly')->count());
    }

    public function test_anomaly_resolves_when_usage_normalises(): void
    {
        $meter = $this->meter();
        MeterAlertSetting::create([
            'device_id' => $meter->id, 'anomaly_enabled' => true, 'anomaly_multiplier' => 2.0,
        ]);
        foreach (['2026-06-30', '2026-07-01', '2026-07-02'] as $day) {
            MeterDailyConsumption::create(['device_id' => $meter->id, 'period_date' => $day, 'units_kwh' => 2.0]);
        }
        $today = MeterDailyConsumption::create(['device_id' => $meter->id, 'period_date' => '2026-07-03', 'units_kwh' => 5.0]);

        $this->artisan('alerts:scan-consumption'); // opens
        $today->update(['units_kwh' => 2.1]);      // e.g. correction / next-day rollover analogue
        $this->artisan('alerts:scan-consumption'); // resolves

        $this->assertSame(0, AlertEvent::where('device_id', $meter->id)
            ->where('alert_type', 'consumption_anomaly')->where('status', 'open')->count());
    }

    public function test_no_budget_configured_opens_nothing(): void
    {
        $meter = $this->meter();
        MeterAlertSetting::create(['device_id' => $meter->id]); // no budgets set
        MeterMonthlyConsumption::create(['device_id' => $meter->id, 'period_start' => '2026-07-01', 'units_kwh' => 999]);

        $this->artisan('alerts:scan-consumption');

        $this->assertSame(0, AlertEvent::where('device_id', $meter->id)->count());
    }

    private function meter(): Device
    {
        $owner = User::factory()->create();

        return Device::create([
            'code' => 'meter-'.fake()->unique()->slug(), 'name' => 'Meter '.fake()->unique()->word(),
            'type' => 'meter', 'mqtt_topic' => 'meters/'.fake()->unique()->slug(), 'is_active' => true,
            'user_id' => $owner->id,
        ]);
    }
}
