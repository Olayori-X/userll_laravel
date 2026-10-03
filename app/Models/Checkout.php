<?php

namespace App\Models;

use App\Enums\CheckoutStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Checkout extends Model
{
    // Server-created only (CheckoutService). Never fill from request input.
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['total' => 'integer', 'status' => CheckoutStatus::class];
    }

    public function buyer(): BelongsTo { return $this->belongsTo(User::class, 'buyer_id'); }
    public function orders(): HasMany { return $this->hasMany(Order::class); }
    public function payments(): HasMany { return $this->hasMany(Payment::class); }
}
