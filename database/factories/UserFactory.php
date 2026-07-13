<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    // ── Grant-bundle states (hybrid FGAC) ────────────────────────────────
    // Requires the permission catalog to be seeded (TestCase::setUp does).

    public function consumer(): static
    {
        return $this->afterCreating(fn ($user) => $user->assignRole('consumer'));
    }

    public function prosumer(): static
    {
        return $this->afterCreating(fn ($user) => $user->assignRole('prosumer'));
    }

    public function fieldEngineer(): static
    {
        return $this->afterCreating(fn ($user) => $user->assignRole('field_engineer'));
    }

    public function fleetOperator(): static
    {
        return $this->afterCreating(fn ($user) => $user->assignRole('fleet_operator'));
    }

    public function superAdmin(): static
    {
        return $this->afterCreating(fn ($user) => $user->assignRole('super_admin'));
    }
}
