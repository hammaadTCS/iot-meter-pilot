<?php

namespace App\Console\Commands;

use App\Events\AlertOpened;
use App\Events\AlertResolved;
use App\Models\AlertEvent;
use App\Models\MeterAlertSetting;
use App\Models\MeterThresholdState;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Electrical threshold detector — evaluates each opted-in meter's LATEST cached
 * reading against its configured limits (over/under-voltage, max power, min
 * power factor) and opens/resolves alerts through the shared pipeline.
 *
 * Design decisions (why it is reliable and fast):
 *  - Runs every minute as a scheduled scan of `latest_meter_states` — a single
 *    indexed row per meter — deliberately OFF the MQTT ingestion hot path, so
 *    alerting can never slow data intake.
 *  - **Hysteresis**: a check must breach for DEBOUNCE consecutive scans to open,
 *    and be back in range for DEBOUNCE consecutive scans to resolve (counters in
 *    meter_threshold_states, durable across restarts). A momentary spike never
 *    pages anyone; a genuine fault alerts within ~DEBOUNCE minutes.
 *  - **Stale data is not evaluated**: if the latest reading is older than
 *    STALE_AFTER_MINUTES the meter is effectively offline (the health detector
 *    owns that condition) and judging thresholds on old data would be wrong —
 *    streaks freeze until fresh telemetry arrives.
 *  - One alert type per check (open-once, auto-resolve), all severity critical —
 *    these guard equipment safety.
 */
class ScanThresholdAlerts extends Command
{
    protected $signature = 'alerts:scan-thresholds';

    protected $description = 'Open/resolve electrical threshold alerts (voltage/power/pf) for opted-in meters';

    /** Consecutive breaching (or clear) scans required to open (or resolve). */
    private const DEBOUNCE = 3;

    /** Latest readings older than this are not judged (device effectively offline). */
    private const STALE_AFTER_MINUTES = 10;

    public function handle(): int
    {
        $settings = MeterAlertSetting::query()
            ->where(fn ($q) => $q
                ->whereNotNull('voltage_high')
                ->orWhereNotNull('voltage_low')
                ->orWhereNotNull('power_max_kw')
                ->orWhereNotNull('pf_min'))
            ->with(['device.latestState'])
            ->get();

        $evaluated = 0;

        foreach ($settings as $setting) {
            $device = $setting->device;
            $state  = $device?->latestState;

            if (! $device || ! $state) {
                continue;
            }

            // Freshness guard — don't judge thresholds on a dead meter's old data.
            $recordedAt = $state->received_at ? Carbon::parse($state->received_at) : null;
            if (! $recordedAt || $recordedAt->lt(now()->subMinutes(self::STALE_AFTER_MINUTES))) {
                continue;
            }

            $evaluated++;

            // Each check: [configured limit, current value, breached?, human message].
            $voltage = $state->voltage !== null ? (float) $state->voltage : null;
            $powerW  = $state->power !== null ? (float) $state->power : null;
            $pf      = $state->pf !== null ? (float) $state->pf : null;

            $this->evaluate($setting, 'voltage_high', $voltage,
                $setting->voltage_high !== null && $voltage !== null && $voltage > (float) $setting->voltage_high,
                fn () => sprintf('Voltage %.1f V above the %.1f V limit.', $voltage, $setting->voltage_high));

            $this->evaluate($setting, 'voltage_low', $voltage,
                $setting->voltage_low !== null && $voltage !== null && $voltage < (float) $setting->voltage_low,
                fn () => sprintf('Voltage %.1f V below the %.1f V limit.', $voltage, $setting->voltage_low));

            $this->evaluate($setting, 'power_max', $powerW,
                $setting->power_max_kw !== null && $powerW !== null && $powerW > (float) $setting->power_max_kw * 1000,
                fn () => sprintf('Power %.2f kW above the %.2f kW limit.', $powerW / 1000, $setting->power_max_kw));

            $this->evaluate($setting, 'pf_min', $pf,
                $setting->pf_min !== null && $pf !== null && $pf < (float) $setting->pf_min,
                fn () => sprintf('Power factor %.2f below the %.2f minimum.', $pf, $setting->pf_min));
        }

        $this->info("Threshold alerts evaluated for {$evaluated} meter(s).");

        return self::SUCCESS;
    }

    /**
     * Advance one check's hysteresis and open/resolve its alert when a streak
     * crosses the debounce. $value null (field absent from telemetry) or the
     * check unconfigured (breach computed false with no limit) both count as
     * "clear" — an unconfigured check must resolve any leftover alert.
     */
    private function evaluate(
        MeterAlertSetting $setting,
        string $checkKey,
        ?float $value,
        bool $breached,
        callable $message,
    ): void {
        // Unconfigured check with no history and no open alert: skip the
        // bookkeeping entirely (the common case — avoids one row per check
        // per meter that never configured it).
        $configured = match ($checkKey) {
            'voltage_high' => $setting->voltage_high !== null,
            'voltage_low'  => $setting->voltage_low !== null,
            'power_max'    => $setting->power_max_kw !== null,
            'pf_min'       => $setting->pf_min !== null,
            default        => false,
        };

        $alertType = 'threshold_' . $checkKey;

        $open = AlertEvent::query()
            ->where('device_id', $setting->device_id)
            ->where('alert_type', $alertType)
            ->where('status', 'open')
            ->first();

        if (! $configured && ! $open) {
            return;
        }

        $state = MeterThresholdState::firstOrNew([
            'device_id' => $setting->device_id,
            'check_key' => $checkKey,
        ]);

        if ($breached) {
            $state->breach_streak = (int) $state->breach_streak + 1;
            $state->clear_streak = 0;
        } else {
            $state->clear_streak = (int) $state->clear_streak + 1;
            $state->breach_streak = 0;
        }
        $state->save();

        if (! $open && $state->breach_streak >= self::DEBOUNCE) {
            $alert = AlertEvent::create([
                'device_id'    => $setting->device_id,
                'device_type'  => $setting->device?->type ?? 'meter',
                'alert_type'   => $alertType,
                'severity'     => 'critical',
                'status'       => 'open',
                'message'      => $message(),
                'context'      => [
                    'check'  => $checkKey,
                    'value'  => $value,
                    'streak' => $state->breach_streak,
                ],
                'triggered_at' => now(),
            ]);

            event(new AlertOpened($alert));

            return;
        }

        if ($open && $state->clear_streak >= self::DEBOUNCE) {
            $open->forceFill(['status' => 'resolved', 'resolved_at' => now()])->save();

            event(new AlertResolved($open));
        }
    }
}
