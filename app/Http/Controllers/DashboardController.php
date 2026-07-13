<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $user = Auth::user();

        $myDevicesCount = Device::where('user_id', $user->id)->count();
        $myActiveCount  = Device::where('user_id', $user->id)->where('is_active', true)->count();

        $systemStats = null;
        if ($user->can('dashboard.view_system_stats')) {
            $systemStats = [
                'total_users'    => User::count(),
                'total_devices'  => Device::count(),
                'online_devices' => Device::whereNotNull('last_seen_at')
                    ->where('last_seen_at', '>=', now()->subMinutes(5))
                    ->count(),
                'active_devices' => Device::where('is_active', true)->count(),
            ];
        }

        // Eager-load the owner (for the admin view) and the single-row latest
        // state so the device cards can render live Voltage / Power / Monthly
        // Units without an N+1 query per card. `latestState` only exists for
        // meters; for other device types the relation is simply null and the
        // card falls back to its identity-only layout.
        $query = Device::with(['user', 'latestState'])->orderByDesc('last_seen_at');
        if (! $user->can('devices.view_any')) {
            $query->where('user_id', $user->id);
        }
        $recentDevices = $query->limit(8)->get();

        return view('dashboard', compact('myDevicesCount', 'myActiveCount', 'systemStats', 'recentDevices'));
    }
}
