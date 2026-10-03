<?php

namespace App\Http\Resources;

use App\Services\CartService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\CartItem */
class CartItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $listing = $this->listing; // null if the listing was deleted
        $unitPrice = $listing?->price ?? 0;
        $lineTotal = $unitPrice * $this->quantity;

        return [
            'id' => $this->id,
            'listing_id' => $this->listing_id,
            'slug' => $listing?->slug,
            'title' => $listing?->title ?? 'Unavailable item',
            'image' => $listing?->images->first()?->url,
            'store_name' => $listing?->seller?->sellerProfile?->store_name,
            'store_slug' => $listing?->seller?->sellerProfile?->slug,
            'quantity' => $this->quantity,
            'unit_price' => $unitPrice,
            'unit_price_formatted' => Money::naira($unitPrice),
            'delivery_fee' => $listing?->delivery_fee ?? 0, // per listing; one fee per seller is charged (the highest)
            'line_total' => $lineTotal,
            'line_total_formatted' => Money::naira($lineTotal),
            'issue' => CartService::issueFor($listing, $this->quantity), // null = fine
        ];
    }
}
