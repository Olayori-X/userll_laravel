<?php

namespace App\Services;

use App\Enums\PayoutStatus;
use App\Jobs\SendPayoutToPaystack;
use App\Models\Payout;
use App\Models\PayoutAccount;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PayoutService
{
    /** Paystack's largest single NGN transfer: ₦10,000,000. */
    public const MAX_TRANSFER_KOBO = 1_000_000_000;

    public function __construct(
        private PaystackClient $paystack,
        private LedgerService $ledger,
        private PayoutAccountService $accounts,
        private Notifier $notifier,
    ) {
    }

    // ------------------------------------------------------------------ fee

    /** Paystack's cost for sending this amount, taken out of the seller's withdrawal. */
    public function fee(int $amount): int
    {
        $config = config('marketplace.payout_fee');
        $tiers = $config['tiers'];

        $fee = (int) (end($tiers)['fee_kobo'] ?? 0);

        foreach ($tiers as $tier) {
            if ($tier['up_to_kobo'] === null || $amount <= $tier['up_to_kobo']) {
                $fee = (int) $tier['fee_kobo'];
                break;
            }
        }

        if ($amount >= $config['stamp_duty_from_kobo']) {
            $fee += (int) $config['stamp_duty_kobo'];
        }

        return $fee;
    }

    /** What a withdrawal of this amount costs and what the seller would receive. @return array{amount: int, fee: int, net: int} */
    public function quote(int $amount): array
    {
        $fee = $this->fee($amount);

        return ['amount' => $amount, 'fee' => $fee, 'net' => $amount - $fee];
    }

    // ------------------------------------------------------------------ the seller asks for a payout

    /**
     * Reserve the money and queue the transfer. Throws ValidationException for anything the seller can
     * fix (identity not verified, no bank account, account on hold, amount too small or too large).
     */
    public function request(User $seller, int $amount): Payout
    {
        $profile = SellerProfile::where('user_id', $seller->id)->first();

        if (! $profile || ! $profile->isVerified()) {
            throw ValidationException::withMessages(['kyc' => 'Verify your identity before you withdraw.']);
        }

        $min = (int) config('marketplace.min_payout_kobo');

        if ($amount < $min) {
            throw ValidationException::withMessages(['amount' => 'The smallest withdrawal is ₦'.number_format($min / 100, 2).'.']);
        }

        if ($amount > self::MAX_TRANSFER_KOBO) {
            throw ValidationException::withMessages(['amount' => 'That is more than one withdrawal can carry. Withdraw a smaller amount.']);
        }

        if ($this->quote($amount)['net'] <= 0) {
            throw ValidationException::withMessages(['amount' => 'That amount would not cover the transfer fee.']);
        }

        $payout = DB::transaction(function () use ($seller, $amount) {
            // Lock the wallet first. Two quick requests then take turns instead of both passing the checks below.
            $wallet = Wallet::whereKey($this->ledger->walletForSeller($seller->id)->id)->lockForUpdate()->firstOrFail();

            $account = PayoutAccount::where('user_id', $seller->id)->first();

            if (! $account || ! $account->isVerified()) {
                throw ValidationException::withMessages(['payout_account' => 'Add and verify your bank account before you withdraw.']);
            }

            if ($until = $this->accounts->heldUntil($account)) {
                throw ValidationException::withMessages([
                    'payout_account' => 'Withdrawals are paused for a short time after you add or change your bank account. You can withdraw again '.$until->diffForHumans().'.',
                ]);
            }

            $open = Payout::where('seller_id', $seller->id)
                ->whereIn('status', [PayoutStatus::Pending->value, PayoutStatus::Processing->value])
                ->exists();

            if ($open) {
                throw ValidationException::withMessages(['payout' => 'You already have a withdrawal in progress. Wait for it to finish.']);
            }

            if ($wallet->available_balance < $amount) {
                throw ValidationException::withMessages(['amount' => 'You cannot withdraw more than your available balance.']);
            }

            $payout = Payout::create([
                'seller_id' => $seller->id,
                'amount' => $amount,
                'fee' => $this->fee($amount),
                'status' => PayoutStatus::Pending,
                // Paystack wants 16-50 characters: lowercase letters, digits, dash and underscore.
                'paystack_reference' => 'payout-'.Str::lower((string) Str::ulid()),
                // Where the money goes is fixed now, whatever the seller does with their account later.
                'recipient_code' => $account->paystack_recipient_code,
                'bank_name' => $account->bank_name,
                'account_name' => $account->account_name,
                'account_last4' => substr((string) $account->account_number, -4),
            ]);

            $this->ledger->reservePayout($payout);

            return $payout;
        });

        SendPayoutToPaystack::dispatch($payout->id);

        return $payout;
    }

    // ------------------------------------------------------------------ sending (called by the queued job)

    public function send(Payout $payout): void
    {
        // Claim the payout. Only one caller can move it from pending to processing, so it is never sent twice.
        $claimed = DB::transaction(function () use ($payout) {
            $locked = Payout::whereKey($payout->id)->lockForUpdate()->first();

            if (! $locked || $locked->status !== PayoutStatus::Pending) {
                return null;
            }

            $locked->update(['status' => PayoutStatus::Processing]);

            return $locked;
        });

        if (! $claimed) {
            return;
        }

        try {
            $result = $this->paystack->initiateTransfer(
                $claimed->netAmount(),
                (string) $claimed->recipient_code,
                $claimed->paystack_reference,
                'Userll payout',
            );
        } catch (PaystackException) {
            return; // we cannot tell whether Paystack got it: it stays "processing" and reconcile() asks Paystack
        }

        if (! $result['accepted']) {
            // Paystack said no. Before giving the money back, make sure it truly never created this
            // transfer (a duplicate-reference refusal would mean it did).
            try {
                $known = $this->paystack->verifyTransfer($claimed->paystack_reference);
            } catch (PaystackException) {
                return;
            }

            if ($known !== null) {
                $this->applyKnownTransfer($claimed, $known);

                return;
            }

            Log::warning('Paystack refused a payout; returning the money to the seller', [
                'payout_id' => $claimed->id,
                'message' => $result['message'],
            ]);

            $this->applyTransferStatus($claimed, PayoutStatus::Failed, null, 'Paystack refused the transfer: '.Str::limit($result['message'], 200));

            return;
        }

        if ($result['status'] === 'otp') {
            Log::warning('Paystack is waiting for OTP approval of a payout. Turn off the transfer OTP in the Paystack dashboard.', [
                'payout_id' => $claimed->id,
            ]);
        }

        $this->applyTransferStatus(
            $claimed,
            PayoutStatus::fromPaystack($result['status']) ?? PayoutStatus::Processing,
            $result['transfer_code'],
        );
    }

    // ------------------------------------------------------------------ outcomes (webhooks, the job and reconcile all end here)

    /**
     * Move a payout to the status Paystack reports. Safe to call twice and in any order: a finished
     * payout never goes backwards, and money is returned to the seller at most once.
     */
    public function applyTransferStatus(Payout $payout, PayoutStatus $next, ?string $transferCode = null, ?string $reason = null): Payout
    {
        return DB::transaction(function () use ($payout, $next, $transferCode, $reason) {
            $locked = Payout::whereKey($payout->id)->lockForUpdate()->firstOrFail();

            return $this->transition($locked, $next, $transferCode, $reason);
        });
    }

    /** Ask Paystack what became of a payout nobody has confirmed, and apply the answer. */
    public function reconcile(Payout $payout): void
    {
        $payout = Payout::find($payout->id);

        if (! $payout || ! $payout->status->isOpen()) {
            return;
        }

        try {
            $transfer = $this->paystack->verifyTransfer($payout->paystack_reference);
        } catch (PaystackException) {
            return; // Paystack is unreachable right now: the next run tries again
        }

        if ($transfer === null) {
            // Paystack has never heard of this payout: the request never reached it.
            $this->failIfUntouched($payout);

            return;
        }

        $this->applyKnownTransfer($payout, $transfer);
    }

    /** Reconcile every open payout that has had no news for a while. @return int how many were checked */
    public function reconcileStale(): int
    {
        $cutoff = now()->subMinutes((int) config('marketplace.payout_reconcile_after_minutes', 10));

        $stale = Payout::whereIn('status', [PayoutStatus::Pending->value, PayoutStatus::Processing->value])
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('id')
            ->limit(100)
            ->get();

        foreach ($stale as $payout) {
            $this->reconcile($payout);
        }

        return $stale->count();
    }

        /**
     * A webhook says something happened to this payout. Ask Paystack for the facts and apply them.
     * A Paystack outage is not caught here: the webhook then answers 500 and Paystack sends the event again.
     */
    public function refresh(Payout $payout): void
    {
        $payout = Payout::find($payout->id);

        if (! $payout || $payout->status->returnsMoney()) {
            return; // already finished and returned to the seller: nothing can change
        }

        $transfer = $this->paystack->verifyTransfer($payout->paystack_reference);

        if ($transfer === null) {
            Log::warning('A transfer webhook arrived for a payout Paystack does not know', ['payout_id' => $payout->id]);

            return;
        }

        $this->applyKnownTransfer($payout, $transfer);
    }

    // ------------------------------------------------------------------ internals

    /** Apply what Paystack's transfer record says. @param array<string, mixed> $transfer */
    private function applyKnownTransfer(Payout $payout, array $transfer): void
    {
        $status = PayoutStatus::fromPaystack($transfer['status'] ?? null);

        if ($status === null) {
            Log::warning('Paystack reported a transfer status we do not recognise', [
                'payout_id' => $payout->id,
                'status' => $transfer['status'] ?? null,
            ]);

            return;
        }

        if ($status === PayoutStatus::Processing && $payout->created_at->lt(now()->subDay())) {
            Log::warning('A payout has been processing for more than a day', ['payout_id' => $payout->id]);
        }

        $reason = $status->returnsMoney() ? 'Paystack reported the transfer as '.$status->value.'.' : null;

        $this->applyTransferStatus($payout, $status, $transfer['transfer_code'] ?? null, $reason);
    }

    /**
     * Paystack has never seen this payout. Fail it and give the money back, but only if nothing has
     * touched it since we looked: a payout that is being sent at this very moment must not be failed.
     */
    private function failIfUntouched(Payout $seen): void
    {
        DB::transaction(function () use ($seen) {
            $locked = Payout::whereKey($seen->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== $seen->status || ! $locked->updated_at->equalTo($seen->updated_at)) {
                return;
            }

            $this->transition($locked, PayoutStatus::Failed, null, 'Paystack never received this payout.');
        });
    }

    /** The one place a locked payout changes status. Call inside a transaction, with the row locked. */
    private function transition(Payout $locked, PayoutStatus $next, ?string $transferCode, ?string $reason): Payout
    {
        if ($locked->status === $next) {
            if ($transferCode && ! $locked->paystack_transfer_code) {
                $locked->update(['paystack_transfer_code' => $transferCode]);
            }

            return $locked;
        }

        if (! $locked->status->canTransitionTo($next)) {
            Log::warning('Ignored a payout status change that would go backwards', [
                'payout_id' => $locked->id,
                'from' => $locked->status->value,
                'to' => $next->value,
            ]);

            return $locked;
        }

        $changes = ['status' => $next];

        if ($transferCode && ! $locked->paystack_transfer_code) {
            $changes['paystack_transfer_code'] = $transferCode;
        }

        if ($next === PayoutStatus::Paid) {
            $changes['processed_at'] = now();
        }

        if ($next->returnsMoney()) {
            $changes['failure_reason'] = $reason ?? 'The transfer did not complete.';
        }

        $locked->update($changes);

        if ($next->returnsMoney()) {
            $this->ledger->returnPayout($locked);
            $this->notifier->payoutReturned($locked);
        } elseif ($next === PayoutStatus::Paid) {
            $this->notifier->payoutPaid($locked);
        }

        return $locked;
    }
}