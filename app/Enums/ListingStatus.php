<?php

namespace App\Enums;

enum ListingStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case SoldOut = 'sold_out';
    case Removed = 'removed';
}
