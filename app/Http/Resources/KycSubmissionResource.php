<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\KycSubmission */
class KycSubmissionResource extends JsonResource
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
        ];
    }
}