<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Payout */
class PayoutResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'amount' => $this->amount,           // taken from the seller's balance, in kobo
            'fee' => $this->fee,                 // Paystack's transfer cost, in kobo
            'net_amount' => $this->netAmount(),  // what reaches their bank, in kobo
            'status' => $this->status->value,
            'bank_name' => $this->bank_name,
            'account_name' => $this->account_name,
            'account_number' => $this->account_last4 ? '******'.$this->account_last4 : null,
            // Paystack's own error text can reveal things about our account, so the seller only sees this.
            'message' => $this->status->returnsMoney()
                ? 'The transfer did not complete. The money is back in your available balance.'
                : null,
            'requested_at' => $this->created_at?->toIso8601String(),
            'paid_at' => $this->processed_at?->toIso8601String(),
        ];
    }
}