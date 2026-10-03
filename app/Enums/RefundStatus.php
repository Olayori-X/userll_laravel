<?php

namespace App\Enums;

enum RefundStatus: string
{
    case Pending = 'pending';               // created, not yet accepted by Paystack
    case Processing = 'processing';         // Paystack has it
    case Processed = 'processed';           // done (the bank may still take a few days)
    case Failed = 'failed';                 // needs an admin: check Paystack, then retry
    case NeedsAttention = 'needs_attention'; // Paystack needs the buyer's bank details

    public static function fromPaystack(?string $status): self
    {
        return match ($status) {
            'processing' => self::Processing,
            'processed' => self::Processed,
            'failed' => self::Failed,
            'needs-attention' => self::NeedsAttention,
            default => self::Pending,
        };
    }
}
