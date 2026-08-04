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
            'users' => $this->assignableOwners(),
            // Only meter.self_provision (no devices.create): type locked to
            // meter, owner locked to self — the view renders the reduced form.
            'selfProvisionOnly' => $this->selfProvisionOnly(),
        ]);
    }

    /**
     * Validation rules for the two MQTT topic fields.
     *
     * A topic string is the ONLY thing that binds an inbound MQTT message to a
     * device row — MeterPayloadProcessor matches `mqtt_topic` and
     * MeterAvailabilityProcessor matches `availability_topic`, both by exact
     * string, both with ->first(). So a topic reused across two device rows is
     * a cross-tenant leak: whichever row the database returns first receives
     * another customer's readings or availability, and the rightful owner
     * silently stops receiving them.
     *
     * Uniqueness therefore has to hold ACROSS BOTH COLUMNS, not within each one:
     * the consumer subscribes to a device's data topic and its status topic, so
     * one device's availability_topic colliding with another's mqtt_topic is
     * just as exploitable as a same-column collision.
     *
     * This is defence in depth pending the claim flow in docs/DEVICE_CLAIMING.md,
     * which removes these fields from user input altogether by deriving both
     * topics server-side from an immutable device_uid.
     *
     * @param  int|null  $ignoreDeviceId  Row to exclude (the record being updated).
     */
    private function topicRules(?int $ignoreDeviceId = null): array
    {
        // Reject a value already used by ANY other device in EITHER column.
        $notUsedByAnotherDevice = function (string $attribute, mixed $value, callable $fail) use ($ignoreDeviceId) {
            $value = trim((string) $value);

            if ($value === '') {
                return;
            }

            $taken = Device::query()
                ->where(fn ($q) => $q->where('mqtt_topic', $value)->orWhere('availability_topic', $value))
                ->when($ignoreDeviceId, fn ($q) => $q->whereKeyNot($ignoreDeviceId))
                ->exists();

            if ($taken) {
                $fail('This MQTT topic is already in use by another device.');
            }
        };

        return [
            'mqtt_topic' => ['required', 'string', 'max:255', $notUsedByAnotherDevice],
            'availability_topic' => [
                'nullable',
                'string',
                'max:255',
                // A device's own two topics must differ, or its status messages
                // would be parsed as readings.
                'different:mqtt_topic',
                $notUsedByAnotherDevice,
            ],
        ];
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
            'name' => 'required|string|max:255',
            'code' => ['required', 'string', 'max:255', Rule::unique('devices', 'code')->where('user_id', $ownerId)],
            'type' => $this->selfProvisionOnly()
                ? 'required|string|in:meter'
                : 'required|string|in:meter,sensor,smart_plug,camera,thermostat,lock',
            'is_active' => 'boolean',
            ...$this->topicRules(),
        ];

        if ($canAssignOwner) {
            $rules['user_id'] = 'required|integer|exists:users,id';
        }

        $validated = $request->validate($rules);

        $validated['is_active'] = $request->boolean('is_active', true);
        $validated['user_id'] = $ownerId;

        Device::create($validated);

        return redirect()->route('devices.manage')
            ->with('success', 'Device created successfully!');
    }

    public function edit(Device $device)
    {
        $this->authorize('update', $device);

        return view('devices-edit', [
            'device' => $device,
            'users' => $this->assignableOwners(),
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

        // Code uniqueness is scoped to the RESULTING owner, which an
        // assign_owner holder may be changing in this same request.
        $targetOwnerId = $user->can('devices.assign_owner')
            ? (int) $request->input('user_id', $device->user_id)
            : (int) $device->user_id;

        $rules = [
            'name' => 'required|string|max:255',
            'code' => [
                'required', 'string', 'max:255',
                Rule::unique('devices', 'code')->where('user_id', $targetOwnerId)->ignore($device->id),
            ],
            'type' => 'required|string|in:meter,sensor,smart_plug,camera,thermostat,lock',
            'is_active' => 'boolean',
            ...$this->topicRules(ignoreDeviceId: $device->id),
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
