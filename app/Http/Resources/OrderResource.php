<?php

namespace App\Http\Resources;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The BUYER's view of an order: no commission, no seller contact details. @mixin \App\Models\Order */
class OrderResource extends JsonResource
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
            'seller' => $this->whenLoaded('seller', fn () => $this->seller->sellerProfile
                ? new SellerProfileResource($this->seller->sellerProfile)
                : null),
            'shipping' => [
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
            'auto_release_at' => $this->auto_release_at?->toIso8601String(),
        ];
    }
}
