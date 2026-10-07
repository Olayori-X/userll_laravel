<?php

namespace App\Http\Resources;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Full listing (detail page, seller dashboard). @mixin \App\Models\Listing */
class ListingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'description' => $this->description,
            'price' => $this->price,                       // kobo, for maths
            'price_formatted' => Money::naira($this->price), // ₦100,500.00
            'price_short' => Money::short($this->price),     // ₦100.50k
            'delivery_fee' => $this->delivery_fee,           // kobo, 0 = free delivery
            'delivery_fee_formatted' => Money::naira($this->delivery_fee),
            'condition' => $this->condition->value,
            'stock' => $this->stock,
            'status' => $this->status->value,
            'removal_reason' => $this->when($this->removal_reason !== null, $this->removal_reason),
            'location' => ['city' => $this->city, 'state' => $this->state],
            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'slug' => $this->category->slug,
            ]),
            'images' => ListingImageResource::collection($this->whenLoaded('images')),
            'seller' => $this->whenLoaded('seller', fn () => $this->seller->sellerProfile
                ? new SellerProfileResource($this->seller->sellerProfile)
                : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
