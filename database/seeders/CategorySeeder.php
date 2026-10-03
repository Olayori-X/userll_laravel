<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CategorySeeder extends Seeder
{
    /** Starter list based on the old Userll site. Edit freely. */
    public function run(): void
    {
        $names = [
            'Phones & Tablets',
            'Electronics',
            'Clothes',
            'Shoes',
            'Jewelry & Accessories',
            'Home & Garden',
            'Beauty & Health',
        ];

        foreach ($names as $i => $name) {
            Category::updateOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name, 'position' => $i, 'is_active' => true],
            );
        }
    }
}
