<?php

namespace App\Jobs;

use App\Models\Payout;
use App\Services\PayoutService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Sends one payout to Paystack. The payout's own status decides whether there is anything to do. */
class SendPayoutToPaystack implements ShouldQueue
{
    use Queueable;

    // One attempt only. PayoutService::send() claims the payout before calling Paystack, so a second
    // run would do nothing anyway, and anything left unconfirmed is settled by marketplace:reconcile-payouts.
    public int $tries = 1;

    public function __construct(public int $payoutId)
    {
    }

    public function handle(PayoutService $payouts): void
    {
        $payout = Payout::find($this->payoutId);

        if ($payout) {
            $payouts->send($payout);
        }
    }

    public function failed(?Throwable $exception): void
    {
        // The payout stays reserved and the reconcile command will settle it, so this is only a log line.
        Log::error('Sending a payout crashed; reconcile will settle it', [
            'payout_id' => $this->payoutId,
            'error' => $exception?->getMessage(),
        ]);
    }
}