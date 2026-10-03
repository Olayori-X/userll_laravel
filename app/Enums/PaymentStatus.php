<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    // Money arrived that we cannot turn into an order (late, duplicate, wrong amount, item sold out).
    // It must be refunded to the buyer. The refund flow itself is built in step 4b.
    case NeedsRefund = 'needs_refund';
    case Refunded = 'refunded'; // a refund for the whole payment has been issued (see the refunds table for its outcome)
}
