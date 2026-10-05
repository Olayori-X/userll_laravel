<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Jobs\SendRefundToPaystack;
use App\Models\Listing;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class RefundService
{
    public function __construct(private LedgerService $ledger)
    {
    }

    /** Refund one paid order (the buyer gets order.total back) and send the request to Paystack. */
    public function refundOrder(Order $order, string $reason, bool $restock): Refund
    {
        $refund = DB::transaction(fn () => $this->createOrderRefund($order, $reason, $restock));

        $this->dispatch($refund);

        return $refund;
    }

    /**
     * All the database work of an order refund. Anyone calling this inside a bigger transaction
     * must call dispatch() themselves AFTER that transaction commits.
     */
    public function createOrderRefund(Order $order, string $reason, bool $restock): Refund
    {
        $order = Order::whereKey($order->id)->lockForUpdate()->with('items')->firstOrFail();

        if ($order->released_at !== null || ! $order->status->canTransitionTo(OrderStatus::Refunded)) {
            throw ValidationException::withMessages([
                'status' => "This order can no longer be refunded (status: {$order->status->value}).",
            ]);
        }

        $payment = Payment::where('checkout_id', $order->checkout_id)
            ->where('status', PaymentStatus::Paid->value)
            ->lockForUpdate()
            ->firstOrFail();

        // Sanity guard: never promise back more than the buyer paid.
        $alreadyRefunded = (int) Refund::where('payment_id', $payment->id)->sum('amount');
        if ($alreadyRefunded + $order->total > $payment->amount) {
            throw new RuntimeException("Refund would exceed payment {$payment->id}.");
        }

        $this->ledger->reverseEscrow($order);

        if ($restock) {
            $this->restock($order);
        }

        $order->update(['status' => OrderStatus::Refunded, 'cancelled_at' => now()]);

        return Refund::create([
            'payment_id' => $payment->id,
            'order_id' => $order->id,
            'amount' => $order->total,
            'status' => RefundStatus::Pending,
            'reason' => $reason,
        ]);
    }

    /** Refund a whole payment that could not become an order (status "needs_refund"). Admin action. */
    public function refundPayment(Payment $payment, string $reason): Refund
    {
        $refund = DB::transaction(function () use ($payment, $reason) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->status !== PaymentStatus::NeedsRefund) {
                throw ValidationException::withMessages([
                    'payment' => "Only payments marked needs_refund can be refunded this way (status: {$payment->status->value}).",
                ]);
            }

            $payment->update(['status' => PaymentStatus::Refunded]);

            return Refund::create([
                'payment_id' => $payment->id,
                'order_id' => null,
                'amount' => $payment->amount,
                'status' => RefundStatus::Pending,
                'reason' => $reason,
            ]);
        });

        $this->dispatch($refund);

        return $refund;
    }

    /** Send a failed refund again. The admin should check the Paystack dashboard first. */
    public function retry(Refund $refund): Refund
    {
        $refund = DB::transaction(function () use ($refund) {
            $locked = Refund::whereKey($refund->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== RefundStatus::Failed) {
                throw ValidationException::withMessages(['refund' => 'Only failed refunds can be retried.']);
            }

            $locked->update(['status' => RefundStatus::Pending, 'failure_note' => null, 'paystack_refund_id' => null]);

            return $locked;
        });

        $this->dispatch($refund);

        return $refund->fresh();
    }

    /** Put the refund request on the queue. A broken queue must never break the buyer's request. */
    public function dispatch(Refund $refund): void
    {
        try {
            SendRefundToPaystack::dispatch($refund->id);
        } catch (Throwable $e) {
            // The job marks the refund as failed itself; here we only make sure nothing bubbles up.
            Log::error('Refund dispatch failed', ['refund_id' => $refund->id, 'error' => $e->getMessage()]);
        }
    }

        /** A refund.* webhook from Paystack: keep our refund row in step with what Paystack reports. */
    public function applyGatewayEvent(string $event, string $transactionReference, int $amount, ?string $refundReference = null): void
    {
        $status = match ($event) {
            'refund.processing' => RefundStatus::Processing,
            'refund.processed' => RefundStatus::Processed,
            'refund.failed' => RefundStatus::Failed,
            'refund.needs-attention' => RefundStatus::NeedsAttention,
            default => null, // refund.pending: nothing to do
        };

        if ($status === null) {
            return;
        }

        $refundReference = ($refundReference !== null && $refundReference !== '') ? $refundReference : null;

        // Paystack refund webhooks carry the payment's reference, the amount and (usually) their own
        // refund reference, but not our refund id. So we bind each refund to that reference the first
        // time we see it, and match on it from then on.
        $forPayment = fn () => Refund::whereHas('payment', fn ($q) => $q->where('paystack_reference', $transactionReference));

        $refund = null;

        if ($refundReference !== null) {
            $refund = $forPayment()->where('paystack_refund_reference', $refundReference)->first();
        }

        if (! $refund) {
            // First event for this refund: pick a refund of the same amount that is not finished and
            // is not already at this status, so two equal-amount refunds on one payment each get their own event.
            $refund = $forPayment()
                ->where('amount', $amount)
                ->when($refundReference !== null, fn ($q) => $q->whereNull('paystack_refund_reference'))
                ->whereNotIn('status', [RefundStatus::Processed->value, $status->value])
                ->orderBy('id')
                ->first();
        }

        if (! $refund) {
            Log::warning('Refund webhook did not match a refund', [
                'event' => $event,
                'reference' => $transactionReference,
                'refund_reference' => $refundReference,
            ]);

            return;
        }

        // Events can arrive out of order: a finished refund never goes backwards.
        if ($refund->status === RefundStatus::Processed || $refund->status === $status) {
            return;
        }

        $refund->update([
            'status' => $status,
            'paystack_refund_reference' => $refundReference ?? $refund->paystack_refund_reference,
            'failure_note' => match ($status) {
                RefundStatus::Failed => 'Paystack could not process this refund; the amount went back to your Paystack balance. Retry it or pay the buyer another way.',
                RefundStatus::NeedsAttention => 'Paystack needs the buyer\'s bank details to finish this refund. Handle it in the Paystack dashboard.',
                default => null,
            },
        ]);
    }

    /** Goods that were reserved at payment go back on sale. */
    private function restock(Order $order): void
    {
        $byListing = $order->items->whereNotNull('listing_id')->groupBy('listing_id')->sortKeys();

        foreach ($byListing as $listingId => $items) {
            $listing = Listing::withTrashed()->whereKey($listingId)->lockForUpdate()->first();

            if ($listing) {
                $listing->stock += (int) $items->sum('quantity');
                $listing->syncStockStatus(); // sold_out becomes active again
                $listing->save();
            }
        }
    }
}
