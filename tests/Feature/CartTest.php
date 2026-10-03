<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesMarketplaceData;
use Tests\TestCase;

class CartTest extends TestCase
{
    use MakesMarketplaceData, RefreshDatabase;

    private function addToCart(User $buyer, Listing $listing, int $quantity = 1)
    {
        return $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/v1/cart/items', ['listing_id' => $listing->id, 'quantity' => $quantity]);
    }

    public function test_cart_requires_login(): void
    {
        $this->getJson('/api/v1/cart')->assertUnauthorized();
    }

    public function test_empty_cart(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum')->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('data.item_count', 0)
            ->assertJsonPath('data.can_checkout', false);
    }

    public function test_add_item_and_totals_are_in_kobo(): void
    {
        $buyer = User::factory()->create();
        $listing = Listing::factory()->create(['seller_id' => $this->makeSeller()->id, 'price' => 45_000_000, 'stock' => 5]);

        $this->addToCart($buyer, $listing, 2)
            ->assertOk()
            ->assertJsonPath('data.item_count', 2)
            ->assertJsonPath('data.items.0.unit_price', 45_000_000)
            ->assertJsonPath('data.items.0.line_total', 90_000_000)
            ->assertJsonPath('data.subtotal', 90_000_000)
            ->assertJsonPath('data.subtotal_formatted', '₦900,000.00')
            ->assertJsonPath('data.can_checkout', true);
    }

    public function test_adding_same_listing_again_increases_quantity(): void
    {
        $buyer = User::factory()->create();
        $listing = Listing::factory()->create(['seller_id' => $this->makeSeller()->id, 'stock' => 5]);

        $this->addToCart($buyer, $listing, 1)->assertOk();
        $this->addToCart($buyer, $listing, 2)
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.quantity', 3);
    }

    public function test_cannot_add_more_than_stock(): void
    {
        $buyer = User::factory()->create();
        $listing = Listing::factory()->create(['seller_id' => $this->makeSeller()->id, 'stock' => 2]);

        $this->addToCart($buyer, $listing, 3)->assertUnprocessable()->assertJsonValidationErrors('quantity');

        $this->addToCart($buyer, $listing, 2)->assertOk();
        $this->addToCart($buyer, $listing, 1)->assertUnprocessable()->assertJsonValidationErrors('quantity');
    }

    public function test_cannot_add_own_listing_draft_or_sold_out(): void
    {
        $seller = $this->makeSeller();
        $other = $this->makeSeller();
        $own = Listing::factory()->create(['seller_id' => $seller->id]);
        $draft = Listing::factory()->draft()->create(['seller_id' => $other->id]);
        $soldOut = Listing::factory()->soldOut()->create(['seller_id' => $other->id]);

        $this->addToCart($seller, $own)->assertUnprocessable()->assertJsonValidationErrors('listing_id');
        $this->addToCart($seller, $draft)->assertUnprocessable()->assertJsonValidationErrors('listing_id');
        $this->addToCart($seller, $soldOut)->assertUnprocessable()->assertJsonValidationErrors('listing_id');
    }

    public function test_update_and_remove_item(): void
    {
        $buyer = User::factory()->create();
        $listing = Listing::factory()->create(['seller_id' => $this->makeSeller()->id, 'stock' => 5]);

        $itemId = $this->addToCart($buyer, $listing)->json('data.items.0.id');

        $this->actingAs($buyer, 'sanctum')->patchJson("/api/v1/cart/items/{$itemId}", ['quantity' => 4])
            ->assertOk()->assertJsonPath('data.items.0.quantity', 4);

        $this->actingAs($buyer, 'sanctum')->patchJson("/api/v1/cart/items/{$itemId}", ['quantity' => 6])
            ->assertUnprocessable();

        $this->actingAs($buyer, 'sanctum')->deleteJson("/api/v1/cart/items/{$itemId}")
            ->assertOk()->assertJsonCount(0, 'data.items');
    }

    public function test_cannot_touch_another_users_cart_item(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $listing = Listing::factory()->create(['seller_id' => $this->makeSeller()->id, 'stock' => 5]);
        $itemId = $this->addToCart($owner, $listing)->json('data.items.0.id');

        $this->actingAs($intruder, 'sanctum')->patchJson("/api/v1/cart/items/{$itemId}", ['quantity' => 2])->assertNotFound();
        $this->actingAs($intruder, 'sanctum')->deleteJson("/api/v1/cart/items/{$itemId}")->assertNotFound();
    }

    public function test_clear_cart(): void
    {
        $buyer = User::factory()->create();
        $seller = $this->makeSeller();
        $this->addToCart($buyer, Listing::factory()->create(['seller_id' => $seller->id]));
        $this->addToCart($buyer, Listing::factory()->create(['seller_id' => $seller->id]));

        $this->actingAs($buyer, 'sanctum')->deleteJson('/api/v1/cart')
            ->assertOk()->assertJsonCount(0, 'data.items');
    }

    public function test_cart_flags_items_that_stopped_being_available(): void
    {
        $buyer = User::factory()->create();
        $seller = $this->makeSeller();
        $shrinking = Listing::factory()->create(['seller_id' => $seller->id, 'stock' => 5]);
        $unpublished = Listing::factory()->create(['seller_id' => $seller->id, 'stock' => 5]);
        $deleted = Listing::factory()->create(['seller_id' => $seller->id, 'stock' => 5]);

        $this->addToCart($buyer, $shrinking, 3);
        $this->addToCart($buyer, $unpublished);
        $this->addToCart($buyer, $deleted);

        $shrinking->update(['stock' => 1]);
        $unpublished->forceFill(['status' => ListingStatus::Draft])->save();
        $deleted->delete();

        $response = $this->actingAs($buyer, 'sanctum')->getJson('/api/v1/cart')->assertOk();
        $response->assertJsonPath('data.can_checkout', false);

        $issues = collect($response->json('data.items'))->pluck('issue', 'listing_id');
        $this->assertSame('Only 1 left', $issues[$shrinking->id]);
        $this->assertSame('No longer available', $issues[$unpublished->id]);
        $this->assertSame('No longer available', $issues[$deleted->id]);
    }
}
