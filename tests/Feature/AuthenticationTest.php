<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Run seeder to set up test users
        $this->seed(\Database\Seeders\TestUsersSeeder::class);
    }

    // ===== LOGIN & AUTHENTICATION TESTS =====

    public function test_user_can_login_with_valid_credentials(): void
    {
        $response = $this->post('/login', [
            'email' => 'user1@test.local',
            'password' => 'password123',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect('/dashboard');
    }

    public function test_user_cannot_login_with_invalid_password(): void
    {
        $response = $this->post('/login', [
            'email' => 'user1@test.local',
            'password' => 'wrongpassword',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors();
    }

    public function test_user_cannot_login_with_nonexistent_email(): void
    {
        $response = $this->post('/login', [
            'email' => 'nonexistent@test.local',
            'password' => 'password123',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors();
    }

    public function test_authenticated_user_can_access_dashboard(): void
    {
        $user = User::where('email', 'user1@test.local')->first();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertSuccessful();
        $response->assertViewIs('dashboard');
    }

    public function test_guest_cannot_access_dashboard(): void
    {
        $response = $this->get('/dashboard');

        $response->assertRedirect('/login');
    }

    public function test_user_can_logout(): void
    {
        $user = User::where('email', 'user1@test.local')->first();

        $this->actingAs($user)->post('/logout');

        $this->assertGuest();
    }

    // ===== DEVICE OWNERSHIP & AUTHORIZATION TESTS =====

    public function test_user_can_only_see_their_own_devices_in_api(): void
    {
        $user1 = User::where('email', 'user1@test.local')->first();
        $user2 = User::where('email', 'user2@test.local')->first();

        $user1DeviceCount = $user1->devices()->count();
        $user2DeviceCount = $user2->devices()->count();

        // User1 makes API call
        $response = $this->actingAs($user1)
            ->getJson('/api/devices');

        $response->assertSuccessful();
        $this->assertCount($user1DeviceCount, $response->json());

        // User2 makes API call
        $response = $this->actingAs($user2)
            ->getJson('/api/devices');

        $response->assertSuccessful();
        $this->assertCount($user2DeviceCount, $response->json());
    }

    public function test_user_cannot_view_other_users_device(): void
    {
        $user1 = User::where('email', 'user1@test.local')->first();
        $user2 = User::where('email', 'user2@test.local')->first();

        // Get a device belonging to user2
        $user2Device = $user2->devices()->first();

        // Skip if user2 has no devices (edge case in test)
        if (!$user2Device) {
            $this->markTestSkipped('User2 has no devices assigned');
            return;
        }

        // User1 tries to access user2's device
        $response = $this->actingAs($user1)
            ->getJson("/api/devices/{$user2Device->id}");

        // Should be forbidden
        $response->assertStatus(403);
    }

    public function test_user_cannot_delete_other_users_device(): void
    {
        $user1 = User::where('email', 'user1@test.local')->first();
        $user2 = User::where('email', 'user2@test.local')->first();

        $user2Device = $user2->devices()->first();

        // Skip if user2 has no devices (edge case in test)
        if (!$user2Device) {
            $this->markTestSkipped('User2 has no devices assigned');
            return;
        }

        $response = $this->actingAs($user1)
            ->deleteJson("/api/devices/{$user2Device->id}");

        $response->assertStatus(403);

        // Verify device still exists
        $this->assertTrue(Device::find($user2Device->id)->exists);
    }

    public function test_consumer_cannot_create_devices_via_api(): void
    {
        // Hybrid FGAC decision D2: consumers no longer create devices —
        // API writes additionally require api.devices.write. Field-engineer
        // creation is covered in DeviceManagementApiTest; web self-provision
        // (prosumer) in MeterSectionPermissionsTest.
        $user1 = User::where('email', 'user1@test.local')->first();

        $this->actingAs($user1)
            ->postJson('/api/devices', [
                'code' => 'TEST_METER_001',
                'name' => 'Test Meter',
                'type' => 'meter',
                'mqtt_topic' => 'test/meter/001',
                'is_active' => true,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('devices', ['code' => 'TEST_METER_001']);
    }

    public function test_unauthenticated_user_cannot_access_api(): void
    {
        $response = $this->getJson('/api/devices');

        $response->assertStatus(401);
    }

    // ===== ADMIN TESTS =====

    public function test_admin_can_see_all_devices(): void
    {
        $admin = User::where('email', 'admin@test.local')->first();
        $totalDevices = Device::count();

        $response = $this->actingAs($admin)
            ->getJson('/api/devices');

        $response->assertSuccessful();
        $this->assertCount($totalDevices, $response->json());
    }

    public function test_admin_can_access_any_device(): void
    {
        $admin = User::where('email', 'admin@test.local')->first();
        $user2Device = User::where('email', 'user2@test.local')->first()->devices()->first();

        // Skip if user2 has no devices (edge case in test)
        if (!$user2Device) {
            $this->markTestSkipped('User2 has no devices assigned');
            return;
        }

        $response = $this->actingAs($admin)
            ->getJson("/api/devices/{$user2Device->id}");

        $response->assertSuccessful();
    }

    public function test_user_role_field_is_set_correctly(): void
    {
        $admin = User::where('email', 'admin@test.local')->first();
        $user1 = User::where('email', 'user1@test.local')->first();

        $this->assertEquals('admin', $admin->role);
        $this->assertTrue($admin->isAdmin());

        $this->assertEquals('user', $user1->role);
        $this->assertFalse($user1->isAdmin());
    }

    // ===== SANCTUM API TOKEN TESTS =====

    public function test_user_can_generate_api_token(): void
    {
        $user = User::where('email', 'user1@test.local')->first();

        // Generate a personal access token
        $token = $user->createToken('test-token')->plainTextToken;

        $this->assertNotEmpty($token);
        $this->assertCount(1, $user->tokens);
    }

    public function test_api_token_can_authenticate_api_request(): void
    {
        $user = User::where('email', 'user1@test.local')->first();
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/devices');

        $response->assertSuccessful();
    }

    public function test_invalid_token_cannot_authenticate(): void
    {
        $response = $this->withToken('invalid-token')
            ->getJson('/api/devices');

        $response->assertStatus(401);
    }
}
