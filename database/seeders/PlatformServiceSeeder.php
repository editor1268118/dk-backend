<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\ServiceCategory;

class PlatformServiceSeeder extends Seeder
{
    /**
     * Seed default platform services created by Super Admin.
     *
     * @return void
     */
    public function run()
    {
        $services = [
            [
                'category_slug' => 'qurbani',
                'name' => 'Qurbani Service',
                'description' => 'Professional Qurbani (animal sacrifice) performed according to Islamic guidelines.',
                'customer_price' => 5000.00,
                'vendor_payout_percentage' => 80.00,
            ],
            [
                'category_slug' => 'quran-khani',
                'name' => 'Quran Khani Service',
                'description' => 'Quran recitation services for home events and gatherings.',
                'customer_price' => 1500.00,
                'vendor_payout_percentage' => 70.00,
            ],
            [
                'category_slug' => 'ruqya',
                'name' => 'Ruqya Service',
                'description' => 'Islamic spiritual healing and prayer sessions.',
                'customer_price' => 2000.00,
                'vendor_payout_percentage' => 75.00,
            ],
            [
                'category_slug' => 'counselling',
                'name' => 'Islamic Counselling',
                'description' => 'Professional personal, mental, or relationship counselling rooted in Islamic values.',
                'customer_price' => 1000.00,
                'vendor_payout_percentage' => 60.00,
            ],
            [
                'category_slug' => 'hijama',
                'name' => 'Hijama (Cupping Therapy)',
                'description' => 'Professional cupping therapy following Sunnah and modern standards.',
                'customer_price' => 1500.00,
                'vendor_payout_percentage' => 75.00,
            ],
            [
                'category_slug' => 'waqf-management',
                'name' => 'Waqf Management Service',
                'description' => 'Islamic endowment management, advisory, and documentation.',
                'customer_price' => 3000.00,
                'vendor_payout_percentage' => 70.00,
            ],
            [
                'category_slug' => 'fair-division',
                'name' => 'Fair Division Service',
                'description' => 'Islamic inheritance and fair division calculations and advisory.',
                'customer_price' => 2500.00,
                'vendor_payout_percentage' => 70.00,
            ],
        ];

        foreach ($services as $serviceData) {
            $category = ServiceCategory::where('slug', $serviceData['category_slug'])->first();

            $slug = Str::slug($serviceData['name']);

            DB::table('platform_services')->updateOrInsert(
                ['slug' => $slug],
                [
                    'service_category_id' => $category ? $category->id : null,
                    'name' => $serviceData['name'],
                    'description' => $serviceData['description'],
                    'customer_price' => $serviceData['customer_price'],
                    'vendor_payout_percentage' => $serviceData['vendor_payout_percentage'],
                    'platform_percentage' => 100 - $serviceData['vendor_payout_percentage'],
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}
