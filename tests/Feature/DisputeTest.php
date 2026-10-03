<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Dispute;
use App\Models\Listing;
use App\Models\Order;
use App\Models\Refund;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesPaystackRefunds;
use Tests\Concerns\MakesMarketplaceData;
use Tests\TestCase;

class DisputeTest extends TestCase
{
    use FakesPaystackRefunds, MakesMarketplaceData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePaystackRefunds();
    }

    private function shippedOrder(User $buyer, User $seller, ?Listing $listing = null, int $quantity = 1): Order
    {
        return $this->makeEscrowedOrder($buyer, $seller, 10_000_000, [
            'status' => OrderStatus::Shipped,
            'shipped_at' => now()->subDays(2),
            'tracking_info' => 'Rider Ade 0801',
            'auto_release_at' => now()->addDays(5),
        ], $listing, $quantity);
    }

    private function openDispute(User $buyer, Order $order)
    {
        return $this->actingAs($buyer, 'sanctum')->postJson("/api/v1/orders/{$order->order_number}/dispute", [
            'reason' => 'not_received',
            'details' => 'The rider never arrived and the phone is switched off.',
        ]);
    }

    // ---------------------------------------------------------------- opening

    public function test_buyer_reports_a_problem_and_the_order_is_frozen(): void
    {
        $buyer = User::factory()->create();
        $order = $this->shippedOrder($buyer, $this->makeSeller());

        $this->openDispute($buyer, $order)->assertOk()->assertJsonPath('data.status', 'disputed');

        $dispute = Dispute::firstOrFail();
        $this->assertSame($order->id, $dispute->order_id);
        $this->assertSame($buyer->id, $dispute->opened_by);
        $this->assertSame('open', $dispute->status);
        $this->assertSame('not_received', $dispute->reason);
    }

    public function test_dispute_input_is_validated(): void
    {
        $buyer = User::factory()->create();
        $order = $this->shippedOrder($buyer, $this->makeSeller());
        $url = "/api/v1/orders/{$order->order_number}/dispute";

        $this->actingAs($buyer, 'sanctum')->postJson($url, ['reason' => 'because', 'details' => 'A long enough description here.'])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->actingAs($buyer, 'sanctum')->postJson($url, ['reason' => 'damaged', 'details' => 'short'])
            ->assertUnprocessable()->assertJsonValidationErrors('details');
        $this->assertSame(0, Dispute::count());
    }

    public function test_only_a_shipped_order_can_be_disputed_and_only_once(): void
    {
        $buyer = User::factory()->create();
        $seller = $this->makeSeller();

        $unshipped = $this->makeEscrowedOrder($buyer, $seller); // still "paid": the buyer should cancel instead
        $this->openDispute($buyer, $unshipped)->assertUnprocessable()->assertJsonValidationErrors('status');

        $completed = $this->makeEscrowedOrder($buyer, $seller, 10_000_000, ['status' => OrderStatus::Completed, 'released_at' => now()]);
        $this->openDispute($buyer, $completed)->assertUnprocessable();

        $shipped = $this->shippedOrder($buyer, $seller);
        $this->openDispute($buyer, $shipped)->assertOk();
        $this->openDispute($buyer, $shipped)->assertUnprocessable(); // already disputed

        $this->assertSame(1, Dispute::count());
    }

    public function test_only_the_buyer_can_dispute(): void
    {
        $buyer = User::factory()->create();
        $seller = $this->makeSeller();
        $order = $this->shippedOrder($buyer, $seller);

        $this->openDispute($seller, $order)->assertNotFound();
        $this->openDispute(User::factory()->create(), $order)->assertNotFound();
    }

    public function test_a_disputed_order_is_not_auto_released(): void
    {
        $buyer = User::factory()->create();
        $seller = $this->makeSeller();
        $order = $this->shippedOrder($buyer, $seller);
        $this->openDispute($buyer, $order)->assertOk();

        $order->update(['auto_release_at' => now()->subDay()]); // timer is long overdue
        Artisan::call('marketplace:release-due-orders');

        $this->assertSame(OrderStatus::Disputed, $order->fresh()->status);
        $this->assertSame(0, Wallet::where('user_id', $seller->id)->value('available_balance'));
    }

    // ---------------------------------------------------------------- admin decides

    public function test_admin_sees_open_disputes_with_what_they_need_to_decide(): void
    {
        $admin = $this->makeAdmin();
        $buyer = User::factory()->create();
        $order = $this->shippedOrder($buyer, $this->makeSeller('Ada Phones'));
        $this->openDispute($buyer, $order)->assertOk();

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/disputes')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reason', 'not_received')
            ->assertJsonPath('data.0.order.order_number', $order->order_number)
            ->assertJsonPath('data.0.order.store_name', 'Ada Phones')
            ->assertJsonPath('data.0.order.tracking_info', 'Rider Ade 0801')
            ->assertJsonPath('data.0.opened_by.email', $buyer->email);
    }

    public function test_admin_rules_for_the_seller_and_the_money_is_released(): void
    {
        $admin = $this->makeAdmin();
        $buyer = User::factory()->create();
        $seller = $this->makeSeller();
        $order = $this->shippedOrder($buyer, $seller);
        $this->openDispute($buyer, $order)->assertOk();
        $dispute = Dispute::firstOrFail();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/disputes/{$dispute->id}/resolve", ['resolution' => 'release', 'note' => 'Tracking shows delivery.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'resolved')
            ->assertJsonPath('data.resolution', 'release');

        $this->assertSame(OrderStatus::Completed, $order->fresh()->status);
        $this->assertSame(9_500_000, Wallet::where('user_id', $seller->id)->value('available_balance'));
        $this->assertSame(0, Wallet::where('user_id', $seller->id)->value('pending_balance'));
        $this->assertSame($admin->id, $dispute->fresh()->resolved_by);
        $this->assertNotNull($dispute->fresh()->resolved_at);
        $this->assertSame(0, Refund::count());
    }

    public function test_admin_rules_for_the_buyer_and_the_money_goes_back(): void
    {
        $admin = $this->makeAdmin();
        $buyer = User::factory()->create();
        $seller = $this->makeSeller();
        $listing = Listing::factory()->create(['seller_id' => $seller->id, 'stock' => 3]);
        $order = $this->shippedOrder($buyer, $seller, $listing, 2);
        $this->openDispute($buyer, $order)->assertOk();
        $dispute = Dispute::firstOrFail();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/disputes/{$dispute->id}/resolve", ['resolution' => 'refund', 'note' => 'Seller could not prove delivery.'])
            ->assertOk()->assertJsonPath('data.resolution', 'refund');

        $this->assertSame(OrderStatus::Refunded, $order->fresh()->status);
        $this->assertSame(0, Wallet::where('user_id', $seller->id)->value('pending_balance'));
        $this->assertSame(0, Wallet::where('user_id', $seller->id)->value('available_balance'));
        $this->assertSame(3, $listing->fresh()->stock); // shipped goods are NOT put back on sale automatically

        $refund = Refund::firstOrFail();
        $this->assertSame('dispute_refund', $refund->reason);
        $this->assertSame(10_000_000, $refund->amount);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/refund') && $request['amount'] === 10_000_000);
    }

    public function test_a_dispute_can_only_be_resolved_once_and_with_a_valid_decision(): void
    {
        $admin = $this->makeAdmin();
        $buyer = User::factory()->create();
        $order = $this->shippedOrder($buyer, $this->makeSeller());
        $this->openDispute($buyer, $order)->assertOk();
        $dispute = Dispute::firstOrFail();
        $url = "/api/v1/admin/disputes/{$dispute->id}/resolve";

        $this->actingAs($admin, 'sanctum')->postJson($url, ['resolution' => 'split'])
            ->assertUnprocessable()->assertJsonValidationErrors('resolution');

        $this->actingAs($admin, 'sanctum')->postJson($url, ['resolution' => 'release'])->assertOk();
        $this->actingAs($admin, 'sanctum')->postJson($url, ['resolution' => 'refund'])
            ->assertUnprocessable()->assertJsonValidationErrors('dispute');

        $this->assertSame(OrderStatus::Completed, $order->fresh()->status); // the second decision changed nothing
        $this->assertSame(0, Refund::count());
    }

    public function test_buyers_and_sellers_cannot_resolve_disputes(): void
    {
        $buyer = User::factory()->create();
        $seller = $this->makeSeller();
        $order = $this->shippedOrder($buyer, $seller);
        $this->openDispute($buyer, $order)->assertOk();
        $dispute = Dispute::firstOrFail();

        $this->actingAs($buyer, 'sanctum')->postJson("/api/v1/admin/disputes/{$dispute->id}/resolve", ['resolution' => 'refund'])->assertForbidden();
        $this->actingAs($seller, 'sanctum')->postJson("/api/v1/admin/disputes/{$dispute->id}/resolve", ['resolution' => 'release'])->assertForbidden();

        $this->assertSame('open', $dispute->fresh()->status);
    }
}
