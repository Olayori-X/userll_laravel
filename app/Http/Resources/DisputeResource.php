<?php

namespace App\Http\Resources;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Admin view of a dispute. @mixin \App\Models\Dispute */
class DisputeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'reason' => $this->reason,
            'details' => $this->details,
            'resolution' => $this->resolution,
            'resolution_note' => $this->resolution_note,
            'created_at' => $this->created_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'order' => $this->whenLoaded('order', fn () => [
                'order_number' => $this->order->order_number,
                'status' => $this->order->status->value,
                'total_formatted' => Money::naira($this->order->total),
                'tracking_info' => $this->order->tracking_info,
                'shipped_at' => $this->order->shipped_at?->toIso8601String(),
                'store_name' => $this->order->seller?->sellerProfile?->store_name,
            ]),
            'opened_by' => $this->whenLoaded('opener', fn () => [
                'name' => $this->opener->name,
                'email' => $this->opener->email,
            ]),
        ];
    }
}
