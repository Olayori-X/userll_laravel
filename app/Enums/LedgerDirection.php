<?php

namespace App\Enums;

enum LedgerDirection: string
{
    case Credit = 'credit';
    case Debit = 'debit';
}
