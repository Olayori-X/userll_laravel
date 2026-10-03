<?php

namespace Tests\Feature;

use App\Enums\CheckoutStatus;
use App\Enums\OrderStatus;
use App\Models\Checkout;
use App\Models\Listing;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesMarketplaceData;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use MakesMarketplaceData, RefreshDatabase;

    private function addToCart(User $buyer, Listing $listing, int $quantity = 1): void
    {
        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/v1/cart/items', ['listing_id' => $listing->id, 'quantity' => $quantity])
            ->assertOk();
    }

    private function checkout(User $buyer, $addressId)
    {
        return $this->actingAs($buyer, 'sanctum')->postJson('/api/v1/checkout', ['address_id' => $addressId]);
    }

    public function test_checkout_splits_cart_into_one_order_per_seller_with_correct_money(): void
    {
        $buyer = User::factory()->create();
        $address = $this->makeAddress($buyer);
        $sellerA = $this->makeSeller('Store A');
        $sellerB = $this->makeSeller('Store B');

        $phone = Listing::factory()->create(['seller_id' => $sellerA->id, 'title' => 'Phone', 'price' => 10_000_000, 'stock' => 5]); // ₦100,000
        $case = Listing::factory()->create(['seller_id' => $sellerA->id, 'title' => 'Case', 'price' => 500_000, 'stock' => 5]);        // ₦5,000
        $shoes = Listing::factory()->create(['seller_id' => $sellerB->id, 'title' => 'Shoes', 'price' => 2_000_000, 'stock' => 5]);    // ₦20,000

        $this->addToCart($buyer, $phone, 2);
        $this->addToCart($buyer, $case, 1);
        $this->addToCart($buyer, $shoes, 1);

        $response = $this->checkout($buyer, $address->id)
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.total', 22_500_000) // 20,000,000 + 500,000 + 2,000,000
            ->assertJsonCount(2, 'data.orders');

        $this->assertStringStartsWith('CHK-', $response->json('data.reference'));
        $this->assertSame(1, Checkout::count());
        $this->assertSame(2, Order::count());

        $orderA = Order::where('seller_id', $sellerA->id)->firstOrFail();
        $this->assertSame(20_500_000, $orderA->subtotal);
        $this->assertSame(1_025_000, $orderA->commission); // 5% of 20,500,000
        $this->assertSame(500, $orderA->commission_rate_bps);
        $this->assertSame(20_500_000, $orderA->total);
        $this->assertSame(OrderStatus::PendingPayment, $orderA->status);
        $this->assertSame($buyer->id, $orderA->buyer_id);
        $this->assertCount(2, $orderA->items);

        $orderB = Order::where('seller_id', $sellerB->id)->firstOrFail();
        $this->assertSame(2_000_000, $orderB->subtotal);
        $this->assertSame(100_000, $orderB->commission);
    }

    public function test_checkout_does_not_touch_stock_or_empty_the_cart(): void
    {
        $buyer = User::factory()->create();
        $address = $this->makeAddress($buyer);
        $listing = Listing::factory()->create(['seller_id' => $this->makeSeller()->id, 'stock' => 5]);
        $this->addToCart($buyer, $listing, 2);

        $this->checkout($buyer, $address->id)->assertCreated();

        $this->assertSame(5, $listing->fresh()->stock);
        $this->actingAs($buyer, 'sanctum')->getJson('/api/v1/cart')->assertJsonPath('data.item_count', 2);
    }

    public function test_order_keeps_a_snapshot_of_price_title_and_address(): void
    {
        $buyer = User::factory()->create();
        $address = $this->makeAddress($buyer);
        $listing = Listing::factory()->create(['seller_id' => $this->makeSeller()->id, 'title' => 'Original title', 'price' => 1_000_000, 'stock' => 5]);
        $this->addToCart($buyer, $listing);

        $this->checkout($buyer, $address->id)->assertCreated();

        $listing->update(['title' => 'Changed title', 'price' => 9_999_999]);
        $address->update(['street' => 'Somewhere else']);

        $order = Order::firstOrFail();
        $this->assertSame('Original title', $order->items->first()->title);
        $this->assertSame(1_000_000, $order->items->first()->unit_price);
        $this->assertSame('12 Allen Avenue', $order->shipping_street);
    }

    public function test_cannot_checkout_with_empty_cart(): void
    {
        $buyer = User::factory()->create();
        $address = $this->makeAddress($buyer);

        $this->checkout($buyer, $address->id)->assertUnprocessable()->assertJsonValidationErrors('cart');
        $this->assertSame(0, Checkout::count());
    }

    public function test_cannot_checkout_when_an_item_is_no_longer_available(): void
    {
        $buyer = User::factory()->create();
        $address = $this->makeAddress($buyer);
        $listing = Listing::factory()->create(['seller_id' => $this->makeSeller()->id, 'stock' => 5]);
        $this->addToCart($buyer, $listing, 3);

        $listing->update(['stock' => 1]);

        $this->checkout($buyer, $address->id)->assertUnprocessable()->assertJsonValidationErrors('cart');
        $this->assertSame(0, Order::count());
    }

    public function test_cannot_use_another_users_address(): void
    {
        $buyer = User::factory()->create();
        $stranger = User::factory()->create();
        $strangersAddress = $this->makeAddress($stranger);
        $listing = Listing::factory()->create(['seller_id' => $this->makeSeller()->id, 'stock' => 5]);
        $this->addToCart($buyer, $listing);

        $this->checkout($buyer, $strangersAddress->id)->assertUnprocessable()->assertJsonValidationErrors('address_id');
        $this->assertSame(0, Checkout::count());
    }

    public function test_new_checkout_cancels_the_previous_unpaid_one(): void
    {
        $buyer = User::factory()->create();
        $address = $this->makeAddress($buyer);
        $listing = Listing::factory()->create(['seller_id' => $this->makeSeller()->id, 'stock' => 5]);
        $this->addToCart($buyer, $listing);

        $firstRef = $this->checkout($buyer, $address->id)->assertCreated()->json('data.reference');
        $secondRef = $this->checkout($buyer, $address->id)->assertCreated()->json('data.reference');

        $this->assertNotSame($firstRef, $secondRef);
        $this->assertSame(CheckoutStatus::Cancelled, Checkout::where('reference', $firstRef)->first()->status);
        $this->assertSame(CheckoutStatus::Pending, Checkout::where('reference', $secondRef)->first()->status);

        $firstId = Checkout::where('reference', $firstRef)->value('id');
        $this->assertSame(OrderStatus::Cancelled, Order::where('checkout_id', $firstId)->first()->status);
        $this->assertSame(1, Order::where('status', OrderStatus::PendingPayment->value)->count());
    }

    public function test_buyer_can_view_only_their_own_checkout(): void
    {
        $buyer = User::factory()->create();
        $other = User::factory()->create();
        $address = $this->makeAddress($buyer);
        $listing = Listing::factory()->create(['seller_id' => $this->makeSeller()->id, 'stock' => 5]);
        $this->addToCart($buyer, $listing);
        $reference = $this->checkout($buyer, $address->id)->json('data.reference');

        $this->actingAs($buyer, 'sanctum')->getJson("/api/v1/checkouts/{$reference}")
            ->assertOk()->assertJsonPath('data.reference', $reference);
        $this->actingAs($other, 'sanctum')->getJson("/api/v1/checkouts/{$reference}")->assertNotFound();
    }

    public function test_checkout_requires_login(): void
    {
        $this->postJson('/api/v1/checkout', ['address_id' => 1])->assertUnauthorized();
    }

    // ---------- Reading orders ----------

    public function test_buyer_sees_only_paid_orders_without_commission_or_seller_contact(): void
    {
        $buyer = User::factory()->create();
        $seller = $this->makeSeller('Ada Phones');
        $order = $this->makePaidOrder($buyer, $seller);

        // An unpaid order must not show up in the list.
        $address = $this->makeAddress($buyer);
        $listing = Listing::factory()->create(['seller_id' => $seller->id, 'stock' => 5]);
        $this->addToCart($buyer, $listing);
        $this->checkout($buyer, $address->id)->assertCreated();

        $list = $this->actingAs($buyer, 'sanctum')->getJson('/api/v1/orders')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.order_number', $order->order_number);

        $detail = $this->actingAs($buyer, 'sanctum')->getJson("/api/v1/orders/{$order->order_number}")
            ->assertOk()
            ->assertJsonPath('data.seller.store_name', 'Ada Phones')
            ->assertJsonMissingPath('data.commission');

        $this->assertStringNotContainsString($seller->email, $detail->getContent());
    }

    public function test_seller_sees_own_sales_with_earnings_and_not_the_buyers_email(): void
    {
        $buyer = User::factory()->create();
        $seller = $this->makeSeller();
        $otherSeller = $this->makeSeller();
        $order = $this->makePaidOrder($buyer, $seller, 10_000_000);
        $this->makePaidOrder($buyer, $otherSeller);

        $this->actingAs($seller, 'sanctum')->getJson('/api/v1/seller/orders')
            ->assertOk()->assertJsonCount(1, 'data');

        $detail = $this->actingAs($seller, 'sanctum')->getJson("/api/v1/seller/orders/{$order->order_number}")
            ->assertOk()
            ->assertJsonPath('data.commission', 500_000)
            ->assertJsonPath('data.earnings', 9_500_000)
            ->assertJsonPath('data.earnings_formatted', '₦95,000.00')
            ->assertJsonPath('data.ship_to.street', '12 Allen Avenue');

        $this->assertStringNotContainsString($buyer->email, $detail->getContent());
    }

    public function test_orders_are_private(): void
    {
        $buyer = User::factory()->create();
        $seller = $this->makeSeller();
        $stranger = $this->makeSeller();
        $order = $this->makePaidOrder($buyer, $seller);

        $this->actingAs($stranger, 'sanctum')->getJson("/api/v1/orders/{$order->order_number}")->assertNotFound();
        $this->actingAs($stranger, 'sanctum')->getJson("/api/v1/seller/orders/{$order->order_number}")->assertNotFound();
        // A seller cannot read an order through the buyer endpoint, nor a buyer through the seller one.
        $this->actingAs($seller, 'sanctum')->getJson("/api/v1/orders/{$order->order_number}")->assertNotFound();
        $this->actingAs($buyer, 'sanctum')->getJson("/api/v1/seller/orders/{$order->order_number}")->assertForbidden();
    }

    public function test_order_list_status_filter(): void
    {
        $buyer = User::factory()->create();
        $seller = $this->makeSeller();
        $this->makePaidOrder($buyer, $seller);
        $this->makePaidOrder($buyer, $seller, 5_000_000, ['status' => OrderStatus::Shipped]);

        $this->actingAs($buyer, 'sanctum')->getJson('/api/v1/orders?status=shipped')->assertJsonCount(1, 'data');
        $this->actingAs($buyer, 'sanctum')->getJson('/api/v1/orders?status=nonsense')->assertUnprocessable();
    }
}
