<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Guarantees at least one account holds the super_admin bundle — the
 * Gate::before bypass is the only path to the permission-management
 * screens, so a system without a super admin is unrecoverable from the UI.
 */
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        if (User::role('super_admin')->exists()) {
            return;
        }

        $legacySuperAdmin = User::where('role', 'super_admin')->first();

        if ($legacySuperAdmin) {
            $legacySuperAdmin->assignRole('super_admin');
            $this->command?->info("super_admin bundle assigned to {$legacySuperAdmin->email}.");

            return;
        }

        $this->command?->warn('No super admin exists — create one, e.g.: User::find(N)->assignRole(\'super_admin\') in tinker.');
    }
}
