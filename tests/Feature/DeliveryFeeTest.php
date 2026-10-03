<?php

namespace Tests\Feature;

use App\Models\Listing;
use App\Models\Category;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesMarketplaceData;
use Tests\TestCase;

class DeliveryFeeTest extends TestCase
{
    use MakesMarketplaceData, RefreshDatabase;

    private function payload(Category $category, array $overrides = []): array
    {
        return array_merge([
            'category_id' => $category->id,
            'title' => 'Leather shoes size 42',
            'description' => 'Brand new leather shoes, never worn, comes with the box.',
            'price' => '25,000',
            'condition' => 'new',
            'stock' => 3,
            'city' => 'Ikeja',
            'state' => 'Lagos',
        ], $overrides);
    }

    public function test_seller_sets_delivery_fee_in_naira_stored_as_kobo(): void
    {
        $seller = $this->makeSeller();
        $category = Category::factory()->create();

        $this->actingAs($seller, 'sanctum')
            ->postJson('/api/v1/seller/listings', $this->payload($category, ['delivery_fee' => '1,500']))
            ->assertCreated()
            ->assertJsonPath('data.delivery_fee', 150_000)
            ->assertJsonPath('data.delivery_fee_formatted', '₦1,500.00');
    }

    public function test_leaving_delivery_fee_out_means_free_delivery(): void
    {
        $seller = $this->makeSeller();
        $category = Category::factory()->create();

        $this->actingAs($seller, 'sanctum')
            ->postJson('/api/v1/seller/listings', $this->payload($category))
            ->assertCreated()
            ->assertJsonPath('data.delivery_fee', 0);

        $this->actingAs($seller, 'sanctum')
            ->postJson('/api/v1/seller/listings', $this->payload($category, ['title' => 'Another listing', 'delivery_fee' => null]))
            ->assertCreated()
            ->assertJsonPath('data.delivery_fee', 0);
    }

    public function test_delivery_fee_validation_and_update(): void
    {
        $seller = $this->makeSeller();
        $category = Category::factory()->create();

        foreach (['abc', '12.345', '60,000'] as $bad) { // 60,000 is above the ₦50,000 cap
            $this->actingAs($seller, 'sanctum')
                ->postJson('/api/v1/seller/listings', $this->payload($category, ['delivery_fee' => $bad]))
                ->assertUnprocessable()->assertJsonValidationErrors('delivery_fee');
        }

        $id = $this->actingAs($seller, 'sanctum')
            ->postJson('/api/v1/seller/listings', $this->payload($category, ['delivery_fee' => '1,000']))
            ->json('data.id');

        $this->actingAs($seller, 'sanctum')->patchJson("/api/v1/seller/listings/{$id}", ['delivery_fee' => '2,000'])
            ->assertOk()->assertJsonPath('data.delivery_fee', 200_000);
    }

    public function test_cart_charges_one_delivery_fee_per_seller_the_highest(): void
    {
        $buyer = User::factory()->create();
        $sellerA = $this->makeSeller();
        $sellerB = $this->makeSeller();

        $a1 = Listing::factory()->create(['seller_id' => $sellerA->id, 'price' => 1_000_000, 'delivery_fee' => 100_000, 'stock' => 5]);
        $a2 = Listing::factory()->create(['seller_id' => $sellerA->id, 'price' => 1_000_000, 'delivery_fee' => 250_000, 'stock' => 5]);
        $b1 = Listing::factory()->create(['seller_id' => $sellerB->id, 'price' => 1_000_000, 'delivery_fee' => 150_000, 'stock' => 5]);

        foreach ([$a1, $a2, $b1] as $listing) {
            $this->actingAs($buyer, 'sanctum')->postJson('/api/v1/cart/items', ['listing_id' => $listing->id])->assertOk();
        }

        $this->actingAs($buyer, 'sanctum')->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('data.subtotal', 3_000_000)
            ->assertJsonPath('data.delivery_total', 400_000) // 250,000 (seller A) + 150,000 (seller B)
            ->assertJsonPath('data.total', 3_400_000);
    }

    public function test_checkout_adds_delivery_to_the_total_but_commission_only_applies_to_items(): void
    {
        $buyer = User::factory()->create();
        $address = $this->makeAddress($buyer);
        $seller = $this->makeSeller();
        $listing = Listing::factory()->create([
            'seller_id' => $seller->id, 'price' => 10_000_000, 'delivery_fee' => 200_000, 'stock' => 5, // ₦100,000 + ₦2,000 delivery
        ]);

        $this->actingAs($buyer, 'sanctum')->postJson('/api/v1/cart/items', ['listing_id' => $listing->id])->assertOk();

        $this->actingAs($buyer, 'sanctum')->postJson('/api/v1/checkout', ['address_id' => $address->id])
            ->assertCreated()
            ->assertJsonPath('data.total', 10_200_000);

        $order = Order::firstOrFail();
        $this->assertSame(10_000_000, $order->subtotal);
        $this->assertSame(200_000, $order->delivery_fee);
        $this->assertSame(10_200_000, $order->total);
        $this->assertSame(500_000, $order->commission);          // 5% of the items only
        $this->assertSame(9_700_000, $order->sellerEarnings());  // 10,200,000 - 500,000
    }

    public function test_seller_sees_delivery_fee_in_earnings(): void
    {
        $buyer = User::factory()->create();
        $seller = $this->makeSeller();
        $order = $this->makePaidOrder($buyer, $seller, 10_000_000, ['delivery_fee' => 200_000, 'total' => 10_200_000]);

        $this->actingAs($seller, 'sanctum')->getJson("/api/v1/seller/orders/{$order->order_number}")
            ->assertOk()
            ->assertJsonPath('data.delivery_fee', 200_000)
            ->assertJsonPath('data.total', 10_200_000)
            ->assertJsonPath('data.earnings', 9_700_000);
    }
}
