<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payout extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'processed_at' => 'datetime'];
    }

    public function seller(): BelongsTo { return $this->belongsTo(User::class, 'seller_id'); }
}
