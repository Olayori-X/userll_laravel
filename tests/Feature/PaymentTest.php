<?php

namespace Tests\Feature;

use App\Enums\CheckoutStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Address;
use App\Models\CartItem;
use App\Models\Checkout;
use App\Models\LedgerEntry;
use App\Models\Listing;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\MakesMarketplaceData;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use MakesMarketplaceData, RefreshDatabase;

    /** Per-reference overrides for what our fake Paystack reports. 'down' = server error. */
    private array $gateway = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['marketplace.paystack.secret_key' => 'sk_test_secret']);

        // A fake Paystack. No real network call is ever made in tests.
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, '/transaction/initialize')) {
                if (($this->gateway['init'] ?? null) === 'down') {
                    return Http::response(['status' => false, 'message' => 'Server error'], 500);
                }

                return Http::response([
                    'status' => true,
                    'message' => 'Authorization URL created',
                    'data' => [
                        'authorization_url' => 'https://checkout.paystack.com/abc123',
                        'access_code' => 'abc123',
                        'reference' => $request['reference'],
                    ],
                ]);
            }

            if (str_contains($url, '/transaction/verify/')) {
                $reference = basename(parse_url($url, PHP_URL_PATH));
                $override = $this->gateway[$reference] ?? [];

                if ($override === 'down') {
                    return Http::response(['status' => false, 'message' => 'Server error'], 500);
                }

                $amount = Payment::where('paystack_reference', $reference)->value('amount');

                return Http::response([
                    'status' => true,
                    'message' => 'Verification successful',
                    'data' => array_merge([
                        'id' => 987,
                        'reference' => $reference,
                        'status' => 'success',
                        'amount' => $amount,
                        'currency' => 'NGN',
                        'paid_at' => '2026-10-03T10:00:00.000Z',
                        'channel' => 'card',
                        'gateway_response' => 'Successful',
                        'authorization' => ['last4' => '4081', 'card_type' => 'visa'],
                    ], $override),
                ]);
            }

            return Http::response([], 404);
        });
    }

    // ---------------------------------------------------------------- helpers

    /** @param list<array{0: Listing, 1: int}> $lines */
    private function pendingCheckout(User $buyer, array $lines): Checkout
    {
        $address = Address::where('user_id', $buyer->id)->first() ?? $this->makeAddress($buyer);

        foreach ($lines as [$listing, $quantity]) {
            $this->actingAs($buyer, 'sanctum')
                ->postJson('/api/v1/cart/items', ['listing_id' => $listing->id, 'quantity' => $quantity])
                ->assertOk();
        }

        $reference = $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/v1/checkout', ['address_id' => $address->id])
            ->assertCreated()
            ->json('data.reference');

        return Checkout::where('reference', $reference)->firstOrFail();
    }

    private function startPayment(User $buyer, Checkout $checkout): Payment
    {
        $this->actingAs($buyer, 'sanctum')->postJson("/api/v1/checkouts/{$checkout->reference}/pay")->assertOk();

        return Payment::where('checkout_id', $checkout->id)->latest('id')->firstOrFail();
    }

    private function sendWebhook(Payment $payment, string $event = 'charge.success', ?string $signature = null)
    {
        $body = json_encode([
            'event' => $event,
            'data' => [
                'id' => 987,
                'reference' => $payment->paystack_reference,
                'status' => 'success',
                'amount' => $payment->amount,
                'currency' => 'NGN',
                'paid_at' => '2026-10-03T10:00:00.000Z',
                'channel' => 'card',
                'gateway_response' => 'Successful',
                'authorization' => ['last4' => '4081', 'card_type' => 'visa'],
            ],
        ]);

        $signature ??= hash_hmac('sha512', $body, 'sk_test_secret');

        return $this->call('POST', '/api/v1/webhooks/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => $signature,
        ], $body);
    }

    private function listing(User $seller, int $price = 10_000_000, int $stock = 5): Listing
    {
        return Listing::factory()->create(['seller_id' => $seller->id, 'price' => $price, 'stock' => $stock]);
    }

    // ---------------------------------------------------------------- starting a payment

    public function test_pay_starts_a_paystack_transaction_for_the_checkout_total(): void
    {
        $buyer = User::factory()->create();
        $checkout = $this->pendingCheckout($buyer, [[$this->listing($this->makeSeller()), 2]]); // ₦200,000

        $response = $this->actingAs($buyer, 'sanctum')->postJson("/api/v1/checkouts/{$checkout->reference}/pay")
            ->assertOk()
            ->assertJsonPath('data.authorization_url', 'https://checkout.paystack.com/abc123');

        $payment = Payment::firstOrFail();
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame(20_000_000, $payment->amount);
        $this->assertSame('NGN', $payment->currency);
        $this->assertSame($payment->paystack_reference, $response->json('data.reference'));

        Http::assertSent(fn ($request) => $request->url() === 'https://api.paystack.co/transaction/initialize'
            && $request['amount'] === 20_000_000
            && $request['email'] === $buyer->email
            && $request['reference'] === $payment->paystack_reference
            && str_contains($request['callback_url'], $checkout->reference)
            && $request->hasHeader('Authorization', 'Bearer sk_test_secret'));
    }

    public function test_cannot_pay_for_someone_elses_checkout(): void
    {
        $buyer = User::factory()->create();
        $checkout = $this->pendingCheckout($buyer, [[$this->listing($this->makeSeller()), 1]]);

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson("/api/v1/checkouts/{$checkout->reference}/pay")->assertNotFound();
    }

    public function test_cannot_pay_for_a_cancelled_checkout(): void
    {
        $buyer = User::factory()->create();
        $listing = $this->listing($this->makeSeller());
        $old = $this->pendingCheckout($buyer, [[$listing, 1]]);

        // Starting another checkout cancels the first one.
        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/v1/checkout', ['address_id' => Address::where('user_id', $buyer->id)->value('id')])
            ->assertCreated();

        $this->actingAs($buyer, 'sanctum')->postJson("/api/v1/checkouts/{$old->reference}/pay")
            ->assertUnprocessable()->assertJsonValidationErrors('checkout');
        $this->assertSame(0, Payment::count());
    }

    public function test_cannot_pay_when_an_item_sold_out_after_checkout(): void
    {
        $buyer = User::factory()->create();
        $listing = $this->listing($this->makeSeller(), stock: 3);
        $checkout = $this->pendingCheckout($buyer, [[$listing, 3]]);

        $listing->update(['stock' => 1]);

        $this->actingAs($buyer, 'sanctum')->postJson("/api/v1/checkouts/{$checkout->reference}/pay")
            ->assertUnprocessable()->assertJsonValidationErrors('checkout');

        $this->assertSame(0, Payment::count());
        Http::assertNothingSent();
    }

    public function test_gateway_failure_on_start_gives_502_and_marks_the_attempt_failed(): void
    {
        $buyer = User::factory()->create();
        $checkout = $this->pendingCheckout($buyer, [[$this->listing($this->makeSeller()), 1]]);
        $this->gateway['init'] = 'down';

        $this->actingAs($buyer, 'sanctum')->postJson("/api/v1/checkouts/{$checkout->reference}/pay")
            ->assertStatus(502);

        $this->assertSame(PaymentStatus::Failed, Payment::firstOrFail()->status);
    }

    // ---------------------------------------------------------------- confirming (webhook)

    public function test_webhook_turns_payment_into_paid_orders_with_escrow_and_reserved_stock(): void
    {
        $buyer = User::factory()->create();
        $seller = $this->makeSeller();
        $listing = $this->listing($seller, price: 10_000_000, stock: 5);
        $checkout = $this->pendingCheckout($buyer, [[$listing, 2]]); // ₦200,000, commission 5% = ₦10,000
        $payment = $this->startPayment($buyer, $checkout);

        $this->sendWebhook($payment)->assertOk();

        $this->assertSame(CheckoutStatus::Paid, $checkout->fresh()->status);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertNotNull($payment->fresh()->paid_at);

        $order = Order::firstOrFail();
        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertNotNull($order->paid_at);
        $this->assertNotNull($order->ship_by_at);

        $this->assertSame(3, $listing->fresh()->stock);   // reserved: 5 - 2
        $this->assertSame(0, CartItem::count());           // bought items leave the cart

        // Escrow: seller share + commission = order total, nothing available yet.
        $sellerWallet = Wallet::where('user_id', $seller->id)->firstOrFail();
        $platformWallet = Wallet::where('type', 'platform')->firstOrFail();
        $this->assertSame(19_000_000, $sellerWallet->pending_balance);
        $this->assertSame(0, $sellerWallet->available_balance);
        $this->assertSame(1_000_000, $platformWallet->pending_balance);
        $this->assertSame($order->total, $sellerWallet->pending_balance + $platformWallet->pending_balance);
        $this->assertSame(2, LedgerEntry::count());
    }

    public function test_only_safe_fields_are_stored_no_card_details(): void
    {
        $buyer = User::factory()->create();
        $payment = $this->startPayment($buyer, $this->pendingCheckout($buyer, [[$this->listing($this->makeSeller()), 1]]));

        $this->sendWebhook($payment)->assertOk();

        $this->assertStringNotContainsString('4081', json_encode(WebhookEvent::firstOrFail()->payload));
        $this->assertStringNotContainsString('4081', json_encode($payment->fresh()->raw_payload));
        $this->assertNotNull(WebhookEvent::firstOrFail()->processed_at);
    }

    public function test_replaying_the_webhook_changes_nothing(): void
    {
        $buyer = User::factory()->create();
        $listing = $this->listing($this->makeSeller(), stock: 5);
        $payment = $this->startPayment($buyer, $this->pendingCheckout($buyer, [[$listing, 2]]));

        $this->sendWebhook($payment)->assertOk();
        $this->sendWebhook($payment)->assertOk();
        $this->sendWebhook($payment)->assertOk();

        $this->assertSame(3, $listing->fresh()->stock); // reduced once, not three times
        $this->assertSame(2, LedgerEntry::count());
        $this->assertSame(1, WebhookEvent::count());
    }

    public function test_bad_signature_is_rejected_and_nothing_changes(): void
    {
        $buyer = User::factory()->create();
        $listing = $this->listing($this->makeSeller(), stock: 5);
        $payment = $this->startPayment($buyer, $this->pendingCheckout($buyer, [[$listing, 1]]));

        $this->sendWebhook($payment, 'charge.success', 'not-the-right-signature')->assertUnauthorized();
        $this->sendWebhook($payment, 'charge.success', '')->assertUnauthorized();

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(5, $listing->fresh()->stock);
        $this->assertSame(0, WebhookEvent::count());
    }

    public function test_webhook_with_unknown_reference_or_other_event_is_acknowledged_and_ignored(): void
    {
        $buyer = User::factory()->create();
        $payment = $this->startPayment($buyer, $this->pendingCheckout($buyer, [[$this->listing($this->makeSeller()), 1]]));

        $unknown = new Payment(['paystack_reference' => 'PAY-DOES-NOT-EXIST', 'amount' => 100]);
        $this->sendWebhook($unknown)->assertOk();
        $this->sendWebhook($payment, 'transfer.success')->assertOk();

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(OrderStatus::PendingPayment, Order::firstOrFail()->status);
    }

    public function test_the_amount_comes_from_paystack_not_from_the_webhook_body(): void
    {
        $buyer = User::factory()->create();
        $listing = $this->listing($this->makeSeller(), stock: 5);
        $checkout = $this->pendingCheckout($buyer, [[$listing, 1]]);
        $payment = $this->startPayment($buyer, $checkout);

        // The webhook body claims the full amount, but Paystack says only ₦10 was paid.
        $this->gateway[$payment->paystack_reference] = ['amount' => 1_000];

        $this->sendWebhook($payment)->assertOk();

        $this->assertSame(PaymentStatus::NeedsRefund, $payment->fresh()->status);
        $this->assertSame(CheckoutStatus::Pending, $checkout->fresh()->status);
        $this->assertSame(OrderStatus::PendingPayment, Order::firstOrFail()->status);
        $this->assertSame(5, $listing->fresh()->stock);
        $this->assertSame(0, LedgerEntry::count());
    }

    public function test_a_failed_payment_is_marked_failed(): void
    {
        $buyer = User::factory()->create();
        $checkout = $this->pendingCheckout($buyer, [[$this->listing($this->makeSeller()), 1]]);
        $payment = $this->startPayment($buyer, $checkout);
        $this->gateway[$payment->paystack_reference] = ['status' => 'failed'];

        $this->sendWebhook($payment)->assertOk();

        $this->assertSame(PaymentStatus::Failed, $payment->fresh()->status);
        $this->assertSame(CheckoutStatus::Pending, $checkout->fresh()->status); // buyer can try again
        $this->assertSame(0, LedgerEntry::count());
    }

    public function test_item_sold_out_while_paying_cancels_the_checkout_and_flags_a_refund(): void
    {
        $buyer = User::factory()->create();
        $listing = $this->listing($this->makeSeller(), stock: 2);
        $checkout = $this->pendingCheckout($buyer, [[$listing, 2]]);
        $payment = $this->startPayment($buyer, $checkout);

        $listing->update(['stock' => 1]); // someone else bought one first

        $this->sendWebhook($payment)->assertOk();

        $this->assertSame(PaymentStatus::NeedsRefund, $payment->fresh()->status);
        $this->assertSame('out_of_stock', $payment->fresh()->raw_payload['flag']);
        $this->assertSame(CheckoutStatus::Cancelled, $checkout->fresh()->status);
        $this->assertSame(OrderStatus::Cancelled, Order::firstOrFail()->status);
        $this->assertSame(1, $listing->fresh()->stock);
        $this->assertSame(0, LedgerEntry::count());
    }

    public function test_late_payment_for_a_cancelled_checkout_is_flagged_for_refund(): void
    {
        $buyer = User::factory()->create();
        $listing = $this->listing($this->makeSeller(), stock: 5);
        $old = $this->pendingCheckout($buyer, [[$listing, 1]]);
        $payment = $this->startPayment($buyer, $old);

        // Buyer starts a new checkout, which cancels the old one, but had already paid the old one.
        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/v1/checkout', ['address_id' => Address::where('user_id', $buyer->id)->value('id')])
            ->assertCreated();

        $this->sendWebhook($payment)->assertOk();

        $this->assertSame(PaymentStatus::NeedsRefund, $payment->fresh()->status);
        $this->assertSame('checkout_cancelled', $payment->fresh()->raw_payload['flag']);
        $this->assertSame(5, $listing->fresh()->stock);
        $this->assertSame(0, LedgerEntry::count());
        $this->assertSame(1, Checkout::where('status', CheckoutStatus::Pending->value)->count()); // the new one is untouched
    }

    public function test_paying_twice_for_one_checkout_only_fulfils_it_once(): void
    {
        $buyer = User::factory()->create();
        $listing = $this->listing($this->makeSeller(), stock: 5);
        $checkout = $this->pendingCheckout($buyer, [[$listing, 2]]);
        $first = $this->startPayment($buyer, $checkout);
        $second = $this->startPayment($buyer, $checkout); // second tab

        $this->sendWebhook($first)->assertOk();
        $this->sendWebhook($second)->assertOk();

        $this->assertSame(PaymentStatus::Paid, $first->fresh()->status);
        $this->assertSame(PaymentStatus::NeedsRefund, $second->fresh()->status);
        $this->assertSame(3, $listing->fresh()->stock);
        $this->assertSame(2, LedgerEntry::count());
    }

    public function test_one_payment_for_two_sellers_splits_escrow_per_order(): void
    {
        $buyer = User::factory()->create();
        $sellerA = $this->makeSeller();
        $sellerB = $this->makeSeller();
        $a = $this->listing($sellerA, price: 10_000_000);  // ₦100,000, commission ₦5,000
        $b = $this->listing($sellerB, price: 2_000_000);   // ₦20,000, commission ₦1,000
        $checkout = $this->pendingCheckout($buyer, [[$a, 1], [$b, 1]]);
        $payment = $this->startPayment($buyer, $checkout);

        $this->sendWebhook($payment)->assertOk();

        $this->assertSame(2, Order::where('status', OrderStatus::Paid->value)->count());
        $this->assertSame(9_500_000, Wallet::where('user_id', $sellerA->id)->value('pending_balance'));
        $this->assertSame(1_900_000, Wallet::where('user_id', $sellerB->id)->value('pending_balance'));
        $this->assertSame(600_000, Wallet::where('type', 'platform')->value('pending_balance'));

        // Money in escrow equals money received.
        $held = Wallet::sum('pending_balance');
        $this->assertSame($payment->amount, (int) $held);
    }

    // ---------------------------------------------------------------- confirming (verify endpoint)

    public function test_verify_endpoint_confirms_a_payment_when_the_webhook_has_not_arrived(): void
    {
        $buyer = User::factory()->create();
        $checkout = $this->pendingCheckout($buyer, [[$this->listing($this->makeSeller()), 1]]);
        $this->startPayment($buyer, $checkout);

        $this->actingAs($buyer, 'sanctum')->postJson("/api/v1/checkouts/{$checkout->reference}/verify")
            ->assertOk()
            ->assertJsonPath('data.status', 'paid');

        $this->assertSame(OrderStatus::Paid, Order::firstOrFail()->status);
    }

    public function test_verify_endpoint_leaves_an_unpaid_checkout_pending(): void
    {
        $buyer = User::factory()->create();
        $checkout = $this->pendingCheckout($buyer, [[$this->listing($this->makeSeller()), 1]]);
        $payment = $this->startPayment($buyer, $checkout);
        $this->gateway[$payment->paystack_reference] = ['status' => 'abandoned'];

        $this->actingAs($buyer, 'sanctum')->postJson("/api/v1/checkouts/{$checkout->reference}/verify")
            ->assertOk()->assertJsonPath('data.status', 'pending');

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }

    public function test_verify_endpoint_gives_502_when_paystack_is_down(): void
    {
        $buyer = User::factory()->create();
        $checkout = $this->pendingCheckout($buyer, [[$this->listing($this->makeSeller()), 1]]);
        $payment = $this->startPayment($buyer, $checkout);
        $this->gateway[$payment->paystack_reference] = 'down';

        $this->actingAs($buyer, 'sanctum')->postJson("/api/v1/checkouts/{$checkout->reference}/verify")
            ->assertStatus(502);

        $this->assertSame(OrderStatus::PendingPayment, Order::firstOrFail()->status);
    }

    public function test_cannot_verify_someone_elses_checkout(): void
    {
        $buyer = User::factory()->create();
        $checkout = $this->pendingCheckout($buyer, [[$this->listing($this->makeSeller()), 1]]);

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson("/api/v1/checkouts/{$checkout->reference}/verify")->assertNotFound();
    }
}
