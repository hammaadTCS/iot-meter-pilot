<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Device;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class TestUsersSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create super admin user
        User::firstOrCreate(
            ['email' => 'superadmin@test.local'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('password123'),
                'role' => 'super_admin',
                'cnic' => '1234567890100',
                'phone_number' => '03001234566',
                'address' => 'Super Admin Address',
            ]
        );

        // Create admin user
        User::firstOrCreate(
            ['email' => 'admin@test.local'],
            [
                'name' => 'Admin User',
                'password' => Hash::make('password123'),
                'role' => 'admin',
                'cnic' => '1234567890101',
                'phone_number' => '03001234567',
                'address' => 'Test Admin Address',
            ]
        );

        // Create regular user 1
        $user1 = User::firstOrCreate(
            ['email' => 'user1@test.local'],
            [
                'name' => 'Test User One',
                'password' => Hash::make('password123'),
                'role' => 'user',
                'cnic' => '1234567890102',
                'phone_number' => '03001234568',
                'address' => 'Test User 1 Address',
            ]
        );

        // Create regular user 2
        $user2 = User::firstOrCreate(
            ['email' => 'user2@test.local'],
            [
                'name' => 'Test User Two',
                'password' => Hash::make('password123'),
                'role' => 'user',
                'cnic' => '1234567890103',
                'phone_number' => '03001234569',
                'address' => 'Test User 2 Address',
            ]
        );

        // Bundles mirror the legacy roles (idempotent) — enforcement reads
        // permissions, so test users need bundles, not just the role column.
        User::where('email', 'superadmin@test.local')->first()?->assignRole('super_admin');
        User::where('email', 'admin@test.local')->first()?->assignRole(['field_engineer', 'fleet_operator']);
        $user1->assignRole('consumer');
        $user2->assignRole('consumer');

        // Assign devices to users (only if devices exist)
        $devices = Device::all();

        if ($devices->count() >= 2) {
            Device::where('id', $devices->get(0)->id)->update(['user_id' => $user1->id]);
            Device::where('id', $devices->get(1)->id)->update(['user_id' => $user1->id]);
        }

        if ($devices->count() >= 4) {
            Device::where('id', $devices->get(2)->id)->update(['user_id' => $user2->id]);
            Device::where('id', $devices->get(3)->id)->update(['user_id' => $user2->id]);
        }

        if ($this->command) {
            $this->command->info('Test users created successfully:');
            $this->command->info('  SuperAdmin: superadmin@test.local / password123 (role: super_admin)');
            $this->command->info('  Admin:      admin@test.local / password123 (role: admin)');
            $this->command->info('  User1:      user1@test.local / password123 (role: user) - has ' . $user1->devices()->count() . ' devices');
            $this->command->info('  User2:      user2@test.local / password123 (role: user) - has ' . $user2->devices()->count() . ' devices');
        }
    }
}
