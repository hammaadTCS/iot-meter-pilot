<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PermissionManagementTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->create()->assignRole('super_admin');
    }

    private function consumer(): User
    {
        return User::factory()->create()->assignRole('consumer');
    }

    public function test_super_admin_can_view_the_access_screen(): void
    {
        $target = $this->consumer();

        $this->actingAs($this->superAdmin())
            ->get(route('users.permissions.show', $target))
            ->assertOk()
            ->assertSee('Manage Access')
            ->assertSee('consumer');
    }

    public function test_regular_user_cannot_view_the_access_screen(): void
    {
        $target = $this->consumer();

        $this->actingAs($this->consumer())
            ->get(route('users.permissions.show', $target))
            ->assertForbidden();
    }

    public function test_delegated_user_with_the_permission_can_view_the_screen(): void
    {
        $delegate = $this->consumer();
        $delegate->givePermissionTo('users.manage_permissions');

        $this->actingAs($delegate)
            ->get(route('users.permissions.show', $this->consumer()))
            ->assertOk();
    }

    public function test_update_syncs_bundles_and_direct_grants(): void
    {
        $target = $this->consumer();

        $this->actingAs($this->superAdmin())
            ->patch(route('users.permissions.update', $target), [
                'bundles' => ['prosumer'],
                'direct'  => ['dashboard.view_system_stats'],
            ])
            ->assertRedirect(route('users.permissions.show', $target));

        $target->refresh();
        $this->assertSame(['prosumer'], $target->roles->pluck('name')->all());
        $this->assertTrue($target->can('meter.self_provision'));   // via prosumer
        $this->assertTrue($target->can('dashboard.view_system_stats')); // direct
        $this->assertFalse($target->can('devices.view_any'));
    }

    public function test_super_admin_bundle_cannot_be_assigned_from_the_screen(): void
    {
        $target = $this->consumer();

        $this->actingAs($this->superAdmin())
            ->patch(route('users.permissions.update', $target), [
                'bundles' => ['super_admin'],
            ])
            ->assertSessionHasErrors('bundles.0');

        $this->assertFalse($target->fresh()->hasRole('super_admin'));
    }

    public function test_a_super_admin_target_is_never_manageable(): void
    {
        $target = $this->superAdmin();
        $actor  = $this->superAdmin();

        $this->actingAs($actor)->get(route('users.permissions.show', $target))->assertForbidden();
        $this->actingAs($actor)->patch(route('users.permissions.update', $target), [])->assertForbidden();
    }

    public function test_detach_converts_a_bundle_into_editable_direct_grants(): void
    {
        $target = $this->consumer();
        $this->assertTrue($target->can('meter.charts'));

        $this->actingAs($this->superAdmin())
            ->post(route('users.permissions.detach', $target), ['bundle' => 'consumer'])
            ->assertRedirect(route('users.permissions.show', $target));

        $target = $target->fresh();
        $this->assertSame([], $target->roles->pluck('name')->all());
        // Same effective access, now as direct grants…
        $this->assertTrue($target->can('meter.charts'));
        $this->assertTrue($target->can('dashboard.view'));

        // …which makes the subtractive exception possible: consumer minus charts.
        $direct = $target->permissions->pluck('name')->reject(fn ($p) => $p === 'meter.charts')->values()->all();
        $this->actingAs($this->superAdmin())
            ->patch(route('users.permissions.update', $target), ['direct' => $direct]);

        $target = $target->fresh();
        $this->assertFalse($target->can('meter.charts'));
        $this->assertTrue($target->can('meter.live_data'));
    }

    public function test_accounts_are_created_with_a_bundle_instead_of_a_role(): void
    {
        // Account creation requires users.view_list + users.create — held by
        // super admins (Gate::before) or explicit delegation.
        $creator = User::factory()->superAdmin()->create();

        $this->actingAs($creator)->post(route('users.store'), [
            'name' => 'Bundled User',
            'email' => 'bundled@test.local',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'bundle' => 'field_engineer',
        ])->assertRedirect(route('users.index'));

        $user = User::where('email', 'bundled@test.local')->firstOrFail();
        // The legacy column falls back to its DB default ('user') until it
        // is dropped in Phase 7 — bundles are the access authority.
        $this->assertSame('user', $user->role);
        $this->assertTrue($user->hasRole('field_engineer'));
        $this->assertTrue($user->can('devices.create'));
    }
}
