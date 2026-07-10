<?php

namespace Tests;

use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Spatie\Permission\Models\Role;

abstract class TestCase extends BaseTestCase
{
    /**
     * The permission catalog is reference data the app assumes exists
     * (assignRole('consumer') etc.), so every DB-backed test gets it —
     * same status as the schema itself.
     *
     * Seeded here rather than via afterRefreshingDatabase(): the
     * RefreshDatabase trait ships an empty stub of that hook which takes
     * precedence over a parent-class implementation, silently disabling it.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (in_array(RefreshDatabase::class, class_uses_recursive(static::class))
            && ! Role::query()->exists()) {
            $this->seed(PermissionSeeder::class);
        }
    }
}
