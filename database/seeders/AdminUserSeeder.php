<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Role;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Seed a default Super Admin user.
     *
     * @return void
     */
    public function run()
    {
        $adminEmail = 'admin@dkplatform.com';

        // Create admin user if not exists
        $admin = User::firstOrCreate(
            ['email' => $adminEmail],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('Azhar@77'),
                'phone' => '0000000000',
                'status' => 'active',
            ]
        );

        // Assign admin role
        $adminRole = Role::where('slug', 'admin')->first();
        if ($adminRole) {
            $admin->roles()->syncWithoutDetaching([$adminRole->id]);
        }

        // Create blank profile if missing
        if (!$admin->userProfile) {
            $admin->userProfile()->create([]);
        }

        $this->command->info("Admin user created/exists: {$adminEmail} / Azhar@77");
    }
}
