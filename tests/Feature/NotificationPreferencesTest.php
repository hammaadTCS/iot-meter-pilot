<?php

namespace Tests\Feature;

use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;

/**
 * The notification preferences settings page: users can tune their own delivery,
 * and only admins may opt into fleet-wide delivery (a privilege guard).
 */
#[RequiresPhpExtension('pdo_sqlite')]
class NotificationPreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_page_renders(): void
    {
        $this->withoutVite();
        $this->actingAs(User::factory()->create(['role' => 'user']));

        $this->get('/settings/notifications')
            ->assertOk()
            ->assertSee('Notification Settings');
    }

    public function test_user_can_update_their_preferences(): void
    {
        $user = User::factory()->create(['role' => 'user']);

        $this->actingAs($user)->patch('/settings/notifications', [
            'min_severity'      => 'critical',
            'quiet_hours_start' => '22:00',
            'quiet_hours_end'   => '06:00',
            'fleet_scope'       => 'own',
            // mail_enabled omitted → unchecked → false
        ])->assertRedirect();

        $pref = NotificationPreference::where('user_id', $user->id)->first();
        $this->assertNotNull($pref);
        $this->assertFalse($pref->mail_enabled);
        $this->assertSame('critical', $pref->min_severity);
        $this->assertNotNull($pref->quiet_hours_start);
        $this->assertNotNull($pref->quiet_hours_end);
    }

    public function test_a_non_admin_cannot_opt_into_fleet_wide_delivery(): void
    {
        $user = User::factory()->create(['role' => 'user']);

        $this->actingAs($user)->patch('/settings/notifications', [
            'min_severity' => 'warning',
            'fleet_scope'  => 'all', // attempted escalation
        ])->assertRedirect();

        $this->assertSame('own', NotificationPreference::where('user_id', $user->id)->value('fleet_scope'));
    }

    public function test_an_admin_can_opt_into_fleet_wide_delivery(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->patch('/settings/notifications', [
            'min_severity' => 'warning',
            'fleet_scope'  => 'all',
        ])->assertRedirect();

        $this->assertSame('all', NotificationPreference::where('user_id', $admin->id)->value('fleet_scope'));
    }
}
