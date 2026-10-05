<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayoutAccount extends Model
{
    // Only what the seller types in. paystack_recipient_code and verified_at are set by the
    // system (PayoutAccountService) after Paystack confirms the account, never from request input.
    protected $fillable = ['user_id', 'bank_code', 'bank_name', 'account_number', 'account_name'];

    protected $hidden = ['paystack_recipient_code'];

    protected function casts(): array
    {
        return ['verified_at' => 'datetime'];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    /** Confirmed with Paystack and ready to receive transfers. */
    public function isVerified(): bool
    {
        return $this->verified_at !== null && $this->paystack_recipient_code !== null;
    }

    /** "0123456789" => "******6789": all the API ever shows back to the seller. */
    public function maskedNumber(): string
    {
        $number = (string) $this->account_number;

        return str_repeat('*', max(strlen($number) - 4, 0)).substr($number, -4);
    }
}