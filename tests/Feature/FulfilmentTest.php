<?php

namespace Tests\Feature;

use App\Enums\CheckoutStatus;
use App\Enums\OrderStatus;
use App\Models\Address;
use App\Models\Checkout;
use App\Models\LedgerEntry;
use App\Models\Listing;
use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\FakesPaystackRefunds;
use Tests\Concerns\MakesMarketplaceData;
use Tests\TestCase;

class FulfilmentTest extends TestCase
{
    use FakesPaystackRefunds, MakesMarketplaceData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePaystackRefunds();
    }

    // ---------------------------------------------------------------- shipping

    public function test_seller_ships_an_order_and_the_auto_release_clock_starts(): void
    {
        $buyer = User::factory()->create();
        $seller = $this->makeSeller();
        $order = $this->makeEscrowedOrder($buyer, $seller);

        $this->actingAs($seller, 'sanctum')
            ->postJson("/api/v1/seller/orders/{$order->order_number}/ship", ['tracking_info' => 'GIG Logistics 123456'])
            ->assertOk()
            ->assertJsonPath('data.status', 'shipped')
            ->assertJsonPath('data.tracking_info', 'GIG Logistics 123456');

        $order->refresh();
        $this->assertNotNull($order->shipped_at);
        $this->assertEqualsWithDelta(
            now()->addDays((int) config('marketplace.auto_release_days'))->timestamp,
            $order->auto_release_at->timestamp,
            5,
        );
    }

    public function test_shipping_rules(): void
    {
        $buyer = User::factory()->create();
        $seller = $this->makeSeller();
        $stranger = $this->makeSeller();
        $order = $this->makeEscrowedOrder($buyer, $seller);
        $url = "/api/v1/seller/orders/{$order->order_number}/ship";

        $this->actingAs($seller, 'sanctum')->postJson($url, [])->assertUnprocessable()->assertJsonValidationErrors('tracking_info');
        $this->actingAs($stranger, 'sanctum')->postJson($url, ['tracking_info' => 'Rider Ade 0801'])->assertNotFound();
        $this->actingAs($buyer, 'sanctum')->postJson($url, ['tracking_info' => 'Rider Ade 0801'])->assertForbidden();

        $this->actingAs($seller, 'sanctum')->postJson($url, ['tracking_info' => 'Rider Ade 0801'])->assertOk();
        $this->actingAs($seller, 'sanctum')->postJson($url, ['tracking_info' => 'Rider Ade 0801'])
            ->assertUnprocessable()->assertJsonValidationErrors('status'); // cannot ship twice
    }

    // ---------------------------------------------------------------- confirming delivery

    public function test_buyer_confirms_and_the_sellers_money_becomes_available(): void
    {
        $buyer = User::factory()->create();
        $seller = $this->makeSeller();
        $order = $this->makeEscrowedOrder($buyer, $seller, 10_000_000); // earnings 9,500,000, commission 500,000
        $this->actingAs($seller, 'sanctum')->postJson("/api/v1/seller/orders/{$order->order_number}/ship", ['tracking_info' => 'Rider Ade 0801'])->assertOk();

        $this->actingAs($buyer, 'sanctum')->postJson("/api/v1/orders/{$order->order_number}/confirm")
            ->assertOk()->assertJsonPath('data.status', 'completed');

        $order->refresh();
        $this->assertSame(OrderStatus::Completed, $order->status);
        $this->assertNotNull($order->delivered_at);
        $this->assertNotNull($order->released_at);

        $sellerWallet = Wallet::where('user_id', $seller->id)->firstOrFail();
        $platformWallet = Wallet::where('type', 'platform')->firstOrFail();
        $this->assertSame(0, $sellerWallet->pending_balance);
        $this->assertSame(9_500_000, $sellerWallet->available_balance);
        $this->assertSame(0, $platformWallet->pending_balance);
        $this->assertSame(500_000, $platformWallet->available_balance);
        $this->assertSame(6, LedgerEntry::count()); // 2 holds + 4 release rows
    }

    public function test_confirming_twice_pays_the_seller_once(): void
    {
        $buyer = User::factory()->create();
        $seller = $this->makeSeller();
        $order = $this->makeEscrowedOrder($buyer, $seller);
        $this->actingAs($seller, 'sanctum')->postJson("/api/v1/seller/orders/{$order->order_number}/ship", ['tracking_info' => 'Rider Ade 0801'])->assertOk();

        $this->actingAs($buyer, 'sanctum')->postJson("/api/v1/orders/{$order->order_number}/confirm")->assertOk();
        $this->actingAs($buyer, 'sanctum')->postJson("/api/v1/orders/{$order->order_number}/confirm")->assertOk();

        $this->assertSame(9_500_000, Wallet::where('user_id', $seller->id)->value('available_balance'));
        $this->assertSame(6, LedgerEntry::count());
    }

    public function test_cannot_confirm_before_shipping_or_someone_elses_order(): void
    {
        $buyer = User::factory()->create();
        $seller = $this->makeSeller();
        $order = $this->makeEscrowedOrder($buyer, $seller);

        $this->actingAs($buyer, 'sanctum')->postJson("/api/v1/orders/{$order->order_number}/confirm")
            ->assertUnprocessable()->assertJsonValidationErrors('status');

        $this->actingAs(User::factory()->create(), 'sanctum')->postJson("/api/v1/orders/{$order->order_number}/confirm")
            ->assertNotFound();
        $this->actingAs($seller, 'sanctum')->postJson("/api/v1/orders/{$order->order_number}/confirm")
            ->assertNotFound(); // the seller cannot confirm on the buyer's behalf

        $this->assertSame(0, Wallet::where('user_id', $seller->id)->value('available_balance'));
    }

    // ---------------------------------------------------------------- cancelling before shipping

    public function test_buyer_cancels_before_shipping_and_everything_is_reversed(): void
    {
        $buyer = User::factory()->create();
        $seller = $this->makeSeller();
        $listing = Listing::factory()->create(['seller_id' => $seller->id, 'stock' => 3]); // 5 originally, 2 reserved
        $order = $this->makeEscrowedOrder($buyer, $seller, 10_000_000, [], $listing, 2);

        $this->actingAs($buyer, 'sanctum')->postJson("/api/v1/orders/{$order->order_number}/cancel")
            ->assertOk()->assertJsonPath('data.status', 'refunded');

        $this->assertSame(OrderStatus::Refunded, $order->fresh()->status);
        $this->assertSame(5, $listing->fresh()->stock); // reserved stock goes back on sale
        $this->assertSame(0, Wallet::where('user_id', $seller->id)->value('pending_balance'));
        $this->assertSame(0, Wallet::where('type', 'platform')->value('pending_balance'));
        $this->assertSame(0, Wallet::where('user_id', $seller->id)->value('available_balance'));

        $refund = $order->refunds()->firstOrFail();
        $this->assertSame(10_000_000, $refund->amount);
        $this->assertSame('buyer_cancelled', $refund->reason);
        $this->assertSame('pending', $refund->status->value);
        $this->assertSame('3018284', $refund->paystack_refund_id);

        \Illuminate\Support\Facades\Http::assertSent(fn ($request) => str_ends_with($request->url(), '/refund')
            && $request['transaction'] === $refund->payment->paystack_reference
            && $request['amount'] === 10_000_000);
    }

    public function test_seller_can_reject_an_order_before_shipping(): void
    {
        $buyer = User::factory()->create();
        $seller = $this->makeSeller();
        $order = $this->makeEscrowedOrder($buyer, $seller);

        $this->actingAs($seller, 'sanctum')->postJson("/api/v1/seller/orders/{$order->order_number}/cancel")
            ->assertOk()->assertJsonPath('data.status', 'refunded');

        $this->assertSame('seller_cancelled', $order->refunds()->firstOrFail()->reason);
    }

    public function test_cannot_cancel_after_shipping(): void
    {
        $buyer = User::factory()->create();
        $seller = $this->makeSeller();
        $order = $this->makeEscrowedOrder($buyer, $seller);
        $this->actingAs($seller, 'sanctum')->postJson("/api/v1/seller/orders/{$order->order_number}/ship", ['tracking_info' => 'Rider Ade 0801'])->assertOk();

        $this->actingAs($buyer, 'sanctum')->postJson("/api/v1/orders/{$order->order_number}/cancel")
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->actingAs($seller, 'sanctum')->postJson("/api/v1/seller/orders/{$order->order_number}/cancel")
            ->assertUnprocessable();

        $this->assertSame(0, \App\Models\Refund::count());
    }

    public function test_refund_request_failing_does_not_undo_the_cancellation(): void
    {
        $buyer = User::factory()->create();
        $seller = $this->makeSeller();
        $order = $this->makeEscrowedOrder($buyer, $seller);
        $this->refundGateway = 'down';

        $this->actingAs($buyer, 'sanctum')->postJson("/api/v1/orders/{$order->order_number}/cancel")
            ->assertOk()->assertJsonPath('data.status', 'refunded');

        // The buyer is owed the money; the refund row says so loudly for an admin to handle.
        $refund = $order->refunds()->firstOrFail();
        $this->assertSame('failed', $refund->status->value);
        $this->assertNotNull($refund->failure_note);
    }

    // ---------------------------------------------------------------- scheduled jobs

    public function test_auto_release_pays_overdue_shipped_orders_only(): void
    {
        $buyer = User::factory()->create();
        $seller = $this->makeSeller();
        $due = $this->makeEscrowedOrder($buyer, $seller, 10_000_000, [
            'status' => OrderStatus::Shipped, 'shipped_at' => now()->subDays(8), 'auto_release_at' => now()->subMinute(),
        ]);
        $notYet = $this->makeEscrowedOrder($buyer, $seller, 10_000_000, [
            'status' => OrderStatus::Shipped, 'shipped_at' => now(), 'auto_release_at' => now()->addDays(3),
        ]);

        Artisan::call('marketplace:release-due-orders');

        $this->assertSame(OrderStatus::Completed, $due->fresh()->status);
        $this->assertSame(OrderStatus::Shipped, $notYet->fresh()->status);
        $this->assertSame(9_500_000, Wallet::where('user_id', $seller->id)->value('available_balance'));
        $this->assertSame(9_500_000, Wallet::where('user_id', $seller->id)->value('pending_balance')); // the other order, still in escrow
    }

    public function test_unshipped_orders_past_the_deadline_are_cancelled_and_refunded(): void
    {
        $buyer = User::factory()->create();
        $seller = $this->makeSeller();
        $late = $this->makeEscrowedOrder($buyer, $seller, 10_000_000, ['ship_by_at' => now()->subHour()]);
        $onTime = $this->makeEscrowedOrder($buyer, $seller, 10_000_000, ['ship_by_at' => now()->addDay()]);

        Artisan::call('marketplace:cancel-overdue-orders');

        $this->assertSame(OrderStatus::Refunded, $late->fresh()->status);
        $this->assertSame('seller_did_not_ship', $late->refunds()->firstOrFail()->reason);
        $this->assertSame(OrderStatus::Paid, $onTime->fresh()->status);
    }

    public function test_stale_checkouts_expire_and_their_orders_are_cancelled(): void
    {
        $buyer = User::factory()->create();
        $address = $this->makeAddress($buyer);
        $seller = $this->makeSeller();
        $listing = Listing::factory()->create(['seller_id' => $seller->id, 'stock' => 5]);
        $this->actingAs($buyer, 'sanctum')->postJson('/api/v1/cart/items', ['listing_id' => $listing->id])->assertOk();
        $this->actingAs($buyer, 'sanctum')->postJson('/api/v1/checkout', ['address_id' => $address->id])->assertCreated();

        // Fresh checkout is left alone.
        Artisan::call('marketplace:expire-checkouts');
        $this->assertSame(CheckoutStatus::Pending, Checkout::firstOrFail()->status);

        Checkout::query()->update(['created_at' => now()->subHours(2)]);
        Artisan::call('marketplace:expire-checkouts');

        $this->assertSame(CheckoutStatus::Expired, Checkout::firstOrFail()->status);
        $this->assertSame(OrderStatus::Cancelled, Order::firstOrFail()->status);
    }

    public function test_the_scheduler_actually_has_the_three_jobs_registered(): void
    {
        $commands = collect(app(Schedule::class)->events())->map(fn ($event) => (string) $event->command)->implode(' ');

        $this->assertStringContainsString('marketplace:release-due-orders', $commands);
        $this->assertStringContainsString('marketplace:cancel-overdue-orders', $commands);
        $this->assertStringContainsString('marketplace:expire-checkouts', $commands);
    }
}
