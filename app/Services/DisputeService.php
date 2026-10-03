<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Dispute;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DisputeService
{
    public const REASONS = ['not_received', 'not_as_described', 'damaged', 'other'];

    public function __construct(private OrderFulfilmentService $fulfilment, private RefundService $refunds)
    {
    }

    /**
     * The buyer reports a problem with a shipped order. This freezes the order: the auto-release timer
     * ignores it until an admin decides. Only possible while the order is "shipped" (before the money is released).
     */
    public function open(Order $order, User $buyer, string $reason, string $details): Dispute
    {
        return DB::transaction(function () use ($order, $buyer, $reason, $details) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->status !== OrderStatus::Shipped) {
                throw ValidationException::withMessages([
                    'status' => 'You can only report a problem with an order that was shipped and not yet confirmed.',
                ]);
            }

            $dispute = Dispute::create([
                'order_id' => $order->id,
                'opened_by' => $buyer->id,
                'reason' => $reason,
                'details' => $details,
                'status' => 'open',
            ]);

            $order->update(['status' => OrderStatus::Disputed]);

            return $dispute;
        });
    }

    /** Admin decides: "release" pays the seller, "refund" returns the money to the buyer. */
    public function resolve(Dispute $dispute, User $admin, string $resolution, ?string $note): Dispute
    {
        $refund = null;

        $dispute = DB::transaction(function () use ($dispute, $admin, $resolution, $note, &$refund) {
            $dispute = Dispute::whereKey($dispute->id)->lockForUpdate()->firstOrFail();

            if ($dispute->status !== 'open') {
                throw ValidationException::withMessages(['dispute' => 'This dispute has already been resolved.']);
            }

            $order = Order::findOrFail($dispute->order_id);

            if ($resolution === 'release') {
                $this->fulfilment->complete($order, allowDisputed: true);
            } else {
                // Goods were shipped (and are probably lost or being returned), so no automatic restock.
                $refund = $this->refunds->createOrderRefund($order, 'dispute_refund', restock: false);
            }

            $dispute->update([
                'status' => 'resolved',
                'resolution' => $resolution,
                'resolution_note' => $note,
                'resolved_by' => $admin->id,
                'resolved_at' => now(),
            ]);

            return $dispute;
        });

        if ($refund) {
            $this->refunds->dispatch($refund); // only after the transaction above has committed
        }

        return $dispute;
    }
}
