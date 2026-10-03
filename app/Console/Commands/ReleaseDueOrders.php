<?php

namespace App\Console\Commands;

use App\Services\OrderFulfilmentService;
use Illuminate\Console\Command;

class ReleaseDueOrders extends Command
{
    protected $signature = 'marketplace:release-due-orders';

    protected $description = 'Release escrow for shipped orders whose auto-release time has passed';

    public function handle(OrderFulfilmentService $fulfilment): int
    {
        $this->info("Released {$fulfilment->releaseDue()} order(s).");

        return self::SUCCESS;
    }
}
