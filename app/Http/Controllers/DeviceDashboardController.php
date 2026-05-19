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

        return view('devices.dashboards.meter', [
            'device'             => $device,
            'allDevices'         => collect([]), // picker removed; kept for API compat
            'currentSnapshot'    => $device->currentSnapshot(),
            'deviceAvailability' => $device->availabilitySnapshot(),
            'deviceIssue'        => $device->issueSnapshot(),
            'recentReadings'     => $recentReadings,
        ]);
    }
}
