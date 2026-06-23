<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;

/**
 * Covers the notification bell + popup in the app header. This is currently a
 * FRONT-END-ONLY feature (static placeholder data, no backend wiring), so the
 * test simply asserts the bell trigger and the popup scaffolding render on an
 * authenticated page. Update these assertions when real alerts are wired in.
 */
#[RequiresPhpExtension('pdo_sqlite')]
class NotificationBellTest extends TestCase
{
    use RefreshDatabase;

    public function test_header_renders_notification_bell_and_popup(): void
    {
        $this->withoutVite();
        $this->actingAs(User::factory()->create());

        $response = $this->get('/dashboard');

        $response->assertOk()
            ->assertSee('aria-label="Notifications"', false) // bell trigger
            ->assertSee('Notifications')                     // popup heading
            ->assertSee('new')                               // unread badge ("3 new")
            ->assertSee('High voltage detected')             // placeholder item
            ->assertSee('View all notifications');           // popup footer action
    }
}
