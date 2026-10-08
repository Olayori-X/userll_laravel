<?php

namespace App\Jobs;

use App\Enums\RefundStatus;
use App\Models\Refund;
use App\Services\PaystackClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Sends one refund request to Paystack. Needs a running queue worker (php artisan queue:work).
 *
 * It is deliberately tried only once: if Paystack received the request but the answer got lost,
 * a blind retry could refund the buyer twice. A failure is marked on the refund row for an admin to
 * check in the Paystack dashboard and then retry by hand.
 */
class SendRefundToPaystack implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public int $refundId)
    {
    }

    public function handle(PaystackClient $paystack): void
    {
        $refund = Refund::with('payment')->find($this->refundId);

        // Only a fresh refund is sent; anything else was already handled.
        if (! $refund || $refund->status !== RefundStatus::Pending || $refund->paystack_refund_id !== null) {
            return;
        }

        $data = $paystack->refund(
            $refund->payment->paystack_reference,
            $refund->amount,
            'Refund from Userll ('.$refund->reason.')',
        );

        $newStatus = RefundStatus::fromPaystack($data['status'] ?? null);

        // Always keep Paystack's id, whatever else happened while we waited for its answer.
        Refund::whereKey($refund->id)->update([
            'paystack_refund_id' => isset($data['id']) ? (string) $data['id'] : null,
        ]);

        // Only move the status if nothing has moved it since we read the row. A refund.processed
        // webhook can arrive while this request is still in flight, and a finished refund must
        // never be pushed back to "processing" by an older answer.
        Refund::whereKey($refund->id)
            ->where('status', RefundStatus::Pending->value)
            ->update(['status' => $newStatus->value]);
    }

    public function failed(Throwable $e): void
    {
        Refund::whereKey($this->refundId)
            ->where('status', RefundStatus::Pending->value)
            ->update([
                'status' => RefundStatus::Failed->value,
                'failure_note' => 'The request to Paystack failed. Check the Paystack dashboard before retrying, to avoid refunding twice.',
            ]);
    }
}
