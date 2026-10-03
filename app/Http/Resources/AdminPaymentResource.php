<?php

namespace App\Http\Resources;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Payment */
class AdminPaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->paystack_reference,
            'checkout_reference' => $this->whenLoaded('checkout', fn () => $this->checkout->reference),
            'amount' => $this->amount,
            'amount_formatted' => Money::naira($this->amount),
            'status' => $this->status->value,
            'flag' => $this->raw_payload['flag'] ?? null, // why it needs a refund: out_of_stock, amount_mismatch, ...
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
