<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class DeviceManagementController extends Controller
{
    public function index()
    {
        $user = Auth::user();

        $query = Device::query()
            ->with('user')
            ->orderBy('name');

        if (! $user->isAdminOrAbove()) {
            $query->where('user_id', $user->id);
        }

        return view('devices-manage', [
            'devices' => $query->get(),
        ]);
    }

    public function create()
    {
        $users = Auth::user()->isAdminOrAbove()
            ? User::orderBy('name')->get(['id', 'name', 'email', 'role'])
            : collect([]);

        return view('devices-create', ['users' => $users]);
    }

    public function store(Request $request)
    {
        $user = Auth::user();

        $rules = [
            'name'       => 'required|string|max:255',
            'code'       => ['required', 'string', 'max:255', Rule::unique('devices', 'code')->where('user_id', $user->isAdminOrAbove() ? $request->input('user_id', $user->id) : $user->id)],
            'type'       => 'required|string|in:meter,sensor,smart_plug,camera,thermostat,lock',
            'mqtt_topic'         => 'required|string|max:255',
            'availability_topic' => 'nullable|string|max:255',
            'is_active'          => 'boolean',
        ];

        if ($user->isAdminOrAbove()) {
            $rules['user_id'] = 'required|integer|exists:users,id';
        }

        $validated = $request->validate($rules);

        $validated['is_active'] = $request->boolean('is_active', true);
        $validated['user_id']   = $user->isAdminOrAbove()
            ? (int) $validated['user_id']
            : $user->id;

        Device::create($validated);

        return redirect()->route('devices.manage')
            ->with('success', 'Device created successfully!');
    }

    public function edit(Device $device)
    {
        $user = Auth::user();

        if (! $user->isAdminOrAbove() && $device->user_id !== $user->id) {
            abort(403, 'Unauthorized action.');
        }

        $users = $user->isAdminOrAbove()
            ? User::orderBy('name')->get(['id', 'name', 'email', 'role'])
            : collect([]);

        return view('devices-edit', ['device' => $device, 'users' => $users]);
    }

    public function update(Request $request, Device $device)
    {
        $user = Auth::user();

        if (! $user->isAdminOrAbove() && $device->user_id !== $user->id) {
            abort(403, 'Unauthorized action.');
        }

        $rules = [
            'name'       => 'required|string|max:255',
            'code'       => 'required|string|max:255',
            'type'                 => 'required|string|in:meter,sensor,smart_plug,camera,thermostat,lock',
            'mqtt_topic'           => 'required|string|max:255',
            'availability_topic'   => 'nullable|string|max:255',
            'is_active'            => 'boolean',
        ];

        if ($user->isAdminOrAbove()) {
            $rules['user_id'] = 'required|integer|exists:users,id';
        }

        $validated = $request->validate($rules);

        $validated['is_active'] = $request->boolean('is_active', false);

        if (! $user->isAdminOrAbove()) {
            unset($validated['user_id']);
        }

        $device->update($validated);

        return redirect()->route('devices.manage')
            ->with('success', 'Device updated successfully!');
    }

    public function destroy(Device $device)
    {
        $user = Auth::user();

        if (! $user->isAdminOrAbove() && $device->user_id !== $user->id) {
            abort(403, 'Unauthorized action.');
        }

        $device->delete();

        return redirect()->route('devices.manage')
            ->with('success', 'Device deleted successfully!');
    }
}
