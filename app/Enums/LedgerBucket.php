<?php

namespace App\Enums;

enum LedgerBucket: string
{
    case Pending = 'pending';
    case Available = 'available';
}
