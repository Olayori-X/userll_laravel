<?php

namespace App\Console\Commands;

use App\Services\CheckoutService;
use Illuminate\Console\Command;

class ExpireCheckouts extends Command
{
    protected $signature = 'marketplace:expire-checkouts';

    protected $description = 'Close checkouts that stayed unpaid too long';

    public function handle(CheckoutService $checkouts): int
    {
        $this->info("Expired {$checkouts->expireStale()} checkout(s).");

        return self::SUCCESS;
    }
}
