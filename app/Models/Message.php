<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    protected $fillable = ['listing_id', 'order_id', 'sender_id', 'recipient_id', 'body'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    public function sender(): BelongsTo { return $this->belongsTo(User::class, 'sender_id'); }
    public function recipient(): BelongsTo { return $this->belongsTo(User::class, 'recipient_id'); }
    public function listing(): BelongsTo { return $this->belongsTo(Listing::class); }
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
}
