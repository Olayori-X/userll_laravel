<?php

namespace App\Console\Commands;

use App\Services\OrderFulfilmentService;
use Illuminate\Console\Command;

class CancelOverdueOrders extends Command
{
    protected $signature = 'marketplace:cancel-overdue-orders';

    protected $description = 'Cancel and refund paid orders the seller did not ship in time';

    public function handle(OrderFulfilmentService $fulfilment): int
    {
        $this->info("Cancelled and refunded {$fulfilment->cancelOverdue()} order(s).");

        return self::SUCCESS;
    }
}
