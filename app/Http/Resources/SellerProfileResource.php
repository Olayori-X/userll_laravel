<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\SellerProfile */
class SellerProfileResource extends JsonResource
{
    /** Public fields only: never email, phone or payout details. */
    public function toArray(Request $request): array
    {
        return [
            'store_name' => $this->store_name,
            'slug' => $this->slug,
            'bio' => $this->bio,
            'city' => $this->city,
            'state' => $this->state,
            'is_verified' => $this->isVerified(),
            'reviews_count' => (int) $this->reviews_count,
            'rating_average' => (float) $this->rating_average, // 0 when there are no reviews yet
            'member_since' => $this->created_at?->toDateString(),
        ];
    }
}
