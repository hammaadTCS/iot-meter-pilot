<?php

namespace App\Http\Controllers;

use App\Models\Device;

class DeviceManagementController extends Controller
{
    /**
     * Render a small management surface for creating and deleting meters.
     */
    public function index()
    {
        $devices = Device::query()
            ->where('type', 'meter')
            ->orderBy('name')
            ->get();

        return view('devices-manage', [
            'devices' => $devices,
        ]);
    }
}
