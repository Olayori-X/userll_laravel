<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesMarketplaceData;
use Tests\TestCase;

/** Silly price input is a normal validation error (422), never a server error (500). */
class PriceInputTest extends TestCase
{
    use MakesMarketplaceData, RefreshDatabase;

    public function test_huge_search_prices_are_validation_errors(): void
    {
        $this->getJson('/api/v1/listings?max_price=99999999999999999999')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('max_price');

        $this->getJson('/api/v1/listings?min_price=999999999999999k')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('min_price');
    }

    public function test_sensible_search_prices_still_work(): void
    {
        $this->getJson('/api/v1/listings?min_price=1000&max_price=100.5k')->assertOk();
    }

    public function test_a_huge_listing_price_is_a_validation_error(): void
    {
        $seller = $this->makeSeller();

        $this->actingAs($seller, 'sanctum')
            ->postJson('/api/v1/seller/listings', ['price' => '99999999999999999999k', 'delivery_fee' => '99999999999999999999'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['price', 'delivery_fee']);
    }
}