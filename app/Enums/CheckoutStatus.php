<?php

namespace App\Enums;

enum CheckoutStatus: string
{
    case Pending = 'pending';     // created, waiting for the buyer to pay
    case Paid = 'paid';
    case Cancelled = 'cancelled'; // replaced by a newer checkout, or abandoned
    case Expired = 'expired';     // unpaid for too long (set by a scheduled job in step 4)
}
