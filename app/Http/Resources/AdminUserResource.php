<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
class AdminUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $profile = $this->sellerProfile;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'type' => $this->isAdmin() ? 'admin' : ($profile ? 'seller' : 'buyer'),
            'status' => $this->status->value,
            'email_verified' => $this->email_verified_at !== null,
            'suspended_at' => $this->suspended_at?->toIso8601String(),
            'suspension_reason' => $this->suspension_reason, // for admins only: the user is never shown this
            'created_at' => $this->created_at?->toIso8601String(),
            'seller' => $profile ? [
                'store_name' => $profile->store_name,
                'slug' => $profile->slug,
                'kyc_status' => $profile->kyc_status->value,
                'kyc_verified_at' => $profile->kyc_verified_at?->toIso8601String(),
                'reviews_count' => (int) $profile->reviews_count,
                'rating_average' => (float) $profile->rating_average,
            ] : null,
            // Only on the single-user view, where the controller attaches it.
            'stats' => $this->whenHas('stats'),
        ];
    }
}