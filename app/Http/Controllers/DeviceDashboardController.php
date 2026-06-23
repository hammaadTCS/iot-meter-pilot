<?php

namespace App\Http\Controllers;

use App\Models\Device;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class DeviceDashboardController extends Controller
{
    public function show(Device $device): View
    {
        $user = Auth::user();

        if (!$user->isAdminOrAbove() && $device->user_id !== $user->id) {
            abort(403, 'You do not have access to this device.');
        }

        if (!$device->is_active) {
            return view('devices.dashboards.placeholder', [
                'device' => $device,
                'reason' => 'disabled',
            ]);
        }

        return match ($device->type) {
            'meter'  => $this->showMeter($device),
            default  => view('devices.dashboards.placeholder', compact('device')),
        };
    }

    private function showMeter(Device $device): View
    {
        $device->load('latestState');

        $recentReadings = $device->readings()
            ->latest('ts')
            ->limit(20)
            ->get()
            ->reverse()
            ->values();

        // Historical monthly consumption for the "Monthly Units" panel.
        //
        // These rows are maintained incrementally during MQTT ingestion (see
        // MeterPayloadProcessor::updateMonthlyConsumption), so this is a single
        // cheap, pre-aggregated query — never a scan of raw readings. We pull the
        // most recent 12 calendar months (newest first); the chart renders them
        // top-to-bottom with the current month on top.
        //
        // Each row is mapped to a flat, presentation-ready shape:
        //   - period_start as a plain 'Y-m-d' string. We format it explicitly
        //     rather than letting the model's `date` cast serialise it, because
        //     that cast emits a UTC datetime (e.g. "2026-05-31T19:00:00Z" for a
        //     non-UTC app timezone), which would corrupt the month label and the
        //     current-month match on the client.
        //   - units_kwh as a float, so the chart gets a clean number regardless
        //     of the driver (SQLite returns a float, MySQL a decimal string).
        $monthlyConsumption = $device->monthlyConsumptions()
            ->orderByDesc('period_start')
            ->limit(12)
            ->get(['period_start', 'units_kwh'])
            ->map(fn ($row) => [
                'period_start' => $row->period_start->format('Y-m-d'),
                'units_kwh'    => (float) $row->units_kwh,
            ]);

        return view('devices.dashboards.meter', [
            'device'             => $device,
            'allDevices'         => collect([]), // picker removed; kept for API compat
            'currentSnapshot'    => $device->currentSnapshot(),
            'deviceAvailability' => $device->availabilitySnapshot(),
            'deviceIssue'        => $device->issueSnapshot(),
            'recentReadings'     => $recentReadings,
            'monthlyConsumption' => $monthlyConsumption,
        ]);
    }
}
