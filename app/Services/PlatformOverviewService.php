<?php

namespace App\Services;

use App\Enums\KycStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PayoutStatus;
use App\Enums\RefundStatus;
use App\Enums\UserStatus;
use App\Models\Dispute;
use App\Models\KycSubmission;
use App\Models\Listing;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\Refund;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\Wallet;
use Carbon\CarbonInterface;

/** The numbers an operator watches. Read-only: nothing here changes any data. */
class PlatformOverviewService
{
    /** @return array<string, mixed> */
    public function build(CarbonInterface $from, CarbonInterface $to): array
    {
        $paidInPeriod = fn () => Order::whereNotNull('paid_at')->whereBetween('paid_at', [$from, $to]);

        // Commission is earned when an order completes and its money is released, so completed orders are
        // counted by their release date, not their payment date.
        $releasedInPeriod = fn () => Order::where('status', OrderStatus::Completed->value)
            ->whereNotNull('released_at')
            ->whereBetween('released_at', [$from, $to]);

        $refundsInPeriod = fn () => Refund::whereBetween('created_at', [$from, $to]);

        return [
            'period' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],

            // What happened in the chosen period.
            'sales' => [
                'paid_orders' => $paidInPeriod()->count(),
                'paid_total' => (int) $paidInPeriod()->sum('total'),
                'completed_orders' => $releasedInPeriod()->count(),
                'completed_total' => (int) $releasedInPeriod()->sum('total'),
                'commission_earned' => (int) $releasedInPeriod()->sum('commission'),
                'refunds_requested' => $refundsInPeriod()->count(),
                'refunds_requested_total' => (int) $refundsInPeriod()->sum('amount'),
            ],
            'growth' => [
                'new_users' => User::whereBetween('created_at', [$from, $to])->count(),
                'new_sellers' => SellerProfile::whereBetween('created_at', [$from, $to])->count(),
            ],

            // The state of the platform right now, whatever dates were chosen. Seller wallets and the
            // platform's own wallet (our commission) are kept apart, so nothing of ours is counted as theirs.
            'money_now' => [
                'escrow_held' => (int) Wallet::sum('pending_balance'),                                   // everything buyers paid that is not yet released
                'escrow_sellers_share' => (int) Wallet::where('type', 'seller')->sum('pending_balance'),  // ...of which sellers' money
                'escrow_commission' => (int) Wallet::where('type', 'platform')->sum('pending_balance'),   // ...of which our commission
                'owed_to_sellers' => (int) Wallet::where('type', 'seller')->sum('available_balance'),     // released, waiting to be withdrawn
                'commission_earned_all_time' => (int) Wallet::where('type', 'platform')->sum('available_balance'),
                'payouts_in_progress_count' => $this->openPayouts()->count(),
                'payouts_in_progress_total' => (int) $this->openPayouts()->sum('amount'),
            ],
            'needs_attention' => [
                'open_disputes' => Dispute::where('status', 'open')->count(),
                'pending_kyc' => KycSubmission::where('status', KycStatus::Pending->value)->count(),
                'failed_refunds' => Refund::whereIn('status', [RefundStatus::Failed->value, RefundStatus::NeedsAttention->value])->count(),
                'payments_needing_refund' => Payment::where('status', PaymentStatus::NeedsRefund->value)->count(),
                'payouts_returned_last_7_days' => Payout::whereIn('status', [PayoutStatus::Failed->value, PayoutStatus::Reversed->value])
                    ->where('updated_at', '>=', now()->subDays(7))->count(),
            ],
            'totals' => [
                'users' => User::count(),
                'suspended_users' => User::where('status', UserStatus::Suspended->value)->count(),
                'sellers' => SellerProfile::count(),
                'verified_sellers' => SellerProfile::where('kyc_status', KycStatus::Verified->value)->count(),
                'buyable_listings' => Listing::active()->count(), // live, in stock, seller not suspended
            ],
        ];
    }

    private function openPayouts()
    {
        return Payout::whereIn('status', [PayoutStatus::Pending->value, PayoutStatus::Processing->value]);
    }
}