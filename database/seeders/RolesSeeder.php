<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Role;

class RolesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $roles = [
            [
                'name' => 'Admin',
                'slug' => 'admin',
                'description' => 'System administrator with full access',
            ],
            [
                'name' => 'User',
                'slug' => 'user',
                'description' => 'Standard customer or user',
            ],
            [
                'name' => 'Provider',
                'slug' => 'provider',
                'description' => 'Service provider offering bookings',
            ],
            [
                'name' => 'Fundraiser',
                'slug' => 'fundraiser',
                'description' => 'Individual or organization running fundraisers',
            ],
            [
                'name' => 'Institution',
                'slug' => 'institution',
                'description' => 'Institutional account',
            ],
        ];

        foreach ($roles as $role) {
            Role::firstOrCreate(['slug' => $role['slug']], $role);
        }
    }
}
