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
        if ($user->isAdminOrAbove()) {
            $systemStats = [
                'total_users'    => User::count(),
                'total_devices'  => Device::count(),
                'online_devices' => Device::whereNotNull('last_seen_at')
                    ->where('last_seen_at', '>=', now()->subMinutes(5))
                    ->count(),
                'active_devices' => Device::where('is_active', true)->count(),
            ];
        }

        $query = Device::with('user')->orderByDesc('last_seen_at');
        if (!$user->isAdminOrAbove()) {
            $query->where('user_id', $user->id);
        }
        $recentDevices = $query->limit(8)->get();

        return view('dashboard', compact('myDevicesCount', 'myActiveCount', 'systemStats', 'recentDevices'));
    }
}
