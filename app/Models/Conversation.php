<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Conversation extends Model
{
    // Server-created only (ChatService). Never fill from request input.
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime'];
    }

    public function buyer(): BelongsTo { return $this->belongsTo(User::class, 'buyer_id'); }
    public function seller(): BelongsTo { return $this->belongsTo(User::class, 'seller_id'); }
    public function listing(): BelongsTo { return $this->belongsTo(Listing::class); }
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function messages(): HasMany { return $this->hasMany(Message::class); }

    /** The newest message, for the preview line in a conversation list. */
    public function latestMessage(): HasOne { return $this->hasOne(Message::class)->latestOfMany(); }

    /** Conversations this user is part of, as buyer or as seller. */
    public function scopeInvolving(Builder $query, int $userId): Builder
    {
        return $query->where(fn ($q) => $q->where('buyer_id', $userId)->orWhere('seller_id', $userId));
    }

    public function includes(User $user): bool
    {
        return $user->id === $this->buyer_id || $user->id === $this->seller_id;
    }

    /** The id of the person on the other side. */
    public function otherPartyId(User $me): int
    {
        return $me->id === $this->buyer_id ? $this->seller_id : $this->buyer_id;
    }

    /** An order chat is about a purchase; otherwise it is a pre-sale question about a listing. */
    public function isOrderChat(): bool
    {
        return $this->order_id !== null;
    }
}