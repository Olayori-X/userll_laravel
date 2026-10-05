<?php

namespace App\Models;

use App\Enums\PayoutStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payout extends Model
{
    // Server-created only (PayoutService). Never fill from request input.
    protected $guarded = ['id'];

    // Paystack's identifiers are internal: never part of any API output.
    protected $hidden = ['recipient_code', 'paystack_transfer_code', 'paystack_reference'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'fee' => 'integer',
            'status' => PayoutStatus::class,
            'processed_at' => 'datetime',
        ];
    }

    public function seller(): BelongsTo { return $this->belongsTo(User::class, 'seller_id'); }
    public function ledgerEntries(): HasMany { return $this->hasMany(LedgerEntry::class); }

    /** What the seller's bank actually receives: the amount taken from the wallet minus Paystack's cost. */
    public function netAmount(): int
    {
        return $this->amount - $this->fee;
    }
}