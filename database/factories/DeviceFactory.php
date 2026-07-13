<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Device>
 */
class DeviceFactory extends Factory
{
    /**
     * Defaults model the common case: an active meter with a unique code and
     * data topic. Ownership is intentionally left null — tests decide who
     * owns the device, since ownership is half of every authorization rule.
     */
    public function definition(): array
    {
        $code = 'MTR-'.fake()->unique()->numerify('#####');

        return [
            'user_id'    => null,
            'code'       => $code,
            'name'       => fake()->streetName().' Meter',
            'type'       => 'meter',
            'mqtt_topic' => 'meters/'.strtolower($code).'/data',
            'is_active'  => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
