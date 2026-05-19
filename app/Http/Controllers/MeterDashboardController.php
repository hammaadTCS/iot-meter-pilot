<?php

namespace App\Http\Controllers;

use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MeterDashboardController extends Controller
{
    /**
     * Show the dashboard for one selected meter while ingestion continues
     * storing data for every active meter in the background.
     */
    public function show(Request $request)
    {
        $user = Auth::user();

        // Only meter devices should appear in the dashboard selector.
        $query = Device::query()
            ->where('type', 'meter')
            ->where('is_active', true)
            ->orderBy('name');

        if (! $user->isAdminOrAbove()) {
            $query->where('user_id', $user->id);
        }

        $devices = $query->get();

        if ($devices->isEmpty()) {
            return view('meter-dashboard-empty');
        }

        // Allow deep-linking directly to a selected meter via /?device=<id>.
        $selectedId = (int) $request->query('device', 0);

        // Fallback order:
        // 1. Explicit device query param
        // 2. Legacy env-selected pilot meter
        // 3. First active meter in the list
        $device = $devices->firstWhere('id', $selectedId)
            ?? $devices->firstWhere('code', env('METER_DEVICE_CODE'))
            ?? $devices->first();

        $device->load('latestState');

        // Keep a small recent slice for the initial table render. The charts and
        // table are still refreshed through the per-device readings API.
        $recentReadings = $device->readings()
            ->latest('ts')
            ->limit(20)
            ->get()
            ->reverse()
            ->values();

        return view('meter-dashboard', [
            'devices' => $devices,
            'device' => $device,
            'currentSnapshot' => $device->currentSnapshot(),
            'deviceAvailability' => $device->availabilitySnapshot(),
            'deviceIssue' => $device->issueSnapshot(),
            'recentReadings' => $recentReadings,
        ]);
    }
}
