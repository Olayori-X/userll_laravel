<?php

namespace App\Http\Resources;

use App\Services\CartService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Cart */
class CartResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $items = $this->items;
        $subtotal = (int) $items->sum(fn ($item) => ($item->listing?->price ?? 0) * $item->quantity);
        // One delivery fee per seller: the highest among that seller's items in the cart.
        $delivery = (int) $items
            ->groupBy(fn ($item) => (string) $item->listing?->seller_id)
            ->sum(fn ($lines) => CartService::deliveryFor($lines));

        return [
            'items' => CartItemResource::collection($items),
            'item_count' => (int) $items->sum('quantity'),
            'subtotal' => $subtotal,
            'subtotal_formatted' => Money::naira($subtotal),
            'delivery_total' => $delivery,
            'delivery_total_formatted' => Money::naira($delivery),
            'total' => $subtotal + $delivery,
            'total_formatted' => Money::naira($subtotal + $delivery),
            // False while the cart is empty or any line has an issue (sold out, not enough stock...).
            'can_checkout' => $items->isNotEmpty()
                && $items->every(fn ($item) => CartService::issueFor($item->listing, $item->quantity) === null),
        ];
    }
}
