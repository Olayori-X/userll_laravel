<?php

namespace App\Http\Resources;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Checkout */
class CheckoutResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'reference' => $this->reference,
            'status' => $this->status->value,
            'total' => $this->total,
            'total_formatted' => Money::naira($this->total),
            'orders' => OrderResource::collection($this->whenLoaded('orders')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
