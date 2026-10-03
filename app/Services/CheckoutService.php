<?php

namespace App\Services;

use App\Enums\CheckoutStatus;
use App\Enums\OrderStatus;
use App\Models\Address;
use App\Models\Checkout;
use App\Models\Order;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CheckoutService
{
    /**
     * Turn the buyer's cart into one checkout (one payment) with one order per seller.
     *
     * Stock is NOT reduced here. It is reserved when the payment is confirmed (PaymentService),
     * so abandoned checkouts never lock up a seller's stock. The cart is also kept until payment succeeds.
     */
    public function create(User $buyer, Address $address): Checkout
    {
        $items = $buyer->cart()->first()?->items()->with('listing')->get() ?? collect();

        if ($items->isEmpty()) {
            throw ValidationException::withMessages(['cart' => 'Your cart is empty.']);
        }

        $problems = [];
        foreach ($items as $item) {
            $listing = $item->listing;
            $name = $listing?->title ?? 'An item in your cart';

            if ($listing && $listing->seller_id === $buyer->id) {
                $problems[] = "{$name}: you cannot buy your own listing";
            } elseif ($issue = CartService::issueFor($listing, $item->quantity)) {
                $problems[] = "{$name}: {$issue}";
            }
        }
        if ($problems) {
            throw ValidationException::withMessages(['cart' => $problems]);
        }

        $rateBps = (int) config('marketplace.commission_bps');

        // One order per seller. All maths in whole kobo.
        $plans = $items->groupBy(fn ($item) => $item->listing->seller_id)->map(function ($lines) use ($rateBps) {
            $subtotal = (int) $lines->sum(fn ($line) => $line->listing->price * $line->quantity);
            $delivery = CartService::deliveryFor($lines); // highest fee among this seller's items

            return [
                'lines' => $lines,
                'subtotal' => $subtotal,
                'delivery' => $delivery,
                'commission' => Money::commission($subtotal, $rateBps), // on the items only, not on delivery
                'total' => $subtotal + $delivery,
            ];
        });

        $grandTotal = (int) $plans->sum('total');

        return DB::transaction(function () use ($buyer, $address, $plans, $grandTotal, $rateBps) {
            $this->supersedePendingCheckouts($buyer);

            $checkout = Checkout::create([
                'buyer_id' => $buyer->id,
                'reference' => 'CHK-'.Str::upper((string) Str::ulid()),
                'total' => $grandTotal,
                'status' => CheckoutStatus::Pending,
            ]);

            foreach ($plans as $sellerId => $plan) {
                $order = Order::create([
                    'order_number' => 'ORD-'.Str::upper(Str::random(10)),
                    'checkout_id' => $checkout->id,
                    'buyer_id' => $buyer->id,
                    'seller_id' => $sellerId,
                    'status' => OrderStatus::PendingPayment,
                    // The address is copied in, so later edits never change this order.
                    'shipping_name' => $address->recipient_name,
                    'shipping_phone' => $address->phone,
                    'shipping_street' => $address->street,
                    'shipping_city' => $address->city,
                    'shipping_state' => $address->state,
                    'subtotal' => $plan['subtotal'],
                    'delivery_fee' => $plan['delivery'],
                    // Rate is stored on the order, so changing it later never alters past orders.
                    'commission_rate_bps' => $rateBps,
                    'commission' => $plan['commission'],
                    'total' => $plan['total'],
                ]);

                foreach ($plan['lines'] as $line) {
                    $order->items()->create([
                        'listing_id' => $line->listing_id,
                        // Title and price are copied in, so later listing edits never change this order.
                        'title' => $line->listing->title,
                        'unit_price' => $line->listing->price,
                        'quantity' => $line->quantity,
                        'line_total' => $line->listing->price * $line->quantity,
                    ]);
                }
            }

            return $checkout;
        });
    }

    /** Scheduled: close checkouts that stayed unpaid too long and cancel their orders. Returns how many. */
    public function expireStale(): int
    {
        $cutoff = now()->subMinutes((int) config('marketplace.checkout_expiry_minutes'));
        $expired = 0;

        Checkout::where('status', CheckoutStatus::Pending->value)
            ->where('created_at', '<=', $cutoff)
            ->chunkById(100, function ($checkouts) use (&$expired) {
                foreach ($checkouts as $checkout) {
                    DB::transaction(function () use ($checkout, &$expired) {
                        // Re-read under a lock: a payment may have been confirmed a moment ago.
                        $locked = Checkout::whereKey($checkout->id)->lockForUpdate()->first();

                        if (! $locked || $locked->status !== CheckoutStatus::Pending) {
                            return;
                        }

                        Order::where('checkout_id', $locked->id)
                            ->where('status', OrderStatus::PendingPayment->value)
                            ->update(['status' => OrderStatus::Cancelled->value, 'cancelled_at' => now()]);

                        $locked->update(['status' => CheckoutStatus::Expired]);
                        $expired++;
                    });
                }
            });

        return $expired;
    }

    /**
     * A buyer only ever has one open checkout: starting a new one cancels the old unpaid one.
     * If money for a cancelled checkout still arrives, PaymentService flags it "needs_refund".
     */
    private function supersedePendingCheckouts(User $buyer): void
    {
        $pending = Checkout::where('buyer_id', $buyer->id)
            ->where('status', CheckoutStatus::Pending->value)
            ->get();

        foreach ($pending as $old) {
            Order::where('checkout_id', $old->id)
                ->where('status', OrderStatus::PendingPayment->value)
                ->update(['status' => OrderStatus::Cancelled->value, 'cancelled_at' => now()]);

            $old->update(['status' => CheckoutStatus::Cancelled]);
        }
    }
}
