<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    public function index()
    {
        return response()->json(
            Device::orderBy('id', 'desc')->get()
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|max:255|unique:devices,code',
            'name' => 'required|string|max:255',
            'type' => 'required|string|max:255',
            'mqtt_topic' => 'required|string|max:255|unique:devices,mqtt_topic',
            'is_active' => 'nullable|boolean',
        ]);

        $device = Device::create([
            'code' => trim($validated['code']),
            'name' => trim($validated['name']),
            'type' => trim($validated['type']),
            'mqtt_topic' => trim($validated['mqtt_topic']),
            'is_active' => $request->boolean('is_active', true),
            'last_seen_at' => null,
        ]);

        return response()->json($device, 201);
    }

    public function show($id)
    {
        $device = Device::findOrFail($id);

        return response()->json($device);
    }

    public function readings($id)
    {
        $device = Device::with([
            'readings' => function ($query) {
                $query->latest()->limit(100);
            },
            'latestState',
        ])->findOrFail($id);

        return response()->json($device);
    }
}
