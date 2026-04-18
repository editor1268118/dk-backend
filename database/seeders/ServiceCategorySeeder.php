<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ServiceCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $categories = [
            [
                'name' => 'Qurbani',
                'description' => 'Professional animal sacrifice services observing Islamic guidelines.',
            ],
            [
                'name' => 'Quran Khani',
                'description' => 'Quran recitation services for gatherings and home events.',
            ],
            [
                'name' => 'Ruqya',
                'description' => 'Islamic spiritual healing and prayer services.',
            ],
            [
                'name' => 'Counselling',
                'description' => 'Professional personal, mental, or relationship Islamic counselling.',
            ]
        ];

        foreach ($categories as $category) {
            DB::table('service_categories')->updateOrInsert(
                ['slug' => Str::slug($category['name'])], // Check for duplicates by slug
                [
                    'name' => $category['name'],
                    'description' => $category['description'],
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}
