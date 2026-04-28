<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeviceController extends Controller
{
    /**
     * Return all configured devices so the frontend can build selectors and
     * management lists.
     */
    public function index()
    {
        return response()->json(
            Device::orderBy('id', 'desc')->get()
        );
    }

    /**
     * Create a new device record from the management screen.
     */
    public function store(Request $request)
    {
        $mqttTopic = trim((string) $request->input('mqtt_topic'));
        $availabilityTopic = trim((string) $request->input('availability_topic', ''));

        $request->merge([
            'code' => trim((string) $request->input('code')),
            'name' => trim((string) $request->input('name')),
            'type' => trim((string) $request->input('type')),
            'mqtt_topic' => $mqttTopic,
            'availability_topic' => $availabilityTopic === '' ? null : $availabilityTopic,
        ]);

        $validated = $request->validate([
            'code' => 'required|string|max:255|unique:devices,code',
            'name' => 'required|string|max:255',
            'type' => 'required|string|max:255',
            'mqtt_topic' => 'required|string|max:255|unique:devices,mqtt_topic',
            'availability_topic' => 'nullable|string|max:255|unique:devices,availability_topic|different:mqtt_topic',
            'is_active' => 'nullable|boolean',
        ]);

        $device = Device::create([
            'code' => $validated['code'],
            'name' => $validated['name'],
            'type' => $validated['type'],
            'mqtt_topic' => $mqttTopic,
            'availability_topic' => $validated['availability_topic']
                ?? Device::deriveAvailabilityTopic($mqttTopic),
            'is_active' => $request->boolean('is_active', true),
            'last_seen_at' => null,
        ]);

        return response()->json($device->fresh(), 201);
    }

    /**
     * Return one device by route-model-bound id.
     */
    public function show(Device $device)
    {
        $device->load('latestState');

        return response()->json([
            'device' => $device,
            'health' => $device->healthSnapshot(),
            'availability' => $device->availabilitySnapshot(),
            'issue' => $device->issueSnapshot(),
            'current_snapshot' => $device->currentSnapshot(),
        ]);
    }

    /**
     * Delete a device and let the database cascade remove its related history.
     */
    public function destroy(Device $device)
    {
        DB::transaction(function () use ($device) {
            $device->delete();
        });

        return response()->json([
            'message' => 'Device deleted successfully.',
        ]);
    }

    /**
     * Return one device plus a small recent snapshot for quick inspection.
     */
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

    /**
     * Return just the runtime status snapshots needed by the live dashboard.
     */
    public function status(Device $device)
    {
        $device->load('latestState');

        return response()->json([
            'device_id' => $device->id,
            'health' => $device->healthSnapshot(),
            'availability' => $device->availabilitySnapshot(),
            'issue' => $device->issueSnapshot(),
            'current_snapshot' => $device->currentSnapshot(),
        ]);
    }
}
