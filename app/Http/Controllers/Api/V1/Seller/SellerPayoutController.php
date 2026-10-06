<?php

namespace App\Http\Controllers\Api\V1\Seller;

use App\Enums\PayoutStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\PayoutAccountResource;
use App\Http\Resources\PayoutResource;
use App\Models\Payout;
use App\Models\PayoutAccount;
use App\Services\LedgerService;
use App\Services\PayoutAccountService;
use App\Services\PayoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SellerPayoutController extends Controller
{
    /** Balances, whether the seller can withdraw right now, and exactly what is blocking them if not. */
    public function summary(Request $request, LedgerService $ledger, PayoutAccountService $accounts): JsonResponse
    {
        $user = $request->user();
        $wallet = $ledger->walletForSeller($user->id);
        $account = PayoutAccount::where('user_id', $user->id)->first();
        $min = (int) config('marketplace.min_payout_kobo');

        $open = Payout::where('seller_id', $user->id)
            ->whereIn('status', [PayoutStatus::Pending->value, PayoutStatus::Processing->value])
            ->latest('id')
            ->first();

        $blockers = [];

        if (! $user->sellerProfile->isVerified()) {
            $blockers[] = ['code' => 'kyc', 'message' => 'Verify your identity before you withdraw.'];
        }

        if (! $account || ! $account->isVerified()) {
            $blockers[] = ['code' => 'payout_account', 'message' => 'Add and verify your bank account before you withdraw.'];
        } elseif ($until = $accounts->heldUntil($account)) {
            $blockers[] = [
                'code' => 'account_hold',
                'message' => 'Withdrawals are paused for a short time after you add or change your bank account.',
                'until' => $until->toIso8601String(),
            ];
        }

        if ($open) {
            $blockers[] = ['code' => 'payout_in_progress', 'message' => 'You already have a withdrawal in progress.'];
        }

        if ($wallet->available_balance < $min) {
            $blockers[] = ['code' => 'balance_too_low', 'message' => 'Your available balance is below the smallest withdrawal.'];
        }

        return response()->json(['data' => [
            'available_balance' => $wallet->available_balance, // can be withdrawn now
            'pending_balance' => $wallet->pending_balance,     // held in escrow until orders are delivered
            'min_payout' => $min,
            'can_withdraw' => $blockers === [],
            'blockers' => $blockers,
            'payout_account' => $account ? PayoutAccountResource::make($account)->resolve() : null,
            'open_payout' => $open ? PayoutResource::make($open)->resolve() : null,
        ]]);
    }

    /** What a withdrawal of this amount would cost and what would reach the seller's bank. */
    public function quote(Request $request, PayoutService $payouts): JsonResponse
    {
        $data = $request->validate(['amount' => ['required', 'integer', 'min:1', 'max:'.PayoutService::MAX_TRANSFER_KOBO]]);

        return response()->json(['data' => $payouts->quote((int) $data['amount']) + [
            'min_payout' => (int) config('marketplace.min_payout_kobo'),
        ]]);
    }

    /** The seller's withdrawals, newest first. */
    public function index(Request $request): AnonymousResourceCollection
    {
        return PayoutResource::collection(
            Payout::where('seller_id', $request->user()->id)->latest('id')->paginate(20)
        );
    }

    /** Withdraw money. The amount (in kobo) leaves the available balance at once; the bank receives it minus the fee. */
    public function store(Request $request, PayoutService $payouts): JsonResponse
    {
        $data = $request->validate(['amount' => ['required', 'integer', 'min:1']]);

        $payout = $payouts->request($request->user(), (int) $data['amount']);

        return PayoutResource::make($payout)->response()->setStatusCode(201);
    }
}