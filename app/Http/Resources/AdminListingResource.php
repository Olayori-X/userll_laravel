<?php

namespace App\Http\Resources;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Listing */
class AdminListingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'description' => $this->description,
            'price' => $this->price,
            'price_short' => Money::short($this->price),
            'delivery_fee' => $this->delivery_fee,
            'condition' => $this->condition->value,
            'stock' => $this->stock,
            'status' => $this->status->value,
            'location' => ['city' => $this->city, 'state' => $this->state],
            'category' => $this->whenLoaded('category', fn () => $this->category?->name),
            'image' => $this->whenLoaded('images', fn () => $this->images->first()?->url),
            'images' => ListingImageResource::collection($this->whenLoaded('images')),
            'seller' => $this->whenLoaded('seller', fn () => [
                'id' => $this->seller_id,
                'name' => $this->seller?->name,
                'store_name' => $this->seller?->sellerProfile?->store_name,
                'account_status' => $this->seller?->status->value, // "suspended" also hides all of this seller's listings
            ]),
            // Set only while the listing is removed.
            'removed_at' => $this->removed_at?->toIso8601String(),
            'removal_reason' => $this->removal_reason,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}