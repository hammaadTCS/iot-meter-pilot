<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\AlertDigestNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;

/**
 * The notification bell in the app header, now backed by real database
 * notifications: it renders empty for a fresh user, shows a delivered alert with
 * an unread badge, and "Mark all read" clears the unread state.
 */
#[RequiresPhpExtension('pdo_sqlite')]
class NotificationBellTest extends TestCase
{
    use RefreshDatabase;

    public function test_bell_renders_empty_for_a_fresh_user(): void
    {
        $this->withoutVite();
        $this->actingAs(User::factory()->create());

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('aria-label="Notifications"', false) // bell trigger
            ->assertSee('Notifications')                     // popup heading
            ->assertSee('all caught up')                     // empty state
            ->assertSee('Mark all read');                    // footer action
    }

    public function test_bell_shows_a_delivered_alert_and_can_mark_read(): void
    {
        $this->withoutVite();
        $user = User::factory()->create();
        $this->actingAs($user);

        $user->notify(new AlertDigestNotification([[
            'device_id'   => 1,
            'device_name' => 'Main Feeder',
            'device_type' => 'meter',
            'alert_type'  => 'telemetry_down',
            'severity'    => 'critical',
            'message'     => 'No telemetry for 12 minutes',
            'transition'  => 'opened',
            'at'          => null,
        ]], 'critical'));

        $this->assertSame(1, $user->unreadNotifications()->count());

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Main Feeder')   // the delivered alert title
            ->assertSee('new');          // unread badge ("1 new")

        $this->post('/notifications/read')->assertRedirect();

        $this->assertSame(0, $user->fresh()->unreadNotifications()->count());
    }
}
