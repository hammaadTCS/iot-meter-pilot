<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A topic string is the only thing binding an inbound MQTT message to a device
 * row (MeterPayloadProcessor matches mqtt_topic, MeterAvailabilityProcessor
 * matches availability_topic — both exact-string, both ->first()). A topic
 * reused across two rows is therefore a cross-tenant leak.
 *
 * The web forms previously validated neither field for uniqueness, while the
 * API validated both; and availability_topic had no database constraint at all.
 * These tests pin the closure of that gap.
 *
 * Full context: docs/DEVICE_CLAIMING.md §1.2, §1.3, §8.
 */
class DeviceTopicUniquenessTest extends TestCase
{
    use RefreshDatabase;

    private function operator(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo([
            'devices.view_own', 'devices.create', 'devices.edit_own', 'meter.access',
        ]);

        return $user;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'My Meter',
            'code' => 'my-meter',
            'type' => 'meter',
            'mqtt_topic' => 'meters/mine/data',
            'availability_topic' => 'meters/mine/status',
            'is_active' => true,
        ], $overrides);
    }

    public function test_a_second_device_cannot_take_an_existing_data_topic(): void
    {
        $victim = Device::factory()->create(['mqtt_topic' => 'meters/victim/data']);

        $this->actingAs($this->operator())
            ->post(route('devices.store'), $this->payload(['mqtt_topic' => 'meters/victim/data']))
            ->assertSessionHasErrors('mqtt_topic');

        $this->assertSame(1, Device::where('mqtt_topic', 'meters/victim/data')->count());
        $this->assertSame($victim->id, Device::where('mqtt_topic', 'meters/victim/data')->first()->id);
    }

    /**
     * The live defect this fix exists for: availability_topic had no unique
     * index and no validation, so a self-provisioning user could point their
     * device at another customer's status topic and receive that household's
     * online/offline state.
     */
    public function test_a_second_device_cannot_take_an_existing_availability_topic(): void
    {
        Device::factory()->create([
            'mqtt_topic' => 'meters/victim/data',
            'availability_topic' => 'meters/victim/status',
        ]);

        $this->actingAs($this->operator())
            ->post(route('devices.store'), $this->payload(['availability_topic' => 'meters/victim/status']))
            ->assertSessionHasErrors('availability_topic');

        $this->assertSame(1, Device::where('availability_topic', 'meters/victim/status')->count());
    }

    /**
     * Cross-column: the consumer subscribes to BOTH of a device's topics, so
     * claiming another device's data topic as your status topic is exploitable
     * in the same way as a same-column collision.
     */
    public function test_availability_topic_cannot_collide_with_another_devices_data_topic(): void
    {
        Device::factory()->create(['mqtt_topic' => 'meters/victim/data']);

        $this->actingAs($this->operator())
            ->post(route('devices.store'), $this->payload(['availability_topic' => 'meters/victim/data']))
            ->assertSessionHasErrors('availability_topic');
    }

    public function test_a_device_cannot_use_one_topic_for_both_of_its_own_fields(): void
    {
        $this->actingAs($this->operator())
            ->post(route('devices.store'), $this->payload([
                'mqtt_topic' => 'meters/mine/data',
                'availability_topic' => 'meters/mine/data',
            ]))
            ->assertSessionHasErrors('availability_topic');
    }

    public function test_update_cannot_steal_another_devices_topic(): void
    {
        $operator = $this->operator();
        $mine = Device::factory()->create([
            'user_id' => $operator->id,
            'mqtt_topic' => 'meters/mine/data',
            'availability_topic' => 'meters/mine/status',
        ]);
        Device::factory()->create([
            'mqtt_topic' => 'meters/victim/data',
            'availability_topic' => 'meters/victim/status',
        ]);

        $this->actingAs($operator)
            ->patch(route('devices.update', $mine), $this->payload([
                'code' => $mine->code,
                'availability_topic' => 'meters/victim/status',
            ]))
            ->assertSessionHasErrors('availability_topic');

        $this->assertSame('meters/mine/status', $mine->fresh()->availability_topic);
    }

    /** A device keeping its own topics on update must not fail against itself. */
    public function test_update_may_keep_its_own_topics_unchanged(): void
    {
        $operator = $this->operator();
        $device = Device::factory()->create([
            'user_id' => $operator->id,
            'mqtt_topic' => 'meters/mine/data',
            'availability_topic' => 'meters/mine/status',
        ]);

        $this->actingAs($operator)
            ->patch(route('devices.update', $device), $this->payload([
                'name' => 'Renamed Meter',
                'code' => $device->code,
                'mqtt_topic' => 'meters/mine/data',
                'availability_topic' => 'meters/mine/status',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('Renamed Meter', $device->fresh()->name);
    }

    public function test_a_free_topic_is_still_accepted(): void
    {
        $this->actingAs($this->operator())
            ->post(route('devices.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('devices', ['mqtt_topic' => 'meters/mine/data']);
    }
}
