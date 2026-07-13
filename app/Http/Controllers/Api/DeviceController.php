<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class DeviceController extends Controller
{
    use AuthorizesRequests;
    /**
     * Return all configured devices for the current user (or all if admin).
     */
    public function index(Request $request)
    {
        $user = $request->user();

        // Fleet visibility is a permission, not a role.
        $query = $user->can('devices.view_any') ? Device::query() : Device::forUser($user);

        return response()->json(
            $query->orderBy('id', 'desc')->get()
        );
    }

    /**
     * Create a new device record and assign it to the current user.
     */
    public function store(Request $request)
    {
        $this->authorize('create', Device::class);

        $user = $request->user();
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
            'code' => [
                'required',
                'string',
                'max:255',
                // Code must be unique per user, not globally
                'unique:devices,code,null,id,user_id,' . $user->id,
            ],
            'name' => 'required|string|max:255',
            'type' => 'required|string|max:255',
            'mqtt_topic' => 'required|string|max:255|unique:devices,mqtt_topic',
            'availability_topic' => 'nullable|string|max:255|unique:devices,availability_topic|different:mqtt_topic',
            'is_active' => 'nullable|boolean',
        ]);

        $device = $user->devices()->create([
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
     * Return one device by route-model-bound id (with authorization).
     */
    public function show(Request $request, Device $device)
    {
        $this->authorize('view', $device);

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
     * Delete a device (with authorization).
     */
    public function destroy(Request $request, Device $device)
    {
        $this->authorize('delete', $device);

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
    public function readings(Request $request, $id)
    {
        $device = Device::findOrFail($id);
        $this->authorize('view', $device);

        $device->load([
            'readings' => function ($query) {
                $query->latest()->limit(100);
            },
            'latestState',
        ]);

        return response()->json($device);
    }

    /**
     * Return just the runtime status snapshots needed by the live dashboard.
     */
    public function status(Request $request, Device $device)
    {
        $this->authorize('view', $device);

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
