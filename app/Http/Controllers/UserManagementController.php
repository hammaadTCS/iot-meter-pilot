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

        if ($role = $request->get('role')) {
            $query->where('role', $role);
        }

        $users = $query->paginate(20)->withQueryString();

        return view('users.index', compact('users'));
    }

    public function create(): View
    {
        return view('users.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $authUser = Auth::user();
        $roleOptions = $authUser->isSuperAdmin() ? 'in:user,admin,super_admin' : 'in:user,admin';

        $validated = $request->validate([
            'name'                  => ['required', 'string', 'max:255'],
            'email'                 => ['required', 'email', 'unique:users,email'],
            'password'              => ['required', 'string', 'min:8', 'confirmed'],
            'role'                  => ['required', $roleOptions],
            'cnic'                  => ['nullable', 'string', 'regex:/^[0-9]{13}$/'],
            'phone_number'          => ['nullable', 'string', 'regex:/^[0-9]{11}$/'],
            'address'               => ['nullable', 'string', 'max:500'],
        ]);

        $validated['password'] = Hash::make($validated['password']);

        User::create($validated);

        return redirect()->route('users.index')
            ->with('success', 'User created successfully.');
    }

    public function show(User $user): View
    {
        $user->load('devices');
        return view('users.show', compact('user'));
    }

    public function edit(User $user): View
    {
        return view('users.edit', compact('user'));
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $authUser = Auth::user();
        $roleOptions = $authUser->isSuperAdmin() ? 'in:user,admin,super_admin' : 'in:user,admin';

        $rules = [
            'name'         => ['required', 'string', 'max:255'],
            'email'        => ['required', 'email', Rule::unique('users')->ignore($user->id)],
            'cnic'         => ['nullable', 'string', 'regex:/^[0-9]{13}$/'],
            'phone_number' => ['nullable', 'string', 'regex:/^[0-9]{11}$/'],
            'address'      => ['nullable', 'string', 'max:500'],
        ];

        if ($authUser->isSuperAdmin()) {
            $rules['role'] = ['sometimes', $roleOptions];
        }

        $validated = $request->validate($rules);

        // Admins cannot change role
        if (!$authUser->isSuperAdmin()) {
            unset($validated['role']);
        }

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
