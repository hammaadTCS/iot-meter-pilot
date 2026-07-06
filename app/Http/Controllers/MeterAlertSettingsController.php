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
 * chooses for one meter. Authorized like editing the device itself (owner or
 * admin) via DevicePolicy::update.
 */
class MeterAlertSettingsController extends Controller
{
    use AuthorizesRequests;

    public function edit(Device $device): View
    {
        $this->authorize('update', $device);

        return view('devices.alerts', [
            'device'   => $device,
            'settings' => MeterAlertSetting::forDevice($device),
        ]);
    }

    public function update(Request $request, Device $device): RedirectResponse
    {
        $this->authorize('update', $device);

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
}
