<?php

namespace App\Http\Resources;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Refund */
class RefundResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'reason' => $this->reason,
            'amount' => $this->amount,
            'amount_formatted' => Money::naira($this->amount),
            'payment_reference' => $this->whenLoaded('payment', fn () => $this->payment->paystack_reference),
            'order_number' => $this->whenLoaded('order', fn () => $this->order?->order_number),
            'paystack_refund_id' => $this->paystack_refund_id,
            'failure_note' => $this->failure_note,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
