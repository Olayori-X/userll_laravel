<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Review */
class OwnReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order?->order_number,
            'rating' => $this->rating,
            'comment' => $this->comment,
            'reviewer' => ReviewResource::displayName($this->reviewer?->name),
            'seller_reply' => $this->seller_reply,
            'seller_replied_at' => $this->seller_replied_at?->toIso8601String(),
            // Only the seller can reply, once, and not to a hidden review.
            'can_reply' => $this->seller_reply === null && ! $this->is_hidden,
            // A hidden review disappears from public lists, but its buyer and seller still see it, and why.
            'is_hidden' => $this->is_hidden,
            'hidden_reason' => $this->is_hidden ? $this->hidden_reason : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}