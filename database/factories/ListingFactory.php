<?php

namespace Database\Factories;

use App\Enums\ListingCondition;
use App\Enums\ListingStatus;
use App\Models\Category;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Listing> */
class ListingFactory extends Factory
{
    public function definition(): array
    {
        $title = fake()->unique()->words(3, true);

        return [
            'seller_id' => User::factory(),
            'category_id' => Category::factory(),
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
            'description' => fake()->paragraph(),
            'price' => fake()->numberBetween(50_000, 5_000_000), // kobo
            'condition' => ListingCondition::New,
            'stock' => 5,
            'status' => ListingStatus::Active,
            'city' => 'Ikeja',
            'state' => 'Lagos',
        ];
    }

    // seller_id and status are not mass-assignable on the model, so the factory
    // (which uses forceFill) sets them directly. Use these states for readability:
    public function draft(): static
    {
        return $this->state(['status' => ListingStatus::Draft]);
    }

    public function soldOut(): static
    {
        return $this->state(['status' => ListingStatus::SoldOut, 'stock' => 0]);
    }
}
