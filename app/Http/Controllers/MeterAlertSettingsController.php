<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\MeterAlertSetting;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Per-meter, opt-in alert configuration — the toggles and thresholds a user
 * chooses for one meter.
 *
 * Authorization (hybrid FGAC): alerts.settings_own (built-in) for the owner,
 * or devices.edit_any for fleet staff. Deliberately NOT DevicePolicy::update —
 * configuring alert triggers is a different capability from editing the
 * device's identity/topics, and must survive e.g. revoking meter.rename.
 */
class MeterAlertSettingsController extends Controller
{
    use AuthorizesRequests;

    public function edit(Device $device): View
    {
        $this->authorizeAlertSettings($device);

        return view('devices.alerts', [
            'device'   => $device,
            'settings' => MeterAlertSetting::forDevice($device),
        ]);
    }

    public function update(Request $request, Device $device): RedirectResponse
    {
        $this->authorizeAlertSettings($device);

        $validated = $request->validate([
            'monthly_budget_kwh'      => ['nullable', 'numeric', 'min:0'],
            'monthly_budget_warn_pct' => ['required', 'integer', 'min:1', 'max:99'],
            'daily_budget_kwh'        => ['nullable', 'numeric', 'min:0'],
            'anomaly_multiplier'      => ['required', 'numeric', 'min:1.1', 'max:10'],
            'voltage_high'            => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'voltage_low'             => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'power_max_kw'            => ['nullable', 'numeric', 'min:0'],
            'pf_min'                  => ['nullable', 'numeric', 'min:0', 'max:1'],
        ]);

        // A blank value means "that trigger is off" (stored as null). Booleans
        // (anomaly, offline) come from their checkboxes.
        MeterAlertSetting::updateOrCreate(
            ['device_id' => $device->id],
            [
                'monthly_budget_kwh'      => $request->filled('monthly_budget_kwh') ? $validated['monthly_budget_kwh'] : null,
                'monthly_budget_warn_pct' => $validated['monthly_budget_warn_pct'],
                'daily_budget_kwh'        => $request->filled('daily_budget_kwh') ? $validated['daily_budget_kwh'] : null,
                'anomaly_enabled'         => $request->boolean('anomaly_enabled'),
                'anomaly_multiplier'      => $validated['anomaly_multiplier'],
                'voltage_high'            => $request->filled('voltage_high') ? $validated['voltage_high'] : null,
                'voltage_low'             => $request->filled('voltage_low') ? $validated['voltage_low'] : null,
                'power_max_kw'            => $request->filled('power_max_kw') ? $validated['power_max_kw'] : null,
                'pf_min'                  => $request->filled('pf_min') ? $validated['pf_min'] : null,
                'offline_enabled'         => $request->boolean('offline_enabled'),
            ],
        );

        return back()->with('status', 'alert-settings-updated');
    }

    /**
     * Owner with the built-in alerts.settings_own slug, or fleet staff with
     * devices.edit_any. (Gate::before already admitted super admins.)
     */
    private function authorizeAlertSettings(Device $device): void
    {
        $user = request()->user();

        $allowed = $user->can('devices.edit_any')
            || ($user->can('alerts.settings_own') && $user->id === $device->user_id);

        abort_unless($allowed, 403, 'Missing alert-settings permission for this device.');
    }
}
