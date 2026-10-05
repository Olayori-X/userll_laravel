<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\KycSubmission */
class AdminKycSubmissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'legal_name' => $this->legal_name,
            'id_type' => $this->id_type,
            'id_type_label' => $this->idTypeLabel(),
            'rejection_reason' => $this->rejection_reason,
            'submitted_at' => $this->created_at?->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'seller' => [
                'id' => $this->user_id,
                'name' => $this->user?->name,
                'email' => $this->user?->email,
                'store_name' => $this->user?->sellerProfile?->store_name,
            ],
            // The photo is never public. The frontend must fetch this path with the admin's token and show the result as an image blob.
            'photo_path' => "/api/v1/admin/kyc/{$this->id}/photo",
            // match | partial | mismatch | no_bank_account. Only set on the single-submission view.
            'name_match' => $this->whenHas('name_match'),
        ];
    }
}