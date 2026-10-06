<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Dispute;
use App\Models\KycSubmission;
use App\Models\Order;
use App\Models\Payout;
use App\Models\Refund;
use App\Models\User;
use App\Models\Checkout;
use App\Models\Payment;
use App\Notifications\UserNotification;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Who hears about what, and what we say. Business code calls one method here; nothing else in the app
 * writes notification text. A failed notification is logged and swallowed, so a mail outage can never
 * break an escrow release, a refund or a payout.
 */
class Notifier
{
    // ------------------------------------------------------------------ orders

    public function orderPaid(Order $order): void
    {
        $this->send(
            $this->user($order->seller_id), 'order.paid',
            'New order to ship',
            "Order {$order->order_number} is paid ({$this->money($order->total)}). Ship it before the deadline so the sale is not cancelled.",
            $this->sellerOrderLink($order), ['order_id' => $order->id], email: true,
        );

        $this->send(
            $this->user($order->buyer_id), 'order.paid',
            'Payment received',
            "We received your payment for order {$order->order_number}. The seller will ship it soon, and your money stays safe with us until you confirm delivery.",
            $this->buyerOrderLink($order), ['order_id' => $order->id], email: true,
        );
    }

    public function orderShipped(Order $order): void
    {
        $this->send(
            $this->user($order->buyer_id), 'order.shipped',
            'Your order has shipped',
            "Order {$order->order_number} is on its way. Confirm delivery when it arrives, or tell us if there is a problem.",
            $this->buyerOrderLink($order), ['order_id' => $order->id], email: true,
        );
    }

    /** The buyer confirmed (or the timer ran out): the seller's money is released. */
    public function orderCompleted(Order $order): void
    {
        $this->send(
            $this->user($order->seller_id), 'order.completed',
            'Money released',
            "Order {$order->order_number} is complete. {$this->money($order->sellerEarnings())} is now in your available balance.",
            '/seller/wallet', ['order_id' => $order->id], email: true,
        );

        $this->send(
            $this->user($order->buyer_id), 'order.completed',
            'Order complete',
            "Order {$order->order_number} is complete. How was it? You can leave the seller a review.",
            $this->buyerOrderLink($order), ['order_id' => $order->id],
        );
    }

    /** The order was cancelled before shipping and the buyer is being refunded. */
    public function orderCancelled(Order $order): void
    {
        $this->send(
            $this->user($order->buyer_id), 'order.cancelled',
            'Order cancelled',
            "Order {$order->order_number} was cancelled. Your refund of {$this->money($order->total)} is on its way to your original payment method.",
            $this->buyerOrderLink($order), ['order_id' => $order->id], email: true,
        );

        $this->send(
            $this->user($order->seller_id), 'order.cancelled',
            'Order cancelled',
            "Order {$order->order_number} was cancelled and refunded to the buyer.",
            $this->sellerOrderLink($order), ['order_id' => $order->id],
        );
    }

        /** Paystack finished the refund: the money has reached the buyer's bank or card. */
    public function refundProcessed(Refund $refund): void
    {
        $order = $refund->order_id ? Order::find($refund->order_id) : null;

        $this->send(
            $this->user($order?->buyer_id ?? $this->buyerOfPayment($refund)),
            'refund.processed',
            'Refund sent',
            ($order ? "Your refund for order {$order->order_number}" : 'Your refund')." of {$this->money($refund->amount)} has been sent. Your bank may take a few days to show it.",
            $order ? $this->buyerOrderLink($order) : null,
            ['refund_id' => $refund->id],
            email: true,
        );
    }

    /** A payment that could not become an order (sold out while paying, say) is being refunded in full. */
    public function paymentRefundStarted(Refund $refund): void
    {
        $this->send(
            $this->user($this->buyerOfPayment($refund)),
            'refund.started',
            "We're refunding your payment",
            "We could not complete your purchase, so we are refunding your payment of {$this->money($refund->amount)} to your original payment method. Your bank may take a few days to show it.",
            null,
            ['refund_id' => $refund->id],
            email: true,
        );
    }

    // ------------------------------------------------------------------ disputes

    public function disputeOpened(Dispute $dispute): void
    {
        $order = Order::find($dispute->order_id);

        if (! $order) {
            return;
        }

        $this->send(
            $this->user($order->seller_id), 'dispute.opened',
            'A buyer reported a problem',
            "The buyer reported a problem with order {$order->order_number}. The money is frozen until our team decides.",
            $this->sellerOrderLink($order), ['order_id' => $order->id, 'dispute_id' => $dispute->id], email: true,
        );

        foreach ($this->admins() as $admin) {
            $this->send(
                $admin, 'admin.dispute_opened',
                'New dispute',
                "A dispute was opened on order {$order->order_number}.",
                "/admin/disputes/{$dispute->id}", ['dispute_id' => $dispute->id],
            );
        }
    }

    public function disputeResolved(Dispute $dispute): void
    {
        $order = Order::find($dispute->order_id);

        if (! $order) {
            return;
        }

        $forSeller = $dispute->resolution === 'release';

        $this->send(
            $this->user($dispute->opened_by), 'dispute.resolved',
            'Your dispute was decided',
            $forSeller
                ? "We reviewed order {$order->order_number} and the payment was released to the seller."
                : "We reviewed order {$order->order_number} and decided in your favour. Your refund is on its way.",
            $this->buyerOrderLink($order), ['order_id' => $order->id, 'dispute_id' => $dispute->id], email: true,
        );

        $this->send(
            $this->user($order->seller_id), 'dispute.resolved',
            'The dispute was decided',
            $forSeller
                ? "We reviewed order {$order->order_number} and decided in your favour. The money is now in your available balance."
                : "We reviewed order {$order->order_number} and the payment was refunded to the buyer.",
            $this->sellerOrderLink($order), ['order_id' => $order->id, 'dispute_id' => $dispute->id], email: true,
        );
    }

    // ------------------------------------------------------------------ payouts

    public function payoutPaid(Payout $payout): void
    {
        $this->send(
            $this->user($payout->seller_id), 'payout.paid',
            'Withdrawal sent',
            "{$this->money($payout->netAmount())} has been sent to your {$payout->bank_name} account ending {$payout->account_last4}.",
            '/seller/wallet', ['payout_id' => $payout->id], email: true,
        );
    }

    /** Failed or reversed: the full amount is back in the seller's available balance. */
    public function payoutReturned(Payout $payout): void
    {
        $this->send(
            $this->user($payout->seller_id), 'payout.failed',
            'Withdrawal did not complete',
            "Your withdrawal of {$this->money($payout->amount)} did not go through. The money is back in your available balance, and you can try again.",
            '/seller/wallet', ['payout_id' => $payout->id], email: true,
        );
    }

    // ------------------------------------------------------------------ identity checks

    public function kycSubmitted(KycSubmission $submission): void
    {
        foreach ($this->admins() as $admin) {
            $this->send(
                $admin, 'admin.kyc_submitted',
                'Identity check to review',
                "{$submission->legal_name} sent identity documents for review.",
                "/admin/kyc/{$submission->id}", ['kyc_submission_id' => $submission->id],
            );
        }
    }

    public function kycApproved(KycSubmission $submission): void
    {
        $this->send(
            $this->user($submission->user_id), 'kyc.approved',
            'Identity verified',
            'Your identity is verified. You can now withdraw your earnings once you have a verified bank account.',
            '/seller/wallet', ['kyc_submission_id' => $submission->id], email: true,
        );
    }

    public function kycRejected(KycSubmission $submission): void
    {
        $this->send(
            $this->user($submission->user_id), 'kyc.rejected',
            'Identity check needs attention',
            "We could not verify your documents: {$submission->rejection_reason} You can send them again.",
            '/seller/kyc', ['kyc_submission_id' => $submission->id], email: true,
        );
    }

    // ------------------------------------------------------------------ internals

    private function send(?User $user, string $kind, string $title, string $body, ?string $link = null, array $meta = [], bool $email = false): void
    {
        if (! $user) {
            return;
        }

        try {
            $user->notify(new UserNotification($kind, $title, $body, $link, $meta, $email));
        } catch (Throwable $e) {
            Log::error('Could not send a notification', ['kind' => $kind, 'user_id' => $user->id, 'error' => $e->getMessage()]);
        }
    }

    private function user(int $id): ?User
    {
        return User::find($id);
    }

    /** The buyer behind a payment: payment -> checkout -> buyer. Null if any link is missing. */
    private function buyerOfPayment(Refund $refund): ?int
    {
        $payment = Payment::find($refund->payment_id);

        return $payment ? Checkout::find($payment->checkout_id)?->buyer_id : null;
    }

    /** @return Collection<int, User> */
    private function admins(): Collection
    {
        return User::where('role', UserRole::Admin->value)->where('status', UserStatus::Active->value)->get();
    }

    private function money(int $kobo): string
    {
        return Money::short($kobo);
    }

    // The frontend paths in one place: change them here if your Next.js routes differ.
    private function buyerOrderLink(Order $order): string
    {
        return "/orders/{$order->order_number}";
    }

    private function sellerOrderLink(Order $order): string
    {
        return "/seller/orders/{$order->order_number}";
    }
}