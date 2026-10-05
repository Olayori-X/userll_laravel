<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\Checkout;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\RefundService;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\FakesPaystackRefunds;
use Tests\Concerns\MakesMarketplaceData;
use Tests\TestCase;

class RefundTest extends TestCase
{
    use FakesPaystackRefunds, MakesMarketplaceData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePaystackRefunds();
    }

    private function needsRefundPayment(User $buyer, int $amount = 5_000_000): Payment
    {
        $checkout = Checkout::create([
            'buyer_id' => $buyer->id,
            'reference' => 'CHK-TEST'.random_int(1000, 9999),
            'total' => $amount,
            'status' => 'cancelled',
        ]);

        return Payment::create([
            'checkout_id' => $checkout->id,
            'paystack_reference' => 'PAY-NEEDS'.random_int(1000, 9999),
            'amount' => $amount,
            'currency' => 'NGN',
            'status' => PaymentStatus::NeedsRefund,
            'raw_payload' => ['flag' => 'out_of_stock'],
        ]);
    }

    /** A refund created the normal way: buyer cancels an unshipped order. */
    private function cancelledOrderRefund(User $buyer, User $seller): Refund
    {
        $order = $this->makeEscrowedOrder($buyer, $seller, 10_000_000);
        $this->actingAs($buyer, 'sanctum')->postJson("/api/v1/orders/{$order->order_number}/cancel")->assertOk();

        return $order->refunds()->with('payment')->firstOrFail();
    }

        private function sendRefundEvent(string $event, string $paymentReference, int $amount, ?string $refundReference = null)
    {
        $body = json_encode([
            'event' => $event,
            'data' => [
                'status' => 'processed',
                'transaction_reference' => $paymentReference,
                'refund_reference' => $refundReference,
                'amount' => (string) $amount, // Paystack sends the amount as text here
                'currency' => 'NGN',
                'processor' => 'instant-transfer',
                'customer' => ['first_name' => 'Drew', 'last_name' => 'Berry', 'email' => 'drew@example.com'],
            ],
        ]);

        return $this->call('POST', '/api/v1/webhooks/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, 'sk_test_secret'),
        ], $body);
    }

    // ---------------------------------------------------------------- admin: payments that need refunding

    public function test_admin_sees_and_refunds_a_payment_that_needs_refunding(): void
    {
        $admin = $this->makeAdmin();
        $buyer = User::factory()->create();
        $payment = $this->needsRefundPayment($buyer);

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/payments')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.flag', 'out_of_stock');

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/payments/{$payment->id}/refund")
            ->assertCreated()
            ->assertJsonPath('data.amount', 5_000_000)
            ->assertJsonPath('data.status', 'pending');

        $this->assertSame(PaymentStatus::Refunded, $payment->fresh()->status);
        $this->assertNull(Refund::firstOrFail()->order_id);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/refund')
            && $request['transaction'] === $payment->paystack_reference
            && $request['amount'] === 5_000_000);

        // Nothing left to refund, and it cannot be done twice.
        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/payments/{$payment->id}/refund")->assertUnprocessable();
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/payments')->assertJsonCount(0, 'data');
    }

    public function test_only_admins_can_use_the_admin_endpoints(): void
    {
        $user = User::factory()->create();
        $seller = $this->makeSeller();
        $payment = $this->needsRefundPayment($user);

        $this->getJson('/api/v1/admin/payments')->assertUnauthorized(); // not logged in at all

        foreach ([$user, $seller] as $nonAdmin) {
            $this->actingAs($nonAdmin, 'sanctum')->getJson('/api/v1/admin/payments')->assertForbidden();
            $this->actingAs($nonAdmin, 'sanctum')->postJson("/api/v1/admin/payments/{$payment->id}/refund")->assertForbidden();
            $this->actingAs($nonAdmin, 'sanctum')->getJson('/api/v1/admin/refunds')->assertForbidden();
            $this->actingAs($nonAdmin, 'sanctum')->getJson('/api/v1/admin/disputes')->assertForbidden();
        }

        $this->assertSame(0, Refund::count());
    }

    public function test_a_paid_payment_cannot_be_refunded_through_the_admin_shortcut(): void
    {
        $admin = $this->makeAdmin();
        $order = $this->makeEscrowedOrder(User::factory()->create(), $this->makeSeller());
        $payment = Payment::where('checkout_id', $order->checkout_id)->firstOrFail();

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/payments/{$payment->id}/refund")
            ->assertUnprocessable()->assertJsonValidationErrors('payment');
    }

    // ---------------------------------------------------------------- failed refunds and retry

    public function test_admin_can_retry_a_failed_refund_but_not_a_healthy_one(): void
    {
        $admin = $this->makeAdmin();
        $buyer = User::factory()->create();
        $seller = $this->makeSeller();
        $this->refundGateway = 'down';
        $failed = $this->cancelledOrderRefund($buyer, $seller);
        $this->assertSame(RefundStatus::Failed, $failed->status);

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/refunds?status=failed')
            ->assertOk()->assertJsonCount(1, 'data');

        $this->refundGateway = 'ok'; // Paystack is back
        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/refunds/{$failed->id}/retry")
            ->assertOk()->assertJsonPath('data.status', 'pending');

        $this->assertSame('3018284', $failed->fresh()->paystack_refund_id);
        $this->assertNull($failed->fresh()->failure_note);

        // It is healthy now: retrying again would risk a double refund, so it is refused.
        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/refunds/{$failed->id}/retry")->assertUnprocessable();
    }

    public function test_an_order_that_was_already_paid_out_cannot_be_refunded(): void
    {
        $order = $this->makeEscrowedOrder(User::factory()->create(), $this->makeSeller(), 10_000_000, [
            'status' => OrderStatus::Completed,
            'released_at' => now(),
        ]);

        $this->expectException(ValidationException::class);
        app(RefundService::class)->refundOrder($order, 'test', restock: true);
    }

    // ---------------------------------------------------------------- Paystack refund notifications

    public function test_refund_webhooks_update_the_refund_status(): void
    {
        $refund = $this->cancelledOrderRefund(User::factory()->create(), $this->makeSeller());
        $reference = $refund->payment->paystack_reference;

        $this->sendRefundEvent('refund.processing', $reference, 10_000_000)->assertOk();
        $this->assertSame(RefundStatus::Processing, $refund->fresh()->status);

        $this->sendRefundEvent('refund.processed', $reference, 10_000_000)->assertOk();
        $this->assertSame(RefundStatus::Processed, $refund->fresh()->status);
    }

    public function test_a_failed_or_stuck_refund_is_flagged_for_the_admin(): void
    {
        $refund = $this->cancelledOrderRefund(User::factory()->create(), $this->makeSeller());
        $reference = $refund->payment->paystack_reference;

        $this->sendRefundEvent('refund.needs-attention', $reference, 10_000_000)->assertOk();
        $this->assertSame(RefundStatus::NeedsAttention, $refund->fresh()->status);
        $this->assertNotNull($refund->fresh()->failure_note);

        $this->sendRefundEvent('refund.failed', $reference, 10_000_000)->assertOk();
        $this->assertSame(RefundStatus::Failed, $refund->fresh()->status);
    }

    public function test_refund_webhook_for_something_unknown_is_acknowledged_and_ignored(): void
    {
        $refund = $this->cancelledOrderRefund(User::factory()->create(), $this->makeSeller());

        $this->sendRefundEvent('refund.processed', 'PAY-NO-SUCH-PAYMENT', 10_000_000)->assertOk();
        $this->sendRefundEvent('refund.processed', $refund->payment->paystack_reference, 999)->assertOk(); // wrong amount

        $this->assertSame(RefundStatus::Pending, $refund->fresh()->status);
    }

    public function test_refund_webhooks_are_deduplicated_and_store_no_customer_details(): void
    {
        $refund = $this->cancelledOrderRefund(User::factory()->create(), $this->makeSeller());
        $reference = $refund->payment->paystack_reference;

        $this->sendRefundEvent('refund.processed', $reference, 10_000_000)->assertOk();
        $this->sendRefundEvent('refund.processed', $reference, 10_000_000)->assertOk();

        $this->assertSame(1, WebhookEvent::where('event_type', 'refund.processed')->count());
        $this->assertStringNotContainsString('drew@example.com', json_encode(WebhookEvent::firstOrFail()->payload));
    }

        public function test_two_equal_refunds_on_one_payment_each_get_their_own_events(): void
    {
        $buyer = User::factory()->create();
        $seller = $this->makeSeller();

        // Two orders from one checkout and one payment, with the same total.
        $first = $this->makeEscrowedOrder($buyer, $seller, 10_000_000);
        $second = $this->makePaidOrder($buyer, $seller, 10_000_000, ['checkout_id' => $first->checkout_id]);
        app(LedgerService::class)->holdEscrow($second);

        Payment::where('checkout_id', $first->checkout_id)->update(['amount' => 20_000_000]);
        Checkout::whereKey($first->checkout_id)->update(['total' => 20_000_000]);

        $refunds = app(RefundService::class);
        $refundA = $refunds->refundOrder($first, 'test', restock: false);
        $refundB = $refunds->refundOrder($second, 'test', restock: false);
        $reference = Payment::where('checkout_id', $first->checkout_id)->value('paystack_reference');

        // Each refund's first event binds it to Paystack's own refund reference.
        $this->sendRefundEvent('refund.processing', $reference, 10_000_000, 'RF-ONE')->assertOk();
        $this->sendRefundEvent('refund.processing', $reference, 10_000_000, 'RF-TWO')->assertOk();

        $this->assertSame(2, WebhookEvent::where('event_type', 'refund.processing')->count()); // not collapsed into one
        $this->assertSame(RefundStatus::Processing, $refundA->fresh()->status);
        $this->assertSame(RefundStatus::Processing, $refundB->fresh()->status);
        $this->assertSame('RF-ONE', $refundA->fresh()->paystack_refund_reference);
        $this->assertSame('RF-TWO', $refundB->fresh()->paystack_refund_reference);

        // Later events find the right refund by reference, whatever the order they arrive in.
        $this->sendRefundEvent('refund.processed', $reference, 10_000_000, 'RF-TWO')->assertOk();
        $this->sendRefundEvent('refund.failed', $reference, 10_000_000, 'RF-ONE')->assertOk();

        $this->assertSame(RefundStatus::Failed, $refundA->fresh()->status);
        $this->assertSame(RefundStatus::Processed, $refundB->fresh()->status);
    }

    public function test_a_late_event_never_moves_a_finished_refund_backwards(): void
    {
        $refund = $this->cancelledOrderRefund(User::factory()->create(), $this->makeSeller());
        $reference = $refund->payment->paystack_reference;

        $this->sendRefundEvent('refund.processed', $reference, 10_000_000, 'RF-LATE')->assertOk();
        $this->sendRefundEvent('refund.processing', $reference, 10_000_000, 'RF-LATE')->assertOk(); // arrives late

        $this->assertSame(RefundStatus::Processed, $refund->fresh()->status);
    }
}
