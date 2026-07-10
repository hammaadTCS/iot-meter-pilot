<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationModeTest extends TestCase
{
    use RefreshDatabase;

    private const PAYLOAD = [
        'name' => 'New Consumer',
        'email' => 'newconsumer@test.local',
        'password' => 'password123!A',
        'password_confirmation' => 'password123!A',
    ];

    public function test_self_serve_registration_creates_a_consumer_account(): void
    {
        $response = $this->post('/register', self::PAYLOAD);

        $response->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticated();

        $user = User::where('email', 'newconsumer@test.local')->firstOrFail();
        $this->assertTrue($user->hasRole('consumer'));

        // Bundle semantics: full own-meter dashboard, no device management.
        $this->assertTrue($user->can('meter.access'));
        $this->assertTrue($user->can('meter.charts'));
        $this->assertTrue($user->can('meter.rename'));
        $this->assertFalse($user->can('devices.create'));
        $this->assertFalse($user->can('devices.view_any'));
    }

    public function test_disabled_registration_is_refused_by_the_controller_guard(): void
    {
        // The route block in routes/auth.php is evaluated at boot, so this
        // exercises the defense-in-depth abort_unless in the controller —
        // the layer that holds even if the routes are re-added by mistake.
        config(['auth.allow_registration' => false]);

        $this->get('/register')->assertNotFound();
        $this->post('/register', self::PAYLOAD)->assertNotFound();

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'newconsumer@test.local']);
    }
}
