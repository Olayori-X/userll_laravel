<?php

namespace Tests\Concerns;

use App\Enums\CheckoutStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Address;
use App\Models\Checkout;
use App\Models\Listing;
use App\Models\Order;
use App\Models\Payment;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\LedgerService;
use App\Support\Money;
use Illuminate\Support\Str;

trait MakesMarketplaceData
{
    protected function makeSeller(?string $store = null): User
    {
        $store ??= 'Store '.Str::random(6);
        $user = User::factory()->create();

        SellerProfile::create([
            'user_id' => $user->id,
            'store_name' => $store,
            'slug' => SellerProfile::uniqueSlug($store),
            'state' => 'Lagos',
            'city' => 'Ikeja',
        ]);

        return $user->fresh();
    }

    protected function makeAddress(User $user, array $overrides = []): Address
    {
        return Address::create($overrides + [
            'user_id' => $user->id,
            'label' => 'Home',
            'recipient_name' => 'Ada Obi',
            'phone' => '+2348012345678',
            'street' => '12 Allen Avenue',
            'city' => 'Ikeja',
            'state' => 'Lagos',
            'is_default' => true,
        ]);
    }

    /** An order that has already been paid (payments arrive in step 4, so tests build it directly). */
    protected function makePaidOrder(User $buyer, User $seller, int $subtotal = 10_000_000, array $overrides = []): Order
    {
        $checkout = Checkout::create([
            'buyer_id' => $buyer->id,
            'reference' => 'CHK-'.Str::upper(Str::random(12)),
            'total' => $subtotal,
            'status' => CheckoutStatus::Paid,
        ]);

        $order = Order::create($overrides + [
            'order_number' => 'ORD-'.Str::upper(Str::random(10)),
            'checkout_id' => $checkout->id,
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'status' => OrderStatus::Paid,
            'shipping_name' => 'Ada Obi',
            'shipping_phone' => '+2348012345678',
            'shipping_street' => '12 Allen Avenue',
            'shipping_city' => 'Ikeja',
            'shipping_state' => 'Lagos',
            'subtotal' => $subtotal,
            'commission_rate_bps' => 500,
            'commission' => Money::commission($subtotal, 500),
            'total' => $subtotal,
            'paid_at' => now(),
        ]);

        $order->items()->create([
            'title' => 'Test item',
            'unit_price' => $subtotal,
            'quantity' => 1,
            'line_total' => $subtotal,
        ]);

        return $order;
    }

    protected function makeAdmin(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => UserRole::Admin])->save();

        return $user->fresh();
    }

    /**
     * A paid order that is really in escrow: it has a paid Paystack payment and its money
     * sits in the ledger, exactly as after a confirmed payment. Optionally linked to a listing.
     */
    protected function makeEscrowedOrder(
        User $buyer,
        User $seller,
        int $subtotal = 10_000_000,
        array $overrides = [],
        ?Listing $listing = null,
        int $quantity = 1,
    ): Order {
        $order = $this->makePaidOrder($buyer, $seller, $subtotal, $overrides);

        if ($listing) {
            $order->items()->update(['listing_id' => $listing->id, 'quantity' => $quantity]);
        }

        Payment::create([
            'checkout_id' => $order->checkout_id,
            'paystack_reference' => 'PAY-'.Str::upper(Str::random(12)),
            'amount' => $order->total,
            'currency' => 'NGN',
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);

        app(LedgerService::class)->holdEscrow($order->fresh());

        return $order->fresh();
    }
}
