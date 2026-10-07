<?php

namespace Tests\Feature;

use App\Enums\KycStatus;
use App\Enums\ListingStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PayoutStatus;
use App\Enums\RefundStatus;
use App\Models\AuditLog;
use App\Models\Checkout;
use App\Models\Dispute;
use App\Models\KycSubmission;
use App\Models\Listing;
use App\Models\Message;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\Refund;
use App\Models\User;
use App\Models\Wallet;
use App\Services\LedgerService;
use App\Notifications\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Concerns\MakesMarketplaceData;
use Tests\TestCase;

class AdminModerationTest extends TestCase
{
    use MakesMarketplaceData, RefreshDatabase;

    private const REASON = 'Photos are stolen from another website.';

    // ---------------------------------------------------------------- helpers

    private function removeAs(User $admin, Listing $listing, string $reason = self::REASON)
    {
        return $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/listings/{$listing->id}/remove", ['reason' => $reason]);
    }

    private function restoreAs(User $admin, Listing $listing)
    {
        return $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/listings/{$listing->id}/restore");
    }

    private function countKind(User $user, string $kind): int
    {
        return Notification::sent($user, UserNotification::class)
            ->filter(fn (UserNotification $n) => $n->kind === $kind)
            ->count();
    }

    private function firstOfKind(User $user, string $kind): UserNotification
    {
        return Notification::sent($user, UserNotification::class)->first(fn (UserNotification $n) => $n->kind === $kind);
    }

    private function overview(User $admin, string $query = '')
    {
        return $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/overview'.$query);
    }

    /** A payment that came in but could not become an order, with no order rows at all. */
    private function paymentNeedingRefund(User $buyer, int $amount = 10_000_000): Payment
    {
        $checkout = Checkout::create([
            'buyer_id' => $buyer->id,
            'reference' => 'CHK-'.Str::upper(Str::random(12)),
            'total' => $amount,
            'status' => 'cancelled',
        ]);

        return Payment::create([
            'checkout_id' => $checkout->id,
            'paystack_reference' => 'PAY-NEEDS'.random_int(1000, 9999),
            'amount' => $amount,
            'currency' => 'NGN',
            'status' => PaymentStatus::NeedsRefund,
        ]);
    }

    /** A fake Paystack that can start and confirm a card payment. */
    private function fakePaystackForPayments(): void
    {
        config(['marketplace.paystack.secret_key' => 'sk_test_secret']);

        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, '/transaction/initialize')) {
                return Http::response(['status' => true, 'message' => 'ok', 'data' => [
                    'authorization_url' => 'https://checkout.paystack.com/abc123',
                    'access_code' => 'abc123',
                    'reference' => $request['reference'],
                ]]);
            }

            if (str_contains($url, '/transaction/verify/')) {
                $reference = basename(parse_url($url, PHP_URL_PATH));

                return Http::response(['status' => true, 'message' => 'ok', 'data' => [
                    'id' => 987,
                    'reference' => $reference,
                    'status' => 'success',
                    'amount' => Payment::where('paystack_reference', $reference)->value('amount'),
                    'currency' => 'NGN',
                    'paid_at' => '2026-10-03T10:00:00.000Z',
                    'channel' => 'card',
                    'gateway_response' => 'Successful',
                ]]);
            }

            return Http::response([], 404);
        });
    }

    private function sendChargeWebhook(Payment $payment)
    {
        $body = json_encode(['event' => 'charge.success', 'data' => [
            'id' => 987,
            'reference' => $payment->paystack_reference,
            'status' => 'success',
            'amount' => $payment->amount,
            'currency' => 'NGN',
        ]]);

        return $this->call('POST', '/api/v1/webhooks/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, 'sk_test_secret'),
        ], $body);
    }

    // ================================================================ listing moderation

    public function test_removing_a_listing_takes_it_off_the_market_and_tells_the_seller(): void
    {
        Notification::fake();
        $admin = $this->makeAdmin();
        $seller = $this->makeSeller('Ada Stores');
        $buyer = User::factory()->create();
        $listing = Listing::factory()->create(['seller_id' => $seller->id, 'stock' => 5, 'title' => 'Samsung Galaxy A54']);

        $this->getJson("/api/v1/listings/{$listing->slug}")->assertOk();

        $this->removeAs($admin, $listing)
            ->assertOk()
            ->assertJsonPath('data.status', 'removed')
            ->assertJsonPath('data.removal_reason', self::REASON)
            ->assertJsonPath('data.seller.store_name', 'Ada Stores');

        $fresh = $listing->fresh();
        $this->assertSame(ListingStatus::Removed, $fresh->status);
        $this->assertNotNull($fresh->removed_at);
        $this->assertSame(self::REASON, $fresh->removal_reason);

        // Off the market: not public, not in the catalog, not buyable, no new chats.
        $this->getJson("/api/v1/listings/{$listing->slug}")->assertNotFound();
        $this->getJson('/api/v1/listings')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($buyer, 'sanctum')->postJson('/api/v1/cart/items', ['listing_id' => $listing->id, 'quantity' => 1])->assertClientError();
        $this->actingAs($buyer, 'sanctum')->postJson("/api/v1/listings/{$listing->slug}/conversation")->assertNotFound();

        // The seller sees why, and cannot publish it again.
        $this->actingAs($seller, 'sanctum')->getJson("/api/v1/seller/listings/{$listing->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'removed')
            ->assertJsonPath('data.removal_reason', self::REASON);
        $this->actingAs($seller, 'sanctum')->postJson("/api/v1/seller/listings/{$listing->id}/publish")
            ->assertUnprocessable()->assertJsonValidationErrors('status');

        // The seller is told once, by email, with the title and the reason.
        $this->assertSame(1, $this->countKind($seller, 'listing.removed'));
        $note = $this->firstOfKind($seller, 'listing.removed');
        $this->assertTrue($note->email);
        $this->assertStringContainsString('Samsung Galaxy A54', $note->body);
        $this->assertStringContainsString(self::REASON, $note->body);

        // The action is in the audit log.
        $this->assertSame(1, AuditLog::where('action', 'POST api/v1/admin/listings/{listing}/remove')->where('admin_id', $admin->id)->count());
    }

    public function test_restoring_a_listing_brings_it_back_as_a_draft_for_the_seller_to_publish(): void
    {
        Notification::fake();
        $admin = $this->makeAdmin();
        $seller = $this->makeSeller();
        $listing = Listing::factory()->create(['seller_id' => $seller->id, 'stock' => 5]);

        $this->removeAs($admin, $listing)->assertOk();
        $this->restoreAs($admin, $listing)
            ->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.removal_reason', null)
            ->assertJsonPath('data.removed_at', null);

        $fresh = $listing->fresh();
        $this->assertSame(ListingStatus::Draft, $fresh->status);
        $this->assertNull($fresh->removed_at);
        $this->assertNull($fresh->removal_reason);

        // A draft is not public: the seller decides when it goes live again.
        $this->getJson("/api/v1/listings/{$listing->slug}")->assertNotFound();

        // The seller no longer sees a removal, and is back to the normal publishing rules (this listing has no photo yet).
        $this->actingAs($seller, 'sanctum')->getJson("/api/v1/seller/listings/{$listing->id}")
            ->assertOk()->assertJsonMissingPath('data.removal_reason');
        $this->actingAs($seller, 'sanctum')->postJson("/api/v1/seller/listings/{$listing->id}/publish")
            ->assertUnprocessable()->assertJsonValidationErrors('images');

        $this->assertSame(1, $this->countKind($seller, 'listing.restored'));
        $this->assertFalse($this->firstOfKind($seller, 'listing.restored')->email);
    }

    public function test_the_rules_for_removing_and_restoring(): void
    {
        $admin = $this->makeAdmin();
        $seller = $this->makeSeller();
        $listing = Listing::factory()->create(['seller_id' => $seller->id]);

        $this->postJson("/api/v1/admin/listings/{$listing->id}/remove", ['reason' => self::REASON])->assertUnauthorized();
        $this->removeAs($seller, $listing)->assertForbidden(); // even the owner cannot use the admin route
        $this->assertSame(ListingStatus::Active, $listing->fresh()->status);

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/listings/{$listing->id}/remove", [])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->removeAs($admin, $listing, 'no')->assertUnprocessable()->assertJsonValidationErrors('reason');

        $this->restoreAs($admin, $listing)->assertUnprocessable()->assertJsonValidationErrors('listing'); // not removed

        $this->removeAs($admin, $listing)->assertOk();
        $this->removeAs($admin, $listing)->assertUnprocessable()->assertJsonValidationErrors('listing'); // already removed

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/listings/999999/remove', ['reason' => self::REASON])->assertNotFound();
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/listings/999999/restore')->assertNotFound();
    }

    public function test_the_admin_listing_list_filters_and_shows_why_a_listing_is_hidden(): void
    {
        $admin = $this->makeAdmin();
        $seller = $this->makeSeller('Ada Stores');
        $other = $this->makeSeller('Other Stores');

        $iphone = Listing::factory()->create(['seller_id' => $seller->id, 'title' => 'iPhone 13 Pro']);
        Listing::factory()->create(['seller_id' => $seller->id, 'title' => 'Draft laptop', 'status' => ListingStatus::Draft]);
        $removed = Listing::factory()->create(['seller_id' => $other->id, 'title' => 'Fake watch', 'status' => ListingStatus::Removed]);
        $gone = Listing::factory()->create(['seller_id' => $other->id, 'title' => 'Deleted by its seller']);
        $gone->delete(); // a seller's own soft delete: not part of moderation

        $get = fn (string $query = '') => $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/listings'.$query);

        $this->getJson('/api/v1/admin/listings')->assertUnauthorized();
        $this->actingAs($seller, 'sanctum')->getJson('/api/v1/admin/listings')->assertForbidden();

        $get()->assertOk()->assertJsonCount(3, 'data');
        $get('?status=removed')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $removed->id);
        $get('?status=draft')->assertOk()->assertJsonCount(1, 'data');
        $get("?seller_id={$seller->id}")->assertOk()->assertJsonCount(2, 'data');
        $get('?search=iphone')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $iphone->id);
        $get('?search=%25')->assertOk()->assertJsonCount(0, 'data'); // a typed % is not a wildcard
        $get('?status=nonsense')->assertUnprocessable();

        // A suspended seller's listings are hidden without being removed, and the admin can tell which is which.
        $other->forceFill(['status' => 'suspended'])->save();
        $this->actingAs($admin, 'sanctum')->getJson("/api/v1/admin/listings/{$removed->id}")
            ->assertOk()
            ->assertJsonPath('data.seller.account_status', 'suspended')
            ->assertJsonPath('data.status', 'removed');
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/listings/999999')->assertNotFound();
    }

    // ================================================================ the overview

    public function test_the_overview_reports_sales_and_growth_for_the_chosen_period(): void
    {
        $admin = $this->makeAdmin();
        $seller = $this->makeSeller();
        $buyer = User::factory()->create();

        $paid1 = $this->makeEscrowedOrder($buyer, $seller, 10_000_000);
        $paid2 = $this->makeEscrowedOrder($buyer, $seller, 20_000_000);
        $done = $this->makeEscrowedOrder($buyer, $seller, 30_000_000, ['status' => OrderStatus::Completed, 'released_at' => now()]);
        $old = $this->makeEscrowedOrder($buyer, $seller, 40_000_000, ['status' => OrderStatus::Completed]);
        Order::whereKey($old->id)->update(['paid_at' => now()->subDays(60), 'released_at' => now()->subDays(59)]);
        $old = $old->fresh();
        User::factory()->create(['created_at' => now()->subDays(60)]);

        $this->getJson('/api/v1/admin/overview')->assertUnauthorized();
        $this->actingAs($seller, 'sanctum')->getJson('/api/v1/admin/overview')->assertForbidden();

        // By default: the last 30 days. The two-month-old order is outside it.
        $this->overview($admin)
            ->assertOk()
            ->assertJsonPath('data.sales.paid_orders', 3)
            ->assertJsonPath('data.sales.paid_total', $paid1->total + $paid2->total + $done->total)
            ->assertJsonPath('data.sales.completed_orders', 1)
            ->assertJsonPath('data.sales.completed_total', $done->total)
            ->assertJsonPath('data.sales.commission_earned', $done->commission)
            ->assertJsonPath('data.growth.new_users', 3)   // the admin, the seller and the buyer
            ->assertJsonPath('data.growth.new_sellers', 1);

        // A wider period brings the old order in.
        $from = now()->subDays(100)->toDateString();
        $this->overview($admin, "?from={$from}")
            ->assertOk()
            ->assertJsonPath('data.sales.paid_orders', 4)
            ->assertJsonPath('data.sales.completed_orders', 2)
            ->assertJsonPath('data.sales.completed_total', $done->total + $old->total)
            ->assertJsonPath('data.sales.commission_earned', $done->commission + $old->commission)
            ->assertJsonPath('data.growth.new_users', 4);

        // A period with nothing in it is all zeros, not an error.
        $this->overview($admin, '?from=2020-01-01&to=2020-01-31')
            ->assertOk()
            ->assertJsonPath('data.sales.paid_orders', 0)
            ->assertJsonPath('data.sales.commission_earned', 0)
            ->assertJsonPath('data.growth.new_users', 0);
    }

    public function test_the_overview_shows_what_needs_attention_right_now_whatever_the_dates(): void
    {
        $admin = $this->makeAdmin();
        $seller = $this->makeSeller();
        $buyer = User::factory()->create();

        $one = $this->makeEscrowedOrder($buyer, $seller, 10_000_000);
        $two = $this->makeEscrowedOrder($buyer, $seller, 20_000_000);
        // Release one order: its seller share becomes available to the seller, and its commission becomes ours.
        app(LedgerService::class)->releaseEscrow($one);

        // Disputes: one open, one decided.
        Dispute::create(['order_id' => $one->id, 'opened_by' => $buyer->id, 'reason' => 'not_received', 'details' => 'Never arrived at all.', 'status' => 'open']);
        Dispute::create(['order_id' => $two->id, 'opened_by' => $buyer->id, 'reason' => 'not_received', 'details' => 'Never arrived at all.', 'status' => 'resolved']);

        // Identity checks: two waiting, one done.
        foreach ([KycStatus::Pending, KycStatus::Pending, KycStatus::Verified] as $status) {
            KycSubmission::create([
                'user_id' => $seller->id, 'legal_name' => 'Ada Obi', 'id_type' => 'passport',
                'id_photo_path' => 'kyc/none.jpg', 'disk' => 'local', 'status' => $status,
            ]);
        }

        // Refunds: one failed, one needing attention, one fine. And a payment with no order.
        $payment = $this->paymentNeedingRefund($buyer);
        foreach ([RefundStatus::Failed, RefundStatus::NeedsAttention, RefundStatus::Processed] as $status) {
            Refund::create(['payment_id' => $payment->id, 'order_id' => null, 'amount' => 1_000_000, 'status' => $status, 'reason' => 'test']);
        }

        // Payouts: two in progress, one paid; one returned recently, one returned long ago.
        $payout = fn (PayoutStatus $status, int $amount) => Payout::create([
            'seller_id' => $seller->id, 'amount' => $amount, 'fee' => 0, 'status' => $status,
            'paystack_reference' => 'payout-'.Str::lower((string) Str::ulid()),
        ]);
        $payout(PayoutStatus::Processing, 1_500_000);
        $payout(PayoutStatus::Pending, 500_000);
        $payout(PayoutStatus::Paid, 700_000);
        $payout(PayoutStatus::Failed, 300_000);
        $longAgo = $payout(PayoutStatus::Reversed, 300_000);
        DB::table('payouts')->where('id', $longAgo->id)->update(['updated_at' => now()->subDays(10)]);

        // Accounts and listings.
        $seller->sellerProfile->forceFill(['kyc_status' => KycStatus::Verified])->save();
        $suspendedSeller = $this->makeSeller();
        Listing::factory()->count(2)->create(['seller_id' => $seller->id, 'stock' => 3]);
        Listing::factory()->create(['seller_id' => $seller->id, 'status' => ListingStatus::Draft]);
        Listing::factory()->create(['seller_id' => $suspendedSeller->id, 'stock' => 3]); // hidden by the suspension
        $suspendedSeller->forceFill(['status' => 'suspended'])->save();

        // The dates chosen do not affect this part: ask for a period in the past.
        $this->overview($admin, '?from=2020-01-01&to=2020-01-31')
            ->assertOk()
            ->assertJsonPath('data.money_now.escrow_held', $two->total)                     // only the second order is still held
            ->assertJsonPath('data.money_now.escrow_sellers_share', $two->sellerEarnings())
            ->assertJsonPath('data.money_now.escrow_commission', $two->commission)
            ->assertJsonPath('data.money_now.owed_to_sellers', $one->sellerEarnings())      // not our commission
            ->assertJsonPath('data.money_now.commission_earned_all_time', $one->commission)
            ->assertJsonPath('data.money_now.payouts_in_progress_count', 2)
            ->assertJsonPath('data.money_now.payouts_in_progress_total', 2_000_000)
            ->assertJsonPath('data.needs_attention.open_disputes', 1)
            ->assertJsonPath('data.needs_attention.pending_kyc', 2)
            ->assertJsonPath('data.needs_attention.failed_refunds', 2)
            ->assertJsonPath('data.needs_attention.payments_needing_refund', 1)
            ->assertJsonPath('data.needs_attention.payouts_returned_last_7_days', 1)
            ->assertJsonPath('data.totals.users', 4)            // admin, buyer, seller, suspended seller
            ->assertJsonPath('data.totals.suspended_users', 1)
            ->assertJsonPath('data.totals.sellers', 2)
            ->assertJsonPath('data.totals.verified_sellers', 1)
            ->assertJsonPath('data.totals.buyable_listings', 2) // not the draft, not the suspended seller's
            ->assertJsonPath('data.sales.refunds_requested', 0); // the refunds were made today, outside that period

        // The same numbers are counted in the period when it covers today.
        $this->overview($admin)->assertOk()->assertJsonPath('data.sales.refunds_requested', 3)->assertJsonPath('data.sales.refunds_requested_total', 3_000_000);
    }

    public function test_the_overview_checks_the_period(): void
    {
        $admin = $this->makeAdmin();

        $this->overview($admin, '?from=2026-10-10&to=2026-10-01')->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->overview($admin, '?from=not-a-date')->assertUnprocessable()->assertJsonValidationErrors('from');
        $this->overview($admin, '?from=2024-01-01&to=2026-10-01')->assertUnprocessable()->assertJsonValidationErrors('from');

        // Just inside the limit is fine, and the default really is 30 days.
        $this->overview($admin, '?from='.now()->subDays(365)->toDateString())->assertOk();

        $default = $this->overview($admin)->assertOk();
        $this->assertSame(29, (int) \Carbon\Carbon::parse($default->json('data.period.from'))->startOfDay()->diffInDays(\Carbon\Carbon::parse($default->json('data.period.to'))->startOfDay()));
    }

    // ================================================================ reading a disputed order's chat

    public function test_an_admin_reads_the_chat_of_a_disputed_order_read_only_and_it_is_logged(): void
    {
        $admin = $this->makeAdmin();
        $buyer = User::factory()->create(['name' => 'Ada Obi']);
        $seller = $this->makeSeller('Ada Stores');
        $order = $this->makeEscrowedOrder($buyer, $seller, 10_000_000, [
            'status' => OrderStatus::Shipped, 'shipped_at' => now()->subDays(2), 'tracking_info' => 'Rider 0801', 'auto_release_at' => now()->addDays(5),
        ]);

        $conversationId = $this->actingAs($buyer, 'sanctum')->postJson("/api/v1/orders/{$order->order_number}/conversation")->json('data.id');
        $this->actingAs($buyer, 'sanctum')->postJson("/api/v1/conversations/{$conversationId}/messages", ['body' => 'The rider never came.'])->assertCreated();
        $this->actingAs($seller, 'sanctum')->postJson("/api/v1/conversations/{$conversationId}/messages", ['body' => 'He was at your gate at noon.'])->assertCreated();
        $this->actingAs($buyer, 'sanctum')->postJson("/api/v1/orders/{$order->order_number}/dispute", [
            'reason' => 'not_received', 'details' => 'Nobody came and the phone was off.',
        ])->assertOk();

        $this->actingAs($admin, 'sanctum')->getJson("/api/v1/admin/orders/{$order->order_number}/chat")
            ->assertOk()
            ->assertJsonPath('data.order_number', $order->order_number)
            ->assertJsonPath('data.dispute.status', 'open')
            ->assertJsonPath('data.buyer.name', 'Ada Obi')
            ->assertJsonPath('data.seller.store_name', 'Ada Stores')
            ->assertJsonCount(2, 'data.messages')
            ->assertJsonPath('data.messages.0.from', 'buyer')
            ->assertJsonPath('data.messages.0.body', 'The rider never came.')
            ->assertJsonPath('data.messages.0.read', true)   // the seller replied, so they had read it
            ->assertJsonPath('data.messages.1.from', 'seller')
            ->assertJsonPath('data.messages.1.read', false)
            ->assertJsonPath('data.has_more', false);

        // Reading it changed nothing for the two people in the chat...
        $this->assertNull(Message::where('body', 'He was at your gate at noon.')->firstOrFail()->read_at);

        // ...and the read itself is in the audit log.
        $log = AuditLog::where('action', 'GET api/v1/admin/orders/{orderNumber}/chat')->sole();
        $this->assertSame($admin->id, $log->admin_id);
        $this->assertSame($order->order_number, $log->route_params['orderNumber']);
        $this->assertSame(200, $log->status);
    }

    public function test_an_order_chat_is_off_limits_without_a_dispute(): void
    {
        $admin = $this->makeAdmin();
        $buyer = User::factory()->create();
        $seller = $this->makeSeller();
        $order = $this->makeEscrowedOrder($buyer, $seller);

        $this->actingAs($buyer, 'sanctum')->postJson("/api/v1/orders/{$order->order_number}/conversation")->assertCreated();
        $conversationId = \App\Models\Conversation::firstOrFail()->id;
        $this->actingAs($buyer, 'sanctum')->postJson("/api/v1/conversations/{$conversationId}/messages", ['body' => 'Private words.'])->assertCreated();

        $url = "/api/v1/admin/orders/{$order->order_number}/chat";

        $this->actingAs($admin, 'sanctum')->getJson($url)->assertForbidden(); // no dispute, no access
        $this->actingAs($buyer, 'sanctum')->getJson($url)->assertForbidden();
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/orders/ORD-NOPE/chat')->assertNotFound();

        // A dispute with no chat behind it.
        $other = $this->makeEscrowedOrder($buyer, $seller);
        Dispute::create(['order_id' => $other->id, 'opened_by' => $buyer->id, 'reason' => 'not_received', 'details' => 'Never arrived at all.', 'status' => 'open']);
        $this->actingAs($admin, 'sanctum')->getJson("/api/v1/admin/orders/{$other->order_number}/chat")->assertNotFound();

        // Once a dispute exists, the first order opens up.
        Dispute::create(['order_id' => $order->id, 'opened_by' => $buyer->id, 'reason' => 'not_received', 'details' => 'Never arrived at all.', 'status' => 'open']);
        $this->actingAs($admin, 'sanctum')->getJson($url)->assertOk()->assertJsonCount(1, 'data.messages');
    }

    public function test_a_long_disputed_chat_loads_in_pages(): void
    {
        $admin = $this->makeAdmin();
        $buyer = User::factory()->create();
        $seller = $this->makeSeller();
        $order = $this->makeEscrowedOrder($buyer, $seller);
        Dispute::create(['order_id' => $order->id, 'opened_by' => $buyer->id, 'reason' => 'not_received', 'details' => 'Never arrived at all.', 'status' => 'open']);

        $conversationId = $this->actingAs($buyer, 'sanctum')->postJson("/api/v1/orders/{$order->order_number}/conversation")->json('data.id');

        foreach (range(1, 55) as $i) {
            Message::create([
                'conversation_id' => $conversationId,
                'sender_id' => $i % 2 ? $buyer->id : $seller->id,
                'recipient_id' => $i % 2 ? $seller->id : $buyer->id,
                'body' => "m{$i}",
            ]);
        }

        $url = "/api/v1/admin/orders/{$order->order_number}/chat";

        $page = $this->actingAs($admin, 'sanctum')->getJson($url)
            ->assertOk()->assertJsonCount(50, 'data.messages')->assertJsonPath('data.has_more', true)
            ->assertJsonPath('data.messages.0.body', 'm6');

        $this->actingAs($admin, 'sanctum')->getJson($url.'?before='.$page->json('data.messages.0.id'))
            ->assertOk()->assertJsonCount(5, 'data.messages')->assertJsonPath('data.has_more', false)->assertJsonPath('data.messages.0.body', 'm1');

        $this->actingAs($admin, 'sanctum')->getJson($url.'?before=abc')->assertUnprocessable();
    }

    // ================================================================ a seller suspended while a buyer is paying

    public function test_a_payment_in_flight_when_the_seller_is_suspended_is_flagged_for_refund_not_turned_into_an_order(): void
    {
        $this->fakePaystackForPayments();
        $admin = $this->makeAdmin();
        $seller = $this->makeSeller();
        $buyer = User::factory()->create();
        $listing = Listing::factory()->create(['seller_id' => $seller->id, 'price' => 10_000_000, 'stock' => 5]);
        $address = $this->makeAddress($buyer);

        $this->actingAs($buyer, 'sanctum')->postJson('/api/v1/cart/items', ['listing_id' => $listing->id, 'quantity' => 1])->assertOk();
        $reference = $this->actingAs($buyer, 'sanctum')->postJson('/api/v1/checkout', ['address_id' => $address->id])
            ->assertCreated()->json('data.reference');
        $this->actingAs($buyer, 'sanctum')->postJson("/api/v1/checkouts/{$reference}/pay")->assertOk(); // the buyer is on the payment page

        // Meanwhile an admin suspends the seller.
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/users/{$seller->id}/suspend", ['reason' => 'Reported for fraud by several buyers.'])
            ->assertOk();

        // The buyer's money arrives.
        $payment = Payment::firstOrFail();
        $this->sendChargeWebhook($payment)->assertOk();

        // No order for a suspended seller: the payment is held for a refund instead, and nothing reached escrow.
        $this->assertSame(PaymentStatus::NeedsRefund, $payment->fresh()->status);
        $this->assertSame(0, Order::where('status', OrderStatus::Paid->value)->count());
        $this->assertSame(0, (int) Wallet::where('user_id', $seller->id)->sum('pending_balance'));
        $this->assertSame(5, $listing->fresh()->stock); // nothing was taken from stock
    }
}