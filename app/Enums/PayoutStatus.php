<?php

namespace App\Enums;

enum PayoutStatus: string
{
    case Pending = 'pending';       // money reserved in the wallet, not yet accepted by Paystack
    case Processing = 'processing'; // Paystack has it and is sending it to the bank
    case Paid = 'paid';             // the bank received it
    case Failed = 'failed';         // never left: the money went back to the seller's balance
    case Reversed = 'reversed';     // Paystack took it back: the money went back to the seller's balance

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Processing, self::Paid, self::Failed, self::Reversed],
            self::Processing => [self::Paid, self::Failed, self::Reversed],
            self::Paid => [self::Reversed], // a bank can still return a transfer after it succeeded
            self::Failed, self::Reversed => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    /** Still waiting for an answer: the seller cannot start another payout, and the reconcile command watches these. */
    public function isOpen(): bool
    {
        return $this === self::Pending || $this === self::Processing;
    }

    /** The money did not reach the seller's bank, so it goes back to their available balance. */
    public function returnsMoney(): bool
    {
        return $this === self::Failed || $this === self::Reversed;
    }

    /** Paystack's transfer status as our status. Null when Paystack says something we do not recognise. */
    public static function fromPaystack(?string $status): ?self
    {
        return match ($status) {
            'success' => self::Paid,
            'failed' => self::Failed,
            'reversed' => self::Reversed,
            'pending', 'processing', 'received', 'queued' => self::Processing,
            default => null,
        };
    }
}