<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * One-time bridge from the legacy users.role enum to hybrid bundles
 * (docs/FGAC_IMPLEMENTATION_PLAN.md Phase 2, decisions D2/D3).
 *
 * - user        → consumer  (deliberate tightening: loses device create /
 *                 full edit; grant the prosumer bundle to restore them)
 * - admin       → field_engineer + fleet_operator
 * - super_admin → super_admin (Gate::before bypass)
 *
 * Idempotent and additive: assignRole() never removes bundles or direct
 * grants added since a previous run. Requires PermissionSeeder first.
 * The legacy role column is left untouched — it stays authoritative for
 * the old middleware until the Phase 5 cutover, and is dropped in Phase 7.
 */
class MigrateRolesToPermissionsSeeder extends Seeder
{
    private const ROLE_TO_BUNDLES = [
        'user'        => ['consumer'],
        'admin'       => ['field_engineer', 'fleet_operator'],
        'super_admin' => ['super_admin'],
    ];

    public function run(): void
    {
        foreach (self::ROLE_TO_BUNDLES as $legacyRole => $bundles) {
            $count = 0;

            User::where('role', $legacyRole)->each(function (User $user) use ($bundles, &$count) {
                $user->assignRole($bundles);
                $count++;
            });

            $this->command?->info(sprintf(
                '%-11s → %-31s %d user(s)', $legacyRole, implode(' + ', $bundles), $count
            ));
        }

        $unmapped = User::whereNull('role')->whereDoesntHave('roles')->count();
        if ($unmapped > 0) {
            $this->command?->warn("{$unmapped} user(s) have neither a legacy role nor a bundle — assign one via the permissions screen.");
        }
    }
}
