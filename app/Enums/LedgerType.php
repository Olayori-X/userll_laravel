<?php

namespace App\Enums;

enum LedgerType: string
{
    case EscrowHold = 'escrow_hold';
    case Release = 'release';
    case Commission = 'commission';
    case Refund = 'refund';
    case Payout = 'payout';
}
