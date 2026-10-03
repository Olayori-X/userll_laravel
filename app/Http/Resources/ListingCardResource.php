<?php

namespace App\Http\Resources;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Light version for search results and grids. @mixin \App\Models\Listing */
class ListingCardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'price' => $this->price,
            'price_formatted' => Money::naira($this->price),
            'price_short' => Money::short($this->price),
            'delivery_fee' => $this->delivery_fee,
            'delivery_fee_formatted' => Money::naira($this->delivery_fee),
            'condition' => $this->condition->value,
            'location' => ['city' => $this->city, 'state' => $this->state],
            'image' => $this->whenLoaded('images', fn () => $this->images->first()?->url),
            'store_name' => $this->whenLoaded('seller', fn () => $this->seller->sellerProfile?->store_name),
            'store_slug' => $this->whenLoaded('seller', fn () => $this->seller->sellerProfile?->slug),
        ];
    }
}
