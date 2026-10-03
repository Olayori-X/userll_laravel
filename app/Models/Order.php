<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    // Server-created only (checkout service, step 3). Never fill from request input.
    protected $guarded = ['id'];

    protected $attributes = ['delivery_fee' => 0];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'subtotal' => 'integer',
            'delivery_fee' => 'integer',
            'delivery_fee' => 'integer',
            'commission' => 'integer',
            'total' => 'integer',
            'commission_rate_bps' => 'integer',
            'paid_at' => 'datetime',
            'ship_by_at' => 'datetime',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
            'auto_release_at' => 'datetime',
            'released_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function checkout(): BelongsTo { return $this->belongsTo(Checkout::class); }
    public function buyer(): BelongsTo { return $this->belongsTo(User::class, 'buyer_id'); }
    public function seller(): BelongsTo { return $this->belongsTo(User::class, 'seller_id'); }
    public function items(): HasMany { return $this->hasMany(OrderItem::class); }
    public function disputes(): HasMany { return $this->hasMany(Dispute::class); }
    public function review(): HasOne { return $this->hasOne(Review::class); }
    public function refunds(): HasMany { return $this->hasMany(Refund::class); }

    /**
     * What the seller is owed once the order completes: items + delivery fee, minus our commission.
     * (Commission is charged on the items only, not on the delivery fee.)
     */
    public function sellerEarnings(): int
    {
        return $this->subtotal + $this->delivery_fee - $this->commission;
    }

    public function canTransitionTo(OrderStatus $next): bool
    {
        return $this->status->canTransitionTo($next);
    }
}
