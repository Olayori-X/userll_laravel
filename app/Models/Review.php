<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Review extends Model
{
    // Only what the buyer writes. The seller's reply and the admin's hide fields (seller_reply, is_hidden...)
    // are set by ReviewService alone, never from request input.
    protected $fillable = ['order_id', 'reviewer_id', 'seller_id', 'rating', 'comment'];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'is_hidden' => 'boolean',
            'seller_replied_at' => 'datetime',
            'hidden_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewer_id'); }
    public function seller(): BelongsTo { return $this->belongsTo(User::class, 'seller_id'); }
    public function hiddenBy(): BelongsTo { return $this->belongsTo(User::class, 'hidden_by'); }

    /** What the public sees and what counts toward the seller's average. */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_hidden', false);
    }
}