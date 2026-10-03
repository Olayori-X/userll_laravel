<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Wallet extends Model
{
    // Balances change only through the ledger service (step 4), inside a DB transaction.
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['pending_balance' => 'integer', 'available_balance' => 'integer'];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function entries(): HasMany { return $this->hasMany(LedgerEntry::class); }
}
