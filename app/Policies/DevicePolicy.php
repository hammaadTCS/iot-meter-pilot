<?php

namespace App\Policies;

use App\Models\Device;
use App\Models\User;

/**
 * Permission + ownership predicates (docs/FGAC_IMPLEMENTATION_PLAN.md §6).
 *
 * super_admin never reaches these methods — Gate::before short-circuits
 * first. Everyone else is evaluated purely on permission slugs, so a fully
 * stripped account can do nothing, owner or not.
 */
class DevicePolicy
{
    public function viewAny(User $user): bool
    {
        // Lists are query-scoped (Device::forUser / devices.view_any) —
        // nothing to decide per-model here.
        return true;
    }

    public function view(User $user, Device $device): bool
    {
        return $user->can('devices.view_any')
            || ($user->can('devices.view_own') && $user->id === $device->user_id);
    }

    public function create(User $user): bool
    {
        // meter.self_provision is the narrow path: the controllers force
        // type=meter and self-ownership for users who only hold it.
        return $user->can('devices.create') || $user->can('meter.self_provision');
    }

    public function update(User $user, Device $device): bool
    {
        if ($user->can('devices.edit_any')) {
            return true;
        }

        if ($user->id !== $device->user_id) {
            return false;
        }

        // Owner: full edit, or name-only rename for meters (the controller
        // strips every field except name in rename-only mode).
        return $user->can('devices.edit_own')
            || ($user->can('meter.rename') && $device->type === 'meter');
    }

    public function delete(User $user, Device $device): bool
    {
        return $user->can('devices.delete_any')
            || ($user->can('devices.delete_own') && $user->id === $device->user_id);
    }
}
