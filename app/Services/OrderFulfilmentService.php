<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class OrderFulfilmentService
{
    public function __construct(private LedgerService $ledger, private RefundService $refunds)
    {
    }

    /** Seller hands the goods over. Starts the clock after which the money is released automatically. */
    public function ship(Order $order, string $trackingInfo): Order
    {
        return DB::transaction(function () use ($order, $trackingInfo) {
            $order = $this->lock($order);
            $this->requireStatus($order, [OrderStatus::Paid], 'shipped');

            $now = now();
            $order->update([
                'status' => OrderStatus::Shipped,
                'shipped_at' => $now,
                'tracking_info' => $trackingInfo,
                'auto_release_at' => $now->copy()->addDays((int) config('marketplace.auto_release_days')),
            ]);

            return $order;
        });
    }

    /**
     * The order is done: the buyer confirmed receipt, the auto-release timer ran out, or an admin
     * ruled for the seller. Moves the seller's money from escrow to available. Safe to call twice.
     */
    public function complete(Order $order, bool $allowDisputed = false): Order
    {
        return DB::transaction(function () use ($order, $allowDisputed) {
            $order = $this->lock($order);

            if ($order->status === OrderStatus::Completed) {
                return $order; // already done (double click, or the timer got there first)
            }

            $this->requireStatus(
                $order,
                $allowDisputed
                    ? [OrderStatus::Shipped, OrderStatus::Delivered, OrderStatus::Disputed]
                    : [OrderStatus::Shipped, OrderStatus::Delivered],
                'completed',
            );

            if ($order->released_at !== null) {
                throw ValidationException::withMessages(['status' => 'This order has already been paid out.']);
            }

            if ($order->status === OrderStatus::Shipped) {
                $order->update(['status' => OrderStatus::Delivered, 'delivered_at' => now()]);
            } elseif ($order->delivered_at === null) {
                $order->update(['delivered_at' => now()]); // a disputed order being settled in the seller's favour
            }

            $this->ledger->releaseEscrow($order);
            $order->update(['status' => OrderStatus::Completed, 'released_at' => now()]);

            return $order;
        });
    }

    /** Buyer or seller backs out before the goods were shipped: full refund, stock goes back on sale. */
    public function cancelBeforeShipping(Order $order, string $reason): Order
    {
        $this->requireStatus($order->fresh(), [OrderStatus::Paid], 'cancelled');

        $this->refunds->refundOrder($order, $reason, restock: true);

        return $order->fresh();
    }

    /** Scheduled: release orders whose auto-release time has passed (and that nobody disputed). */
    public function releaseDue(): int
    {
        $released = 0;

        Order::where('status', OrderStatus::Shipped->value)
            ->where('auto_release_at', '<=', now())
            ->chunkById(100, function (Collection $orders) use (&$released) {
                foreach ($orders as $order) {
                    try {
                        $this->complete($order);
                        $released++;
                    } catch (Throwable $e) {
                        Log::error('Auto-release failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
                    }
                }
            });

        return $released;
    }

    /** Scheduled: a seller who missed the ship-by deadline loses the sale and the buyer is refunded. */
    public function cancelOverdue(): int
    {
        $cancelled = 0;

        Order::where('status', OrderStatus::Paid->value)
            ->where('ship_by_at', '<=', now())
            ->chunkById(100, function (Collection $orders) use (&$cancelled) {
                foreach ($orders as $order) {
                    try {
                        $this->refunds->refundOrder($order, 'seller_did_not_ship', restock: true);
                        $cancelled++;
                    } catch (Throwable $e) {
                        Log::error('Overdue cancellation failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
                    }
                }
            });

        return $cancelled;
    }

    private function lock(Order $order): Order
    {
        return Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
    }

    /** @param list<OrderStatus> $allowed */
    private function requireStatus(Order $order, array $allowed, string $action): void
    {
        if (! in_array($order->status, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => "This order cannot be {$action} right now (status: {$order->status->value}).",
            ]);
        }
    }
}
