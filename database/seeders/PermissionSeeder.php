<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Single source of truth for the hybrid access-control catalog
 * (docs/FGAC_IMPLEMENTATION_PLAN.md §3.1–3.2).
 *
 * Idempotent: safe to re-run at any time. Bundle contents are synced,
 * so editing a bundle here and re-seeding propagates to every user
 * holding that bundle (one role_has_permissions write).
 *
 * All permissions live in the `web` guard only: Spatie resolves the
 * guard from the User model's default guard, so session, stateful-API
 * and Sanctum-token requests all check the same permission set.
 */
class PermissionSeeder extends Seeder
{
    /**
     * Granted to every account through membership of every bundle —
     * a user always holds at least these.
     */
    public const BUILT_IN = [
        'dashboard.view',
        'devices.view_own',
        'alerts.view_own',
        'alerts.settings_own',
        'api.devices.read',
        'api.readings.read',
    ];

    public const GRANTABLE = [
        // Dashboard
        'dashboard.view_system_stats',
        // Devices
        'devices.view_any',
        'devices.create',
        'devices.edit_own',
        'devices.edit_any',
        'devices.delete_own',
        'devices.delete_any',
        'devices.assign_owner',
        // Meter system
        'meter.access',
        'meter.self_provision',
        'meter.rename',
        'meter.live_data',
        'meter.charts',
        'meter.history',
        // Alerts
        'alerts.view_any',
        'alerts.fleet_scope',
        // User management
        'users.view_list',
        'users.view_profile',
        'users.create',
        'users.edit',
        'users.delete',
        'users.manage_permissions',
        // API writes
        'api.devices.write',
    ];

    /**
     * Bundles = Spatie roles used purely as grant templates. Application
     * code never checks these (the Gate::before super_admin bypass is the
     * single exception) — keep bundles lean; extras go on users as direct
     * grants because Spatie has no per-user deny.
     */
    public const BUNDLES = [
        'consumer' => [
            // meter.charts is deliberately NOT in this bundle — it is an
            // opt-in the super admin grants per user (direct grant on the
            // Manage Access screen). Product decision 2026-07-13.
            'meter.access',
            'meter.live_data',
            'meter.history',
            'meter.rename',
        ],
        'prosumer' => [
            'meter.access',
            'meter.live_data',
            'meter.charts',
            'meter.history',
            'meter.rename',
            'meter.self_provision',
            'devices.edit_own',
            'devices.delete_own',
        ],
        'field_engineer' => [
            'devices.view_any',
            'devices.create',
            'devices.edit_any',
            'devices.assign_owner',
            'api.devices.write',
        ],
        'fleet_operator' => [
            'devices.view_any',
            'dashboard.view_system_stats',
            'alerts.view_any',
            'alerts.fleet_scope',
            'users.view_list',
            'users.view_profile',
        ],
        // Bypassed by Gate::before — holds no permission rows on purpose,
        // so new features never require updating a super_admin grant list.
        'super_admin' => [],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([...self::BUILT_IN, ...self::GRANTABLE] as $slug) {
            Permission::firstOrCreate(['name' => $slug, 'guard_name' => 'web']);
        }

        foreach (self::BUNDLES as $bundle => $grantable) {
            $role = Role::firstOrCreate(['name' => $bundle, 'guard_name' => 'web']);

            $role->syncPermissions(
                $bundle === 'super_admin' ? [] : [...self::BUILT_IN, ...$grantable]
            );
        }

        $this->command?->info('Seeded '.Permission::count().' permissions, '.Role::count().' bundles.');
    }
}
