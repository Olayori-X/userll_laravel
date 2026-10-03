<?php

namespace App\Services;

use App\Enums\CheckoutStatus;
use App\Enums\ListingStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Checkout;
use App\Models\Listing;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    public const PAID = 'paid';
    public const ALREADY_PAID = 'already_paid';
    public const NEEDS_REFUND = 'needs_refund';
    public const NOT_SUCCESSFUL = 'not_successful';

    /** The only Paystack fields we ever store. No card or customer details. */
    public const SAFE_FIELDS = ['id', 'status', 'reference', 'transaction_reference', 'refund_reference', 'amount', 'currency', 'paid_at', 'channel', 'gateway_response'];

    public function __construct(private PaystackClient $paystack, private LedgerService $ledger)
    {
    }

    // ------------------------------------------------------------------ starting a payment

    /** @return array{authorization_url: string, access_code: string, reference: string} */
    public function initialize(Checkout $checkout, User $buyer): array
    {
        $this->assertStillAvailable($checkout);

        // One row per attempt, each with its own reference, so a buyer can retry after abandoning.
        $payment = Payment::create([
            'checkout_id' => $checkout->id,
            'paystack_reference' => 'PAY-'.Str::upper((string) Str::ulid()),
            'amount' => $checkout->total,
            'currency' => 'NGN',
            'status' => PaymentStatus::Pending,
        ]);

        try {
            $data = $this->paystack->initialize(
                $buyer->email,
                $checkout->total,
                $payment->paystack_reference,
                rtrim(config('marketplace.frontend_url'), '/').config('marketplace.paystack.callback_path').'?checkout='.$checkout->reference,
                ['checkout_reference' => $checkout->reference],
            );
        } catch (PaystackException $e) {
            $payment->update(['status' => PaymentStatus::Failed]);

            throw $e;
        }

        return [
            'authorization_url' => $data['authorization_url'],
            'access_code' => $data['access_code'],
            'reference' => $payment->paystack_reference,
        ];
    }

    /** Refuse to take money for a checkout that can no longer be fulfilled. */
    public function assertStillAvailable(Checkout $checkout): void
    {
        if ($checkout->status !== CheckoutStatus::Pending) {
            throw ValidationException::withMessages([
                'checkout' => 'This checkout is no longer active. Please start a new one from your cart.',
            ]);
        }

        $needed = $this->quantitiesNeeded($checkout->id);
        $listings = Listing::whereIn('id', $needed->keys())->get()->keyBy('id'); // soft-deleted ones are not found

        $problems = [];
        foreach ($needed as $listingId => $quantity) {
            $listing = $listings->get($listingId);

            if ($issue = CartService::issueFor($listing, $quantity)) {
                $problems[] = ($listing?->title ?? 'An item').': '.$issue;
            }
        }

        if ($problems) {
            throw ValidationException::withMessages(['checkout' => $problems]);
        }
    }

    // ------------------------------------------------------------------ confirming a payment

    /**
     * Ask Paystack what happened and act on the answer. Used by the webhook and by the
     * "verify" endpoint, so there is exactly one path to turn money into paid orders.
     */
    public function verifyWithGateway(Payment $payment): string
    {
        $data = $this->paystack->verify($payment->paystack_reference);

        if (($data['reference'] ?? null) !== $payment->paystack_reference) {
            Log::warning('Paystack verify returned a different reference', ['payment_id' => $payment->id]);

            return self::NOT_SUCCESSFUL;
        }

        return match ($data['status'] ?? null) {
            'success' => $this->confirm($payment, $data),
            'failed' => $this->markFailed($payment),
            default => self::NOT_SUCCESSFUL, // abandoned / still in progress: leave pending
        };
    }

    /**
     * Money has arrived (as reported by Paystack's verify call). Turn it into paid orders, all or nothing:
     * check the amount, reserve stock, mark orders paid, hold the money in escrow, clear the bought items
     * from the cart. Safe to call repeatedly: a second call changes nothing.
     */
    public function confirm(Payment $payment, array $gateway): string
    {
        $gateway = Arr::only($gateway, self::SAFE_FIELDS);

        return DB::transaction(function () use ($payment, $gateway) {
            // Lock order is always payment -> checkout -> listings (by id) -> wallets, so two
            // simultaneous confirmations cannot deadlock each other.
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->status === PaymentStatus::Paid) {
                return self::ALREADY_PAID;
            }
            if ($payment->status === PaymentStatus::NeedsRefund) {
                return self::NEEDS_REFUND;
            }

            $checkout = Checkout::whereKey($payment->checkout_id)->lockForUpdate()->firstOrFail();

            $amountMatches = (int) ($gateway['amount'] ?? -1) === $payment->amount
                && strtoupper((string) ($gateway['currency'] ?? '')) === $payment->currency
                && $payment->amount === $checkout->total;

            if (! $amountMatches) {
                return $this->flagForRefund($payment, $gateway, 'amount_mismatch');
            }

            // A payment for a checkout that is cancelled (late) or already paid (duplicate) cannot be fulfilled.
            if ($checkout->status !== CheckoutStatus::Pending) {
                return $this->flagForRefund($payment, $gateway, 'checkout_'.$checkout->status->value);
            }

            $orders = Order::where('checkout_id', $checkout->id)->orderBy('id')->with('items')->get();

            if ($orders->contains(fn (Order $order) => $order->status !== OrderStatus::PendingPayment)) {
                return $this->flagForRefund($payment, $gateway, 'orders_not_pending');
            }

            // ---- reserve stock (lock the listings while we check and reduce) ----
            $needed = $this->quantitiesNeeded($checkout->id);
            $hasOrphanItems = $orders->flatMap(fn (Order $order) => $order->items)->contains(fn ($item) => $item->listing_id === null);
            $listings = Listing::withTrashed()->whereIn('id', $needed->keys())->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            $unavailable = $hasOrphanItems
                || $needed->contains(fn (int $quantity, int $listingId) => ! $this->canReserve($listings->get($listingId), $quantity));

            if ($unavailable) {
                // Sold out (or removed) while the buyer was paying: cancel everything, refund the money.
                Order::where('checkout_id', $checkout->id)->update([
                    'status' => OrderStatus::Cancelled->value,
                    'cancelled_at' => now(),
                ]);
                $checkout->update(['status' => CheckoutStatus::Cancelled]);

                return $this->flagForRefund($payment, $gateway, 'out_of_stock');
            }

            foreach ($needed as $listingId => $quantity) {
                $listing = $listings->get($listingId);
                $listing->stock -= $quantity;
                $listing->syncStockStatus(); // stock 0 => sold_out
                $listing->save();
            }

            // ---- orders paid, money into escrow ----
            $now = now();
            foreach ($orders as $order) {
                $order->update([
                    'status' => OrderStatus::Paid,
                    'paid_at' => $now,
                    'ship_by_at' => $now->copy()->addHours((int) config('marketplace.ship_within_hours')),
                ]);

                $this->ledger->holdEscrow($order);
            }

            $checkout->update(['status' => CheckoutStatus::Paid]);

            $payment->update([
                'status' => PaymentStatus::Paid,
                'paid_at' => $now,
                'raw_payload' => $gateway,
            ]);

            // Remove only what was bought. Anything the buyer added while paying stays in the cart.
            CartItem::whereIn('cart_id', Cart::where('user_id', $checkout->buyer_id)->select('id'))
                ->whereIn('listing_id', $needed->keys())
                ->delete();

            return self::PAID;
        });
    }

    // ------------------------------------------------------------------ helpers

    private function markFailed(Payment $payment): string
    {
        Payment::whereKey($payment->id)
            ->where('status', PaymentStatus::Pending->value)
            ->update(['status' => PaymentStatus::Failed->value]);

        return self::NOT_SUCCESSFUL;
    }

    /** Money we hold but cannot turn into an order. Kept visible so it gets refunded (step 4b). */
    private function flagForRefund(Payment $payment, array $gateway, string $reason): string
    {
        $payment->update([
            'status' => PaymentStatus::NeedsRefund,
            'raw_payload' => $gateway + ['flag' => $reason],
        ]);

        Log::warning('Payment needs refund', [
            'payment_id' => $payment->id,
            'checkout_id' => $payment->checkout_id,
            'reason' => $reason,
        ]);

        return self::NEEDS_REFUND;
    }

    private function canReserve(?Listing $listing, int $quantity): bool
    {
        return $listing !== null
            && ! $listing->trashed()
            && $listing->status === ListingStatus::Active
            && $listing->stock >= $quantity;
    }

    /** @return Collection<int, int> listing_id => total quantity across all orders in the checkout */
    private function quantitiesNeeded(int $checkoutId): Collection
    {
        return OrderItem::query()
            ->whereIn('order_id', Order::where('checkout_id', $checkoutId)->select('id'))
            ->whereNotNull('listing_id')
            ->selectRaw('listing_id, SUM(quantity) as qty')
            ->groupBy('listing_id')
            ->pluck('qty', 'listing_id')
            ->map(fn ($quantity) => (int) $quantity);
    }
}
