<?php

namespace App\Enums;

enum LedgerType: string
{
    case EscrowHold = 'escrow_hold';
    case Release = 'release';
    case Commission = 'commission';
    case Refund = 'refund';
    case Payout = 'payout';
    case PayoutReturn = 'payout_return'; // a payout that failed or was reversed: the money goes back to the seller
}