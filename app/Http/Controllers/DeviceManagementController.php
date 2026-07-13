<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class DeviceManagementController extends Controller
{
    use AuthorizesRequests;

    public function index()
    {
        $user = Auth::user();

        $query = Device::query()
            ->with('user')
            ->orderBy('name');

        if (! $user->can('devices.view_any')) {
            $query->where('user_id', $user->id);
        }

        return view('devices-manage', [
            'devices' => $query->get(),
        ]);
    }

    public function create()
    {
        $this->authorize('create', Device::class);

        return view('devices-create', [
            'users'             => $this->assignableOwners(),
            // Only meter.self_provision (no devices.create): type locked to
            // meter, owner locked to self — the view renders the reduced form.
            'selfProvisionOnly' => $this->selfProvisionOnly(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', Device::class);

        $user = Auth::user();

        // Self-provision-only accounts: the in:meter rule below rejects any
        // other type, and $ownerId is forced to self — the server is the
        // authority regardless of what was POSTed.
        $canAssignOwner = $user->can('devices.assign_owner') && ! $this->selfProvisionOnly();
        $ownerId = $canAssignOwner
            ? (int) $request->input('user_id', $user->id)
            : $user->id;

        $rules = [
            'name'       => 'required|string|max:255',
            'code'       => ['required', 'string', 'max:255', Rule::unique('devices', 'code')->where('user_id', $ownerId)],
            'type'       => $this->selfProvisionOnly()
                ? 'required|string|in:meter'
                : 'required|string|in:meter,sensor,smart_plug,camera,thermostat,lock',
            'mqtt_topic'         => 'required|string|max:255',
            'availability_topic' => 'nullable|string|max:255',
            'is_active'          => 'boolean',
        ];

        if ($canAssignOwner) {
            $rules['user_id'] = 'required|integer|exists:users,id';
        }

        $validated = $request->validate($rules);

        $validated['is_active'] = $request->boolean('is_active', true);
        $validated['user_id']   = $ownerId;

        Device::create($validated);

        return redirect()->route('devices.manage')
            ->with('success', 'Device created successfully!');
    }

    public function edit(Device $device)
    {
        $this->authorize('update', $device);

        return view('devices-edit', [
            'device'   => $device,
            'users'    => $this->assignableOwners(),
            'nameOnly' => $this->nameOnly($device),
        ]);
    }

    public function update(Request $request, Device $device)
    {
        $this->authorize('update', $device);

        $user = Auth::user();

        // Rename-only mode (meter.rename without an edit permission): the
        // server discards everything except the name, regardless of what
        // was POSTed — hiding form fields is never the enforcement.
        if ($this->nameOnly($device)) {
            $validated = $request->validate(['name' => 'required|string|max:255']);

            $device->update(['name' => $validated['name']]);

            return redirect()->route('devices.manage')
                ->with('success', 'Device renamed successfully!');
        }

        $rules = [
            'name'       => 'required|string|max:255',
            'code'       => 'required|string|max:255',
            'type'                 => 'required|string|in:meter,sensor,smart_plug,camera,thermostat,lock',
            'mqtt_topic'           => 'required|string|max:255',
            'availability_topic'   => 'nullable|string|max:255',
            'is_active'            => 'boolean',
        ];

        if ($user->can('devices.assign_owner')) {
            $rules['user_id'] = 'required|integer|exists:users,id';
        }

        $validated = $request->validate($rules);

        $validated['is_active'] = $request->boolean('is_active', false);

        if (! $user->can('devices.assign_owner')) {
            unset($validated['user_id']);
        }

        $device->update($validated);

        return redirect()->route('devices.manage')
            ->with('success', 'Device updated successfully!');
    }

    public function destroy(Device $device)
    {
        $this->authorize('delete', $device);

        $device->delete();

        return redirect()->route('devices.manage')
            ->with('success', 'Device deleted successfully!');
    }

    /** Owner dropdown contents — only for users who may assign ownership. */
    private function assignableOwners()
    {
        return Auth::user()->can('devices.assign_owner')
            ? User::orderBy('name')->get(['id', 'name', 'email'])
            : collect([]);
    }

    private function selfProvisionOnly(): bool
    {
        $user = Auth::user();

        return ! $user->can('devices.create') && $user->can('meter.self_provision');
    }

    private function nameOnly(Device $device): bool
    {
        $user = Auth::user();

        return $device->type === 'meter'
            && $user->id === $device->user_id
            && $user->can('meter.rename')
            && ! $user->can('devices.edit_own')
            && ! $user->can('devices.edit_any');
    }
}
