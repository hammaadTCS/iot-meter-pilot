<?php

namespace App\Console\Commands;

use App\Events\AlertOpened;
use App\Events\AlertResolved;
use App\Models\AlertEvent;
use App\Models\Device;
use App\Models\MeterAlertSetting;
use App\Models\MeterDailyConsumption;
use App\Models\MeterMonthlyConsumption;
use Illuminate\Console\Command;

/**
 * Consumption alert detector — opens/resolves budget alerts from the
 * pre-aggregated rollups (never a raw scan) for meters that opted in.
 *
 * Off the ingestion hot path (scheduled), cheap (reads one rollup row per
 * meter), idempotent (open-once + promote/demote to the desired severity), and
 * self-resolving (a new month/day resets usage, so alerts clear automatically).
 * Emits into the shared delivery pipeline via AlertOpened/AlertResolved.
 *
 *   monthly_budget → alert_type "consumption_budget" (warning at N%, critical at 100%)
 *   daily_budget   → alert_type "consumption_daily"   (warning when exceeded)
 *   (usage anomaly is added in a later step)
 */
class ScanConsumptionAlerts extends Command
{
    protected $signature = 'alerts:scan-consumption';

    protected $description = 'Open/resolve consumption budget alerts from the rollups for opted-in meters';

    public function handle(): int
    {
        $settings = MeterAlertSetting::query()
            ->where(fn ($q) => $q
                ->whereNotNull('monthly_budget_kwh')
                ->orWhereNotNull('daily_budget_kwh')
                ->orWhere('anomaly_enabled', true))
            ->with('device')
            ->get();

        foreach ($settings as $setting) {
            if (! $setting->device) {
                continue;
            }
            $this->checkMonthlyBudget($setting);
            $this->checkDailyBudget($setting);
            $this->checkAnomaly($setting);
        }

        $this->info("Consumption alerts scanned for {$settings->count()} meter(s).");

        return self::SUCCESS;
    }

    private function checkMonthlyBudget(MeterAlertSetting $setting): void
    {
        $budget = (float) $setting->monthly_budget_kwh;
        if ($setting->monthly_budget_kwh === null || $budget <= 0) {
            return;
        }

        $used = (float) (MeterMonthlyConsumption::query()
            ->where('device_id', $setting->device_id)
            ->whereDate('period_start', now()->startOfMonth()->toDateString())
            ->value('units_kwh') ?? 0);

        $pct  = $used / $budget;
        $warn = max(1, (int) $setting->monthly_budget_warn_pct) / 100;

        [$severity, $message] = match (true) {
            $pct >= 1.0  => ['critical', sprintf('Monthly budget exceeded — %.3f of %.3f kWh (%d%%).', $used, $budget, round($pct * 100))],
            $pct >= $warn => ['warning', sprintf('Monthly budget %d%% used — %.3f of %.3f kWh.', round($pct * 100), $used, $budget)],
            default       => [null, null],
        };

        $this->reconcile($setting->device, 'consumption_budget', $severity, $message, [
            'used_kwh'   => round($used, 3),
            'budget_kwh' => round($budget, 3),
            'pct'        => round($pct * 100, 1),
        ]);
    }

    private function checkDailyBudget(MeterAlertSetting $setting): void
    {
        $budget = (float) $setting->daily_budget_kwh;
        if ($setting->daily_budget_kwh === null || $budget <= 0) {
            return;
        }

        $used = (float) (MeterDailyConsumption::query()
            ->where('device_id', $setting->device_id)
            ->whereDate('period_date', now()->startOfDay()->toDateString())
            ->value('units_kwh') ?? 0);

        $severity = $used >= $budget ? 'warning' : null;
        $message  = $severity
            ? sprintf('Daily budget exceeded — %.3f of %.3f kWh today.', $used, $budget)
            : null;

        $this->reconcile($setting->device, 'consumption_daily', $severity, $message, [
            'used_kwh'   => round($used, 3),
            'budget_kwh' => round($budget, 3),
        ]);
    }

    /**
     * Usage anomaly — today's usage well above the recent daily baseline.
     *
     * Baseline = average of the last 7 completed days (needs ≥ 3 days of history
     * to be meaningful). "Smart" but still cheap — all from the daily rollup.
     * Auto-resolves at the next day rollover (today resets low).
     */
    private function checkAnomaly(MeterAlertSetting $setting): void
    {
        if (! $setting->anomaly_enabled) {
            return;
        }

        $multiplier = (float) $setting->anomaly_multiplier;
        if ($multiplier <= 1) {
            return;
        }

        $today = now()->startOfDay();

        $recent = MeterDailyConsumption::query()
            ->where('device_id', $setting->device_id)
            ->whereDate('period_date', '<', $today->toDateString())
            ->whereDate('period_date', '>=', $today->copy()->subDays(7)->toDateString())
            ->pluck('units_kwh');

        $baseline = (float) ($recent->avg() ?? 0);

        // Not enough history, or a flat/zero baseline → no anomaly (and clear any open one).
        if ($recent->count() < 3 || $baseline <= 0) {
            $this->reconcile($setting->device, 'consumption_anomaly', null, null, []);

            return;
        }

        $used = (float) (MeterDailyConsumption::query()
            ->where('device_id', $setting->device_id)
            ->whereDate('period_date', $today->toDateString())
            ->value('units_kwh') ?? 0);

        $severity = $used >= $multiplier * $baseline ? 'warning' : null;
        $message  = $severity
            ? sprintf('Unusual usage — %.3f kWh today vs ~%.3f kWh typical (%.1f×).', $used, $baseline, $used / $baseline)
            : null;

        $this->reconcile($setting->device, 'consumption_anomaly', $severity, $message, [
            'used_kwh'     => round($used, 3),
            'baseline_kwh' => round($baseline, 3),
            'multiplier'   => $multiplier,
        ]);
    }

    /**
     * Reconcile a device's single-type alert to the desired severity:
     * open it if needed, promote/demote on a severity change, or resolve when the
     * condition clears. Fires the shared pipeline events.
     */
    private function reconcile(Device $device, string $alertType, ?string $severity, ?string $message, array $context): void
    {
        $open = AlertEvent::query()
            ->where('device_id', $device->id)
            ->where('alert_type', $alertType)
            ->where('status', 'open')
            ->first();

        if ($severity === null) {
            if ($open) {
                $this->resolve($open);
            }

            return;
        }

        if ($open && $open->severity === $severity) {
            return; // already open at the desired severity — nothing to do
        }

        if ($open) {
            $this->resolve($open); // severity changed → close the old one first
        }

        $alert = AlertEvent::create([
            'device_id'    => $device->id,
            'device_type'  => $device->type,
            'alert_type'   => $alertType,
            'severity'     => $severity,
            'status'       => 'open',
            'message'      => $message,
            'context'      => $context,
            'triggered_at' => now(),
        ]);

        event(new AlertOpened($alert));
    }

    private function resolve(AlertEvent $alert): void
    {
        $alert->forceFill(['status' => 'resolved', 'resolved_at' => now()])->save();

        event(new AlertResolved($alert));
    }
}
