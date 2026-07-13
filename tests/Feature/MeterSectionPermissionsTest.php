<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Hybrid FGAC Phase 5 — proves the permission toggles ENFORCE, not just
 * persist: every meter-dashboard section has a server-side guard on its API,
 * rename-only mode strips crafted fields, and self-provision is locked to
 * self-owned meters no matter what the request claims.
 */
class MeterSectionPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private function meterOwnedBy(User $user): Device
    {
        return Device::factory()->create([
            'user_id' => $user->id,
            'type'    => 'meter',
        ]);
    }

    /** A user holding exactly the built-ins plus the given meter slugs. */
    private function userWithMeterSlugs(array $slugs): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo([
            'dashboard.view', 'devices.view_own', 'alerts.view_own',
            'alerts.settings_own', 'api.devices.read', 'api.readings.read',
            ...$slugs,
        ]);

        return $user;
    }

    public function test_without_meter_access_the_dashboard_shows_the_no_access_placeholder(): void
    {
        $user = $this->userWithMeterSlugs([]); // built-ins only
        $device = $this->meterOwnedBy($user);

        $this->actingAs($user)
            ->get(route('devices.dashboard', $device))
            ->assertOk()
            ->assertSee('does not have access to the meter system');
    }

    public function test_section_apis_enforce_their_own_slugs(): void
    {
        $user = $this->userWithMeterSlugs(['meter.access', 'meter.live_data']);
        $device = $this->meterOwnedBy($user);

        $this->actingAs($user, 'sanctum');

        // live_data: the status poll works…
        $this->getJson("/api/devices/{$device->id}/status")->assertOk();
        // …but charts and history are refused server-side.
        $this->getJson("/api/devices/{$device->id}/readings/chart?range=1h")->assertForbidden();
        $this->getJson("/api/devices/{$device->id}/readings?range=1h")->assertForbidden();

        // Granting each slug opens exactly its own endpoint.
        $user->givePermissionTo('meter.charts');
        $this->getJson("/api/devices/{$device->id}/readings/chart?range=1h")->assertOk();
        $this->getJson("/api/devices/{$device->id}/readings?range=1h")->assertForbidden();

        $user->givePermissionTo('meter.history');
        $this->getJson("/api/devices/{$device->id}/readings?range=1h")->assertOk();
    }

    public function test_sections_are_absent_from_the_html_not_just_hidden(): void
    {
        $user = $this->userWithMeterSlugs(['meter.access', 'meter.live_data']);
        $device = $this->meterOwnedBy($user);

        $html = $this->actingAs($user)
            ->get(route('devices.dashboard', $device))
            ->assertOk()
            ->getContent();

        // Markers are exact HTML fragments unique to each section's markup —
        // class names/ids alone would false-positive on the CSS/JS blocks,
        // which render for every user.
        $this->assertStringContainsString('<div class="kpi-grid">', $html);          // Section 1 granted
        $this->assertStringNotContainsString('id="chartVC"', $html);                 // Section 2 absent
        $this->assertStringNotContainsString('<tbody id="readings-body">', $html);   // Section 3 absent
        $this->assertStringNotContainsString('chart.umd.min.js', $html);             // Chart.js not downloaded
        $this->assertStringNotContainsString('data-range="6h"', $html);              // range bar rides with 2/3

        // Full consumer: everything renders, including the library.
        $full = User::factory()->consumer()->create();
        $fullHtml = $this->actingAs($full)
            ->get(route('devices.dashboard', $this->meterOwnedBy($full)))
            ->assertOk()
            ->getContent();

        foreach (['<div class="kpi-grid">', 'id="chartVC"', '<tbody id="readings-body">',
                  'chart.umd.min.js', 'data-range="6h"'] as $marker) {
            $this->assertStringContainsString($marker, $fullHtml);
        }
    }

    public function test_ownership_still_bounds_meter_permissions(): void
    {
        // Full consumer bundle, but someone else's meter: policy view fails.
        $user  = User::factory()->consumer()->create();
        $other = User::factory()->consumer()->create();
        $device = $this->meterOwnedBy($other);

        $this->actingAs($user)->get(route('devices.dashboard', $device))->assertForbidden();
        $this->actingAs($user, 'sanctum')
            ->getJson("/api/devices/{$device->id}/readings/chart?range=1h")->assertForbidden();
    }

    public function test_rename_only_mode_discards_every_field_except_name(): void
    {
        $user = User::factory()->consumer()->create(); // meter.rename, no edit_own
        $device = $this->meterOwnedBy($user);
        $originalTopic = $device->mqtt_topic;

        // A crafted request trying to smuggle config changes past the form.
        $this->actingAs($user)
            ->patch(route('devices.update', $device), [
                'name'       => 'Kitchen Meter',
                'code'       => 'HACKED',
                'type'       => 'sensor',
                'mqtt_topic' => 'evil/topic',
                'is_active'  => false,
                'user_id'    => $user->id + 999,
            ])
            ->assertRedirect(route('devices.manage'));

        $device->refresh();
        $this->assertSame('Kitchen Meter', $device->name);       // the one allowed field
        $this->assertSame($originalTopic, $device->mqtt_topic);  // everything else intact
        $this->assertSame('meter', $device->type);
        $this->assertSame($user->id, $device->user_id);
        $this->assertTrue($device->is_active);
    }

    public function test_self_provision_forces_a_self_owned_meter(): void
    {
        $prosumer = User::factory()->prosumer()->create();
        $victim   = User::factory()->consumer()->create();

        // Foreign user_id in the payload is ignored — ownership is forced.
        $this->actingAs($prosumer)
            ->post(route('devices.store'), [
                'name'       => 'My Rooftop Meter',
                'code'       => 'SELF-01',
                'type'       => 'meter',
                'mqtt_topic' => 'meters/self-01/data',
                'user_id'    => $victim->id,
            ])
            ->assertRedirect(route('devices.manage'));

        $this->assertDatabaseHas('devices', [
            'code'    => 'SELF-01',
            'user_id' => $prosumer->id,
        ]);

        // Non-meter types are rejected outright for self-provisioners.
        $this->actingAs($prosumer)
            ->post(route('devices.store'), [
                'name'       => 'Sneaky Camera',
                'code'       => 'SELF-02',
                'type'       => 'camera',
                'mqtt_topic' => 'cams/self-02/data',
            ])
            ->assertSessionHasErrors('type');

        $this->assertDatabaseMissing('devices', ['code' => 'SELF-02']);
    }

    public function test_consumer_cannot_reach_the_create_form_at_all(): void
    {
        $consumer = User::factory()->consumer()->create();

        $this->actingAs($consumer)->get(route('devices.create'))->assertForbidden();
        $this->actingAs($consumer)->post(route('devices.store'), [
            'name' => 'X', 'code' => 'X1', 'type' => 'meter', 'mqtt_topic' => 'x/data',
        ])->assertForbidden();
    }
}
