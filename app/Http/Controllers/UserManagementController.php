<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserManagementController extends Controller
{
    public function index(Request $request): View
    {
        $query = User::with('devices')->orderBy('name');

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($bundle = $request->get('bundle')) {
            $query->whereHas('roles', fn ($q) => $q->where('name', $bundle));
        }

        // Eager-load bundles for the list's access column.
        $users = $query->with('roles')->paginate(20)->withQueryString();

        return view('users.index', compact('users'));
    }

    public function create(): View
    {
        return view('users.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name'                  => ['required', 'string', 'max:255'],
            'email'                 => ['required', 'email', 'unique:users,email'],
            'password'              => ['required', 'string', 'min:8', 'confirmed'],
            // Grant bundle, not legacy role. super_admin is deliberately
            // not creatable from any screen — promote via CLI only.
            'bundle'                => ['required', Rule::in(['consumer', 'prosumer', 'field_engineer', 'fleet_operator'])],
            'cnic'                  => ['nullable', 'string', 'regex:/^[0-9]{13}$/'],
            'phone_number'          => ['nullable', 'string', 'regex:/^[0-9]{11}$/'],
            'address'               => ['nullable', 'string', 'max:500'],
        ]);

        $bundle = $validated['bundle'];
        unset($validated['bundle']);
        $validated['password'] = Hash::make($validated['password']);

        // The legacy role column stays NULL — bundles are the authority
        // for accounts created after the hybrid cutover.
        User::create($validated)->assignRole($bundle);

        return redirect()->route('users.index')
            ->with('success', 'User created successfully.');
    }

    public function show(User $user): View
    {
        // Eager-load each device's latest state so the device cards can render
        // their live Voltage / Power / Monthly Units metrics without an N+1
        // query per card (see resources/views/components/device-card.blade.php).
        $user->load('devices.latestState');
        return view('users.show', compact('user'));
    }

    public function edit(User $user): View
    {
        return view('users.edit', compact('user'));
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        // Access is managed on the permissions screen, not here — this
        // endpoint only edits profile fields and ignores any role input.
        $validated = $request->validate([
            'name'         => ['required', 'string', 'max:255'],
            'email'        => ['required', 'email', Rule::unique('users')->ignore($user->id)],
            'cnic'         => ['nullable', 'string', 'regex:/^[0-9]{13}$/'],
            'phone_number' => ['nullable', 'string', 'regex:/^[0-9]{11}$/'],
            'address'      => ['nullable', 'string', 'max:500'],
        ]);

        $user->update($validated);

        return redirect()->route('users.show', $user)
            ->with('success', 'User updated successfully.');
    }

    public function destroy(User $user): RedirectResponse
    {
        $authUser = Auth::user();

        if (!$authUser->isSuperAdmin()) {
            abort(403);
        }

        if ($user->isSuperAdmin()) {
            return back()->with('error', 'Cannot delete a super admin account.');
        }

        if ($authUser->id === $user->id) {
            return back()->with('error', 'Cannot delete your own account from this panel.');
        }

        $user->delete();

        return redirect()->route('users.index')
            ->with('success', 'User deleted.');
    }

    public function updateRole(Request $request, User $user): RedirectResponse
    {
        if (!Auth::user()->isSuperAdmin()) {
            abort(403);
        }

        $validated = $request->validate([
            'role' => ['required', 'in:user,admin,super_admin'],
        ]);

        $user->update(['role' => $validated['role']]);

        return back()->with('success', "Role updated to {$validated['role']}.");
    }
}
