<?php

namespace App\Models;

use App\Enums\RefundStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Refund extends Model
{
    // Server-created only (RefundService). Never fill from request input.
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'status' => RefundStatus::class];
    }

    public function payment(): BelongsTo { return $this->belongsTo(Payment::class); }
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
}
