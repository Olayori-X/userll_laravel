<?php

namespace App\Http\Resources;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The SELLER's view: what to ship, where, and what they earn. Never the buyer's email. @mixin \App\Models\Order */
class SellerOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'order_number' => $this->order_number,
            'status' => $this->status->value,
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'subtotal' => $this->subtotal,
            'subtotal_formatted' => Money::naira($this->subtotal),
            'delivery_fee' => $this->delivery_fee,
            'delivery_fee_formatted' => Money::naira($this->delivery_fee),
            'total' => $this->total,
            'total_formatted' => Money::naira($this->total),
            'commission' => $this->commission,
            'commission_formatted' => Money::naira($this->commission),
            'commission_rate_bps' => $this->commission_rate_bps,
            'earnings' => $this->sellerEarnings(),
            'earnings_formatted' => Money::naira($this->sellerEarnings()),
            // Needed to deliver the goods.
            'ship_to' => [
                'name' => $this->shipping_name,
                'phone' => $this->shipping_phone,
                'street' => $this->shipping_street,
                'city' => $this->shipping_city,
                'state' => $this->shipping_state,
            ],
            'tracking_info' => $this->tracking_info,
            'created_at' => $this->created_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'ship_by_at' => $this->ship_by_at?->toIso8601String(),
            'shipped_at' => $this->shipped_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
        ];
    }
}
