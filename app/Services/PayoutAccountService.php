<?php

namespace App\Services;

use App\Models\PayoutAccount;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class PayoutAccountService
{
    public const BANKS_CACHE_KEY = 'paystack.banks';

    public function __construct(private PaystackClient $paystack, private KycService $kyc)
    {
    }

    /**
     * Banks a seller can choose from. Cached for a day because the list barely changes.
     * An empty answer is never cached, so one bad Paystack response does not stick for 24 hours.
     *
     * @return list<array{name: string, code: string}>
     */
    public function banks(): array
    {
        $cached = Cache::get(self::BANKS_CACHE_KEY);

        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        $banks = $this->paystack->banks();

        if ($banks !== []) {
            Cache::put(self::BANKS_CACHE_KEY, $banks, now()->addDay());
        }

        return $banks;
    }

    /**
     * Save (or replace) the seller's payout account. Paystack confirms the account exists and tells us
     * its real name, then we register it as a transfer recipient. Throws ValidationException for
     * mistakes the seller can fix, and PaystackException when Paystack itself is failing.
     */
    public function save(User $seller, string $bankCode, string $accountNumber): PayoutAccount
    {
        $bank = collect($this->banks())->firstWhere('code', $bankCode);

        if (! $bank) {
            throw ValidationException::withMessages(['bank_code' => 'Choose a bank from the list.']);
        }

        $resolved = $this->paystack->resolveAccount($accountNumber, $bankCode);

        if (! $resolved) {
            throw ValidationException::withMessages([
                'account_number' => 'We could not find that account number at that bank. Check it and try again.',
            ]);
        }

        // The account must be in the seller's own name, as given in their identity check.
        // A seller with no identity submission yet is not blocked here: the check runs the
        // other way when they submit one.
        $legalName = $this->kyc->currentLegalName($seller->id);

        if ($legalName !== null && $this->kyc->compareNames($legalName, $resolved['account_name']) === 'mismatch') {
            throw ValidationException::withMessages([
                'account_number' => 'This account is not in your name. Use a bank account that matches the legal name on your identity documents.',
            ]);
        }

        $recipient = $this->paystack->createRecipient($resolved['account_name'], $accountNumber, $bankCode);

        try {
            $account = PayoutAccount::firstOrNew(['user_id' => $seller->id]);

            $account->fill([
                'bank_code' => $bankCode,
                'bank_name' => $bank['name'],
                'account_number' => $accountNumber,
                'account_name' => $resolved['account_name'], // Paystack's answer, never the seller's input
            ]);

            // Not fillable on purpose: only this service may mark an account verified.
            $account->forceFill([
                'paystack_recipient_code' => $recipient['recipient_code'],
                'verified_at' => now(),
            ])->save();
        } catch (UniqueConstraintViolationException) {
            // Paystack gives the same recipient for the same bank account, so this means another seller already uses it.
            throw ValidationException::withMessages([
                'account_number' => 'This bank account is already linked to another seller.',
            ]);
        }

        return $account;
    }

    /**
     * After an account is added or changed, payouts wait for a short period. If someone breaks into a
     * seller's login and swaps the bank account, this gives the real owner time to notice.
     * Returns the moment payouts open up again, or null when there is no hold.
     */
    public function heldUntil(PayoutAccount $account): ?CarbonInterface
    {
        $hours = (int) config('marketplace.payout_account_hold_hours', 24);

        if ($hours <= 0 || $account->verified_at === null) {
            return null;
        }

        $until = $account->verified_at->copy()->addHours($hours);

        return $until->isFuture() ? $until : null;
    }
}