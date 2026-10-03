<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    // Written only by the payment service / webhook handler (step 4).
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'amount' => 'integer',
            'paid_at' => 'datetime',
            'raw_payload' => 'array',
        ];
    }

    public function checkout(): BelongsTo { return $this->belongsTo(Checkout::class); }
}
