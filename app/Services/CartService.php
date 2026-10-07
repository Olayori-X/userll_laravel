<?php

namespace App\Services;

use App\Enums\ListingStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class CartService
{
    /** Cart with everything the API response needs, created on first use. */
    public function load(User $user): Cart
    {
        $cart = $user->cart()->firstOrCreate();

        // A cart that was just created on first use is still a normal read: answer 200, not 201.
        $cart->wasRecentlyCreated = false;

        return $cart->load([
            'items.listing.images',
            'items.listing.seller.sellerProfile',
        ]);
    }

    /** Why this line cannot be bought right now, or null if it is fine. Used by the cart view and by checkout. */
    public static function issueFor(?Listing $listing, int $quantity): ?string
    {
        if (
            ! $listing
            || $listing->status !== ListingStatus::Active
            || $listing->stock < 1
            || ! $listing->seller?->isActive() // a suspended seller's items cannot be bought
        ) {
            return 'No longer available';
        }

        if ($quantity > $listing->stock) {
            return "Only {$listing->stock} left";
        }

        return null;
    }

    /** Delivery fee for one seller's lines: the highest fee among them (a seller ships the parcel together). */
    public static function deliveryFor(Collection $lines): int
    {
        return (int) $lines->map(fn ($line) => $line->listing?->delivery_fee ?? 0)->max();
    }

    /** Add a listing, or increase the quantity if it is already in the cart. */
    public function add(User $user, int $listingId, int $quantity): void
    {
        $listing = Listing::find($listingId); // soft-deleted listings are not found

        if (self::issueFor($listing, 1) !== null) {
            throw ValidationException::withMessages(['listing_id' => 'This item is no longer available.']);
        }

        if ($listing->seller_id === $user->id) {
            throw ValidationException::withMessages(['listing_id' => 'You cannot buy your own listing.']);
        }

        $cart = $user->cart()->firstOrCreate();
        $item = $cart->items()->where('listing_id', $listing->id)->first();
        $newQuantity = ($item?->quantity ?? 0) + $quantity;

        if ($newQuantity > $listing->stock) {
            throw ValidationException::withMessages(['quantity' => "Only {$listing->stock} available."]);
        }

        if ($item) {
            $item->update(['quantity' => $newQuantity]);
        } else {
            $cart->items()->create(['listing_id' => $listing->id, 'quantity' => $newQuantity]);
        }
    }

    public function setQuantity(CartItem $item, int $quantity): void
    {
        $issue = self::issueFor($item->listing, $quantity);

        if ($issue !== null) {
            throw ValidationException::withMessages(['quantity' => $issue.'.']);
        }

        $item->update(['quantity' => $quantity]);
    }

    /** Only items in this user's own cart can be found (anyone else's id gives a 404). */
    public function findItem(User $user, int $itemId): CartItem
    {
        $cart = $user->cart()->first();
        abort_if($cart === null, 404);

        return $cart->items()->with('listing')->findOrFail($itemId);
    }
}
