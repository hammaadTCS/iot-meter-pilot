<?php

namespace App\Http\Controllers;

use App\Models\Device;

class MeterDashboardController extends Controller
{
    /**
     * Show the dashboard page for the single pilot meter.
     */
    public function show()
    {
        // Load the selected device and its latest state.
        $device = Device::with('latestState')
            ->where('code', env('METER_DEVICE_CODE'))
            ->firstOrFail();

        // Load recent historical rows for the table.
        $recentReadings = $device->readings()
            ->latest('ts')
            ->limit(20)
            ->get()
            ->reverse()
            ->values();

        return view('meter-dashboard', [
            'device' => $device,
            'recentReadings' => $recentReadings,
        ]);
    }
}
