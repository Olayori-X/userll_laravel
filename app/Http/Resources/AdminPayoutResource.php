<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Payout */
class AdminPayoutResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'amount' => $this->amount,
            'fee' => $this->fee,
            'net_amount' => $this->netAmount(),
            'seller' => [
                'id' => $this->seller_id,
                'name' => $this->seller?->name,
                'email' => $this->seller?->email,
                'store_name' => $this->seller?->sellerProfile?->store_name,
            ],
            'bank_name' => $this->bank_name,
            'account_name' => $this->account_name,
            'account_number' => $this->account_last4 ? '******'.$this->account_last4 : null,
            // For admins only: search these in the Paystack dashboard (Transfers page) when something looks wrong.
            'paystack_reference' => $this->paystack_reference,
            'paystack_transfer_code' => $this->paystack_transfer_code,
            'failure_reason' => $this->failure_reason, // Paystack's own wording; never shown to sellers
            'requested_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'paid_at' => $this->processed_at?->toIso8601String(),
        ];
    }
}