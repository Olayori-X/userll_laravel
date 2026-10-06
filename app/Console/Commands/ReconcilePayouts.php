<?php

namespace App\Console\Commands;

use App\Services\PayoutService;
use Illuminate\Console\Command;

class ReconcilePayouts extends Command
{
    protected $signature = 'marketplace:reconcile-payouts';

    protected $description = 'Ask Paystack about payouts nobody has confirmed (lost responses, missed webhooks) and settle them';

    public function handle(PayoutService $payouts): int
    {
        $this->info("Checked {$payouts->reconcileStale()} payout(s).");

        return self::SUCCESS;
    }
}