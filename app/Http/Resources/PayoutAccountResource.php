<?php

namespace App\Http\Resources;

use App\Services\PayoutAccountService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\PayoutAccount */
class PayoutAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'bank_code' => $this->bank_code,
            'bank_name' => $this->bank_name,
            'account_name' => $this->account_name,
            'account_number' => $this->maskedNumber(), // the full number is never sent back
            'is_verified' => $this->isVerified(),
            'payouts_held_until' => app(PayoutAccountService::class)->heldUntil($this->resource)?->toIso8601String(),
        ];
    }
}