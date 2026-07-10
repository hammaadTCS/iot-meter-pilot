<?php

namespace App\Http\Controllers;

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Per-user access management (docs/FGAC_IMPLEMENTATION_PLAN.md Phase 4).
 *
 * Routes are gated by permission:users.manage_permissions — super admins
 * pass via Gate::before, and granting the permission to anyone else is
 * effectively delegating super-admin authority (see the CSV matrix).
 *
 * Spatie has no per-user deny, so effective access = bundles ∪ direct
 * grants. Subtractive exceptions are handled by detachBundle(): the
 * bundle's permissions become direct grants that can then be unticked.
 */
class PermissionController extends Controller
{
    public function show(User $user): View
    {
        $this->guardTarget($user);

        // slug => bundle names that grant it, for the "via <bundle>" tags.
        $viaBundles = [];
        foreach ($user->roles as $bundle) {
            foreach ($bundle->permissions as $permission) {
                $viaBundles[$permission->name][] = $bundle->name;
            }
        }

        return view('users.permissions', [
            'user'            => $user,
            'bundles'         => Role::where('name', '!=', 'super_admin')->with('permissions')->orderBy('id')->get(),
            'assignedBundles' => $user->roles->pluck('name')->all(),
            'directGrants'    => $user->permissions->pluck('name')->all(),
            'viaBundles'      => $viaBundles,
            'builtIn'         => PermissionSeeder::BUILT_IN,
            'catalog'         => collect(PermissionSeeder::GRANTABLE)
                ->groupBy(fn (string $slug) => Str::before($slug, '.')),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->guardTarget($user);

        $validated = $request->validate([
            'bundles'   => ['sometimes', 'array'],
            // super_admin is deliberately not assignable from any screen —
            // promote via CLI only (privilege-escalation guard).
            'bundles.*' => [Rule::in(Role::where('name', '!=', 'super_admin')->pluck('name'))],
            'direct'    => ['sometimes', 'array'],
            'direct.*'  => [Rule::in([...PermissionSeeder::BUILT_IN, ...PermissionSeeder::GRANTABLE])],
        ]);

        DB::transaction(function () use ($user, $validated) {
            $user->syncRoles($validated['bundles'] ?? []);
            $user->syncPermissions($validated['direct'] ?? []);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return redirect()->route('users.permissions.show', $user)
            ->with('success', 'Access updated.');
    }

    /**
     * Convert a bundle membership into equivalent direct grants — the
     * escape hatch for "consumer, but without X": detach, then untick X.
     */
    public function detachBundle(Request $request, User $user): RedirectResponse
    {
        $this->guardTarget($user);

        $validated = $request->validate([
            'bundle' => ['required', Rule::in($user->roles->where('name', '!=', 'super_admin')->pluck('name'))],
        ]);

        $bundle = Role::findByName($validated['bundle']);

        DB::transaction(function () use ($user, $bundle) {
            $user->givePermissionTo($bundle->permissions);
            $user->removeRole($bundle);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return redirect()->route('users.permissions.show', $user)
            ->with('success', "Bundle '{$bundle->name}' detached — its permissions are now direct grants you can edit individually.");
    }

    private function guardTarget(User $user): void
    {
        // Super admin accounts are never manageable here, even by another
        // super admin. (Target-state inspection — the one hasRole() use
        // outside Gate::before; the Phase 8 CI guardrail allowlists it.)
        abort_if($user->hasRole('super_admin'), 403, 'Super admin access cannot be modified.');
    }
}
