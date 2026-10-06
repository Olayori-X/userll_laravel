<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Checkout;
use App\Models\Dispute;
use App\Models\KycSubmission;
use App\Models\Listing;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\UserNotification;
use App\Services\RefundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\FakesPaystackRefunds;
use Tests\Concerns\MakesMarketplaceData;
use Tests\Concerns\PayoutTestHelpers;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use FakesPaystackRefunds, MakesMarketplaceData, PayoutTestHelpers, RefreshDatabase;

    // ---------------------------------------------------------------- helpers

    /** How many notifications of this kind were sent to this user (with Notification::fake() active). */
    private function countKind(User $user, string $kind): int
    {
        return Notification::sent($user, UserNotification::class)
            ->filter(fn (UserNotification $n) => $n->kind === $kind)
            ->count();
    }

    private function firstOfKind(User $user, string $kind): UserNotification
    {
        return Notification::sent($user, UserNotification::class)
            ->first(fn (UserNotification $n) => $n->kind === $kind);
    }

    private function shippedOrder(User $buyer, User $seller): Order
    {
        return $this->makeEscrowedOrder($buyer, $seller, 10_000_000, [
            'status' => OrderStatus::Shipped,
            'shipped_at' => now()->subDays(2),
            'tracking_info' => 'Rider Ade 0801',
            'auto_release_at' => now()->addDays(5),
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

    // ---------------------------------------------------------------- orders

    public function test_a_confirmed_payment_notifies_the_seller_and_the_buyer_exactly_once(): void
    {
        Notification::fake();
        $this->fakePaystackForPayments();

        $seller = $this->makeSeller();
        $buyer = User::factory()->create();
        $listing = Listing::factory()->create(['seller_id' => $seller->id, 'price' => 10_000_000, 'stock' => 5]);
        $address = $this->makeAddress($buyer);

        $this->actingAs($buyer, 'sanctum')->postJson('/api/v1/cart/items', ['listing_id' => $listing->id, 'quantity' => 1])->assertOk();
        $reference = $this->actingAs($buyer, 'sanctum')->postJson('/api/v1/checkout', ['address_id' => $address->id])
            ->assertCreated()->json('data.reference');
        $this->actingAs($buyer, 'sanctum')->postJson("/api/v1/checkouts/{$reference}/pay")->assertOk();

        $payment = Payment::firstOrFail();

        $this->assertSame(0, $this->countKind($seller, 'order.paid')); // nothing before the money arrives

        $this->sendChargeWebhook($payment)->assertOk();
        $this->sendChargeWebhook($payment)->assertOk(); // Paystack delivers it again

        $this->assertSame(1, $this->countKind($seller, 'order.paid'));
        $this->assertSame(1, $this->countKind($buyer, 'order.paid'));
        $this->assertTrue($this->firstOfKind($seller, 'order.paid')->email);
        $this->assertTrue($this->firstOfKind($buyer, 'order.paid')->email);
        $this->assertStringContainsString('/seller/orders/', $this->firstOfKind($seller, 'order.paid')->link);
        $this->assertStringContainsString('/orders/', $this->firstOfKind($buyer, 'order.paid')->link);
    }

    public function test_shipping_notifies_the_buyer_only_when_the_order_really_ships(): void
    {
        Notification::fake();
        $buyer = User::factory()->create();
        $seller = $this->makeSeller();
        $order = $this->makeEscrowedOrder($buyer, $seller);
        $url = "/api/v1/seller/orders/{$order->order_number}/ship";

        $this->actingAs($seller, 'sanctum')->postJson($url, ['tracking_info' => 'GIG Logistics 123456'])->assertOk();
        $this->actingAs($seller, 'sanctum')->postJson($url, ['tracking_info' => 'again'])->assertUnprocessable(); // cannot ship twice

        $this->assertSame(1, $this->countKind($buyer, 'order.shipped'));
        $this->assertTrue($this->firstOfKind($buyer, 'order.shipped')->email);
        $this->assertSame(0, $this->countKind($seller, 'order.shipped'));
    }

    public function test_completing_an_order_notifies_both_sides_once_even_if_confirmed_twice(): void
    {
        Notification::fake();
        $buyer = User::factory()->create();
        $seller = $this->makeSeller();
        $order = $this->shippedOrder($buyer, $seller);
        $url = "/api/v1/orders/{$order->order_number}/confirm";

        $this->actingAs($buyer, 'sanctum')->postJson($url)->assertOk();
        $this->actingAs($buyer, 'sanctum')->postJson($url); // a double click

        $this->assertSame(1, $this->countKind($seller, 'order.completed'));
        $this->assertSame(1, $this->countKind($buyer, 'order.completed'));
        $this->assertTrue($this->firstOfKind($seller, 'order.completed')->email);
        $this->assertStringContainsString('available balance', $this->firstOfKind($seller, 'order.completed')->body);
    }

    public function test_cancelling_before_shipping_tells_both_sides_and_the_buyer_hears_again_when_the_refund_lands(): void
    {
        Notification::fake();
        $this->fakePaystackRefunds();
        $buyer = User::factory()->create();
        $seller = $this->makeSeller();
        $order = $this->makeEscrowedOrder($buyer, $seller);

        $this->actingAs($buyer, 'sanctum')->postJson("/api/v1/orders/{$order->order_number}/cancel")->assertOk();

        $this->assertSame(1, $this->countKind($buyer, 'order.cancelled'));
        $this->assertSame(1, $this->countKind($seller, 'order.cancelled'));
        $this->assertSame(0, $this->countKind($buyer, 'refund.processed')); // not until Paystack finishes

        $refund = $order->refunds()->with('payment')->firstOrFail();
        $reference = $refund->payment->paystack_reference;

        app(RefundService::class)->applyGatewayEvent('refund.processed', $reference, $refund->amount);
        app(RefundService::class)->applyGatewayEvent('refund.processed', $reference, $refund->amount); // delivered twice

        $this->assertSame(1, $this->countKind($buyer, 'refund.processed'));
        $this->assertTrue($this->firstOfKind($buyer, 'refund.processed')->email);
        $this->assertSame(0, $this->countKind($seller, 'refund.processed'));
    }

    public function test_a_payment_that_never_became_an_order_still_tells_the_buyer_about_the_refund(): void
    {
        Notification::fake();
        $this->fakePaystackRefunds();
        $buyer = User::factory()->create();
        $payment = $this->paymentNeedingRefund($buyer);

        app(RefundService::class)->refundPayment($payment, 'Sold out while paying');

        $this->assertSame(1, $this->countKind($buyer, 'refund.started'));
        $this->assertSame(0, $this->countKind($buyer, 'refund.processed'));

        app(RefundService::class)->applyGatewayEvent('refund.processed', $payment->paystack_reference, $payment->amount);
        app(RefundService::class)->applyGatewayEvent('refund.processed', $payment->paystack_reference, $payment->amount);

        $this->assertSame(1, $this->countKind($buyer, 'refund.processed')); // found through the checkout, not an order
        $this->assertSame(1, $this->countKind($buyer, 'refund.started'));
    }

    // ---------------------------------------------------------------- disputes

    public function test_a_dispute_tells_the_seller_and_the_admins_and_the_decision_tells_buyer_and_seller(): void
    {
        Notification::fake();
        $this->fakePaystackRefunds();
        $admin = $this->makeAdmin();
        $buyer = User::factory()->create();
        $seller = $this->makeSeller();
        $order = $this->shippedOrder($buyer, $seller);

        $this->actingAs($buyer, 'sanctum')->postJson("/api/v1/orders/{$order->order_number}/dispute", [
            'reason' => 'not_received',
            'details' => 'The rider never arrived and the phone is switched off.',
        ])->assertOk();

        $this->assertSame(1, $this->countKind($seller, 'dispute.opened'));
        $this->assertTrue($this->firstOfKind($seller, 'dispute.opened')->email);
        $this->assertSame(1, $this->countKind($admin, 'admin.dispute_opened'));
        $this->assertSame(0, $this->countKind($buyer, 'dispute.opened')); // the buyer knows: they just did it

        $dispute = Dispute::firstOrFail();
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/disputes/{$dispute->id}/resolve", ['resolution' => 'release', 'note' => 'Tracking shows delivery.'])
            ->assertOk();

        $this->assertSame(1, $this->countKind($buyer, 'dispute.resolved'));
        $this->assertSame(1, $this->countKind($seller, 'dispute.resolved'));
        $this->assertStringContainsString('released to the seller', $this->firstOfKind($buyer, 'dispute.resolved')->body);
        $this->assertStringContainsString('in your favour', $this->firstOfKind($seller, 'dispute.resolved')->body);
        $this->assertSame(1, $this->countKind($seller, 'order.completed')); // releasing the money completes the order
    }

    public function test_a_dispute_decided_for_the_buyer_says_so_and_does_not_send_a_cancellation(): void
    {
        Notification::fake();
        $this->fakePaystackRefunds();
        $admin = $this->makeAdmin();
        $buyer = User::factory()->create();
        $seller = $this->makeSeller();
        $order = $this->shippedOrder($buyer, $seller);

        $this->actingAs($buyer, 'sanctum')->postJson("/api/v1/orders/{$order->order_number}/dispute", [
            'reason' => 'not_received',
            'details' => 'The rider never arrived and the phone is switched off.',
        ])->assertOk();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/disputes/'.Dispute::firstOrFail()->id.'/resolve', ['resolution' => 'refund', 'note' => 'Seller could not prove delivery.'])
            ->assertOk();

        $this->assertStringContainsString('in your favour', $this->firstOfKind($buyer, 'dispute.resolved')->body);
        $this->assertStringContainsString('refunded to the buyer', $this->firstOfKind($seller, 'dispute.resolved')->body);
        $this->assertSame(0, $this->countKind($buyer, 'order.cancelled'));
        $this->assertSame(0, $this->countKind($seller, 'order.completed'));
    }

    // ---------------------------------------------------------------- payouts

    public function test_a_paid_payout_tells_the_seller_once(): void
    {
        Notification::fake();
        $this->transferStatusOnCreate = 'success';
        $this->fakePaystackTransfers();
        $seller = $this->makePayoutReadySeller(5_000_000);

        $this->actingAs($seller, 'sanctum')->postJson('/api/v1/seller/payouts', ['amount' => 1_000_000])->assertCreated();

        $this->assertSame(1, $this->countKind($seller, 'payout.paid'));
        $this->assertTrue($this->firstOfKind($seller, 'payout.paid')->email);
        $this->assertSame(0, $this->countKind($seller, 'payout.failed'));
        $this->assertStringContainsString('ending 6789', $this->firstOfKind($seller, 'payout.paid')->body);
    }

    public function test_a_payout_that_does_not_complete_tells_the_seller_the_money_is_back(): void
    {
        Notification::fake();
        $this->transferMode = 'refuse';
        $this->fakePaystackTransfers();
        $seller = $this->makePayoutReadySeller(5_000_000);

        $this->actingAs($seller, 'sanctum')->postJson('/api/v1/seller/payouts', ['amount' => 1_000_000])->assertCreated();

        $this->assertSame(1, $this->countKind($seller, 'payout.failed'));
        $this->assertSame(0, $this->countKind($seller, 'payout.paid'));
        $this->assertStringContainsString('back in your available balance', $this->firstOfKind($seller, 'payout.failed')->body);
        $this->assertStringNotContainsString('balance is not enough', $this->firstOfKind($seller, 'payout.failed')->body); // Paystack's wording stays internal
    }

    // ---------------------------------------------------------------- identity checks

    public function test_identity_documents_notify_the_admins_and_the_decision_notifies_the_seller(): void
    {
        Notification::fake();
        Storage::fake('local');
        config(['marketplace.kyc_disk' => 'local']);
        $admin = $this->makeAdmin();
        $approved = $this->makeSeller();
        $rejected = $this->makeSeller();

        foreach ([$approved, $rejected] as $seller) {
            $this->actingAs($seller, 'sanctum')->post('/api/v1/seller/kyc', [
                'legal_name' => 'Ada Obi',
                'id_type' => 'nin_slip',
                'id_photo' => UploadedFile::fake()->image('id.jpg', 600, 400),
            ], ['Accept' => 'application/json'])->assertCreated();
        }

        $this->assertSame(2, $this->countKind($admin, 'admin.kyc_submitted'));

        $first = KycSubmission::where('user_id', $approved->id)->firstOrFail();
        $second = KycSubmission::where('user_id', $rejected->id)->firstOrFail();

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/kyc/{$first->id}/approve")->assertOk();
        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/kyc/{$first->id}/approve")->assertUnprocessable(); // reviewed twice
        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/kyc/{$second->id}/reject", ['reason' => 'The photo is blurry.'])->assertOk();

        $this->assertSame(1, $this->countKind($approved, 'kyc.approved'));
        $this->assertTrue($this->firstOfKind($approved, 'kyc.approved')->email);
        $this->assertSame(0, $this->countKind($approved, 'kyc.rejected'));

        $this->assertSame(1, $this->countKind($rejected, 'kyc.rejected'));
        $this->assertStringContainsString('The photo is blurry.', $this->firstOfKind($rejected, 'kyc.rejected')->body);
        $this->assertSame(0, $this->countKind($rejected, 'kyc.approved'));
    }

    // ---------------------------------------------------------------- the inbox

    public function test_the_inbox_lists_counts_and_marks_notifications_read(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $user->notify(new UserNotification('order.paid', 'First', 'Body one', '/orders/ORD-1', ['order_id' => 1]));
        $user->notify(new UserNotification('order.shipped', 'Second', 'Body two'));
        $user->notify(new UserNotification('refund.processed', 'Third', 'Body three'));
        $other->notify(new UserNotification('order.paid', 'Not yours', 'Private'));

        $this->getJson('/api/v1/notifications')->assertUnauthorized();

        $list = $this->actingAs($user, 'sanctum')->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('unread_count', 3)
            ->assertJsonPath('data.0.is_read', false);

        $this->assertNotContains('Not yours', $list->json('data.*.title'));

        $first = collect($list->json('data'))->firstWhere('title', 'First');
        $this->assertSame('order.paid', $first['kind']);
        $this->assertSame('/orders/ORD-1', $first['link']);
        $this->assertSame(['order_id' => 1], $first['meta']);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/notifications/unread-count')
            ->assertOk()->assertJsonPath('data.unread_count', 3);

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/notifications/{$first['id']}/read")
            ->assertOk()->assertJsonPath('data.is_read', true);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/notifications?unread=1')
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('unread_count', 2);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/notifications/read-all')
            ->assertOk()->assertJsonPath('data.unread_count', 0);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/notifications?unread=1')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/notifications')
            ->assertOk()->assertJsonCount(3, 'data'); // read ones stay in the inbox
    }

    public function test_nobody_can_read_or_mark_someone_elses_notification(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $owner->notify(new UserNotification('order.paid', 'Private', 'Only for the owner'));
        $id = $owner->notifications()->firstOrFail()->id;

        $this->actingAs($stranger, 'sanctum')->postJson("/api/v1/notifications/{$id}/read")->assertNotFound();
        $this->actingAs($stranger, 'sanctum')->postJson('/api/v1/notifications/read-all')->assertOk();

        $this->assertNull($owner->notifications()->firstOrFail()->read_at); // the stranger's "read all" did not touch it
    }

    // ---------------------------------------------------------------- failure isolation

    public function test_a_broken_mail_server_never_breaks_an_order_action(): void
    {
        config(['mail.default' => 'does-not-exist']); // sending any email now throws
        $buyer = User::factory()->create();
        $seller = $this->makeSeller();
        $order = $this->makeEscrowedOrder($buyer, $seller);

        $this->actingAs($seller, 'sanctum')
            ->postJson("/api/v1/seller/orders/{$order->order_number}/ship", ['tracking_info' => 'GIG Logistics 123456'])
            ->assertOk()
            ->assertJsonPath('data.status', 'shipped');

        $this->assertSame(OrderStatus::Shipped, $order->fresh()->status);
    }

    public function test_a_broken_mail_server_never_breaks_a_payout(): void
    {
        config(['mail.default' => 'does-not-exist']);
        $this->transferStatusOnCreate = 'success';
        $this->fakePaystackTransfers();
        $seller = $this->makePayoutReadySeller(5_000_000);

        $this->actingAs($seller, 'sanctum')->postJson('/api/v1/seller/payouts', ['amount' => 1_000_000])
            ->assertCreated();

        $this->assertSame(4_000_000, $this->availableOf($seller)); // the money moved; only the email failed
    }
}