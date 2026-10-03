<?php

namespace App\Models;

use App\Enums\LedgerBucket;
use App\Enums\LedgerDirection;
use App\Enums\LedgerType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Append-only: once written, a ledger row can never be changed or removed. */
class LedgerEntry extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Ledger entries are immutable.'));
        static::deleting(fn () => throw new LogicException('Ledger entries cannot be deleted.'));
    }

    protected function casts(): array
    {
        return [
            'type' => LedgerType::class,
            'direction' => LedgerDirection::class,
            'bucket' => LedgerBucket::class,
            'amount' => 'integer',
            'balance_after' => 'integer',
            'meta' => 'array',
        ];
    }

    public function wallet(): BelongsTo { return $this->belongsTo(Wallet::class); }
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function payout(): BelongsTo { return $this->belongsTo(Payout::class); }
}
