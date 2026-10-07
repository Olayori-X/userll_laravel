<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Review */
class AdminReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rating' => $this->rating,
            'comment' => $this->comment,
            'seller_reply' => $this->seller_reply,
            'seller_replied_at' => $this->seller_replied_at?->toIso8601String(),
            'order_number' => $this->order?->order_number,
            'reviewer' => [
                'id' => $this->reviewer_id,
                'name' => $this->reviewer?->name,
                'email' => $this->reviewer?->email,
            ],
            'seller' => [
                'id' => $this->seller_id,
                'name' => $this->seller?->name,
                'store_name' => $this->seller?->sellerProfile?->store_name,
            ],
            'is_hidden' => $this->is_hidden,
            'hidden_reason' => $this->hidden_reason,
            'hidden_by' => $this->hiddenBy?->name,
            'hidden_at' => $this->hidden_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}