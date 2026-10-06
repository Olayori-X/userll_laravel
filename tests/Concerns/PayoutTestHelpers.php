<?php

namespace Tests\Concerns;

use App\Enums\KycStatus;
use App\Enums\LedgerBucket;
use App\Enums\LedgerDirection;
use App\Enums\LedgerType;
use App\Models\PayoutAccount;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Support\Facades\Http;

/** Needs MakesMarketplaceData in the same test class (for makeSeller). */
trait PayoutTestHelpers
{
    /** What Paystack does with a new transfer: 'accept', 'refuse' (a 4xx answer) or 'down' (a 500). */
    protected string $transferMode = 'accept';

    /** The status Paystack gives a transfer it accepts: pending, success or otp. */
    protected string $transferStatusOnCreate = 'pending';

    /** Makes the verify-by-reference call fail with a 500. */
    protected bool $verifyDown = false;

    /** @var array<string, array{status: string, transfer_code: string}> transfers Paystack "knows", by reference */
    protected array $paystackTransfers = [];

    /** @var list<array<string, mixed>> the bodies of every transfer request we sent */
    protected array $sentTransfers = [];

    protected function fakePaystackTransfers(): void
    {
        config(['marketplace.paystack.secret_key' => 'sk_test_secret']);

        Http::fake(function ($request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            // GET /transfer/verify/{reference}
            if (str_contains($path, '/transfer/verify/')) {
                if ($this->verifyDown) {
                    return Http::response(['status' => false, 'message' => 'Server error'], 500);
                }

                $reference = rawurldecode(substr($path, strrpos($path, '/') + 1));
                $known = $this->paystackTransfers[$reference] ?? null;

                if (! $known) {
                    return Http::response(['status' => false, 'message' => 'Transfer not found'], 404);
                }

                return Http::response([
                    'status' => true,
                    'message' => 'Transfer retrieved',
                    'data' => ['reference' => $reference, 'transfer_code' => $known['transfer_code'], 'status' => $known['status']],
                ]);
            }

            // POST /transfer
            if (str_ends_with($path, '/transfer') && $request->method() === 'POST') {
                $this->sentTransfers[] = $request->data();

                if ($this->transferMode === 'down') {
                    return Http::response(['status' => false, 'message' => 'Server error'], 500);
                }

                if ($this->transferMode === 'refuse') {
                    return Http::response(['status' => false, 'message' => 'Your balance is not enough to fulfil this request'], 400);
                }

                $reference = $request['reference'];
                $code = 'TRF_'.substr(md5($reference), 0, 10);
                $this->paystackTransfers[$reference] = ['status' => $this->transferStatusOnCreate, 'transfer_code' => $code];

                return Http::response([
                    'status' => true,
                    'message' => 'Transfer has been queued',
                    'data' => [
                        'reference' => $reference,
                        'transfer_code' => $code,
                        'status' => $this->transferStatusOnCreate,
                        'amount' => $request['amount'],
                    ],
                ]);
            }

            return Http::response([], 404);
        });
    }

    /** A seller who can withdraw: identity verified, bank account verified two days ago, and some available balance. */
    protected function makePayoutReadySeller(int $available = 5_000_000): User
    {
        $seller = $this->makeSeller();

        $seller->sellerProfile->forceFill(['kyc_status' => KycStatus::Verified, 'kyc_verified_at' => now()])->save();

        $account = PayoutAccount::create([
            'user_id' => $seller->id,
            'bank_code' => '057',
            'bank_name' => 'Zenith Bank',
            'account_number' => '0123456789',
            'account_name' => 'ADA OBI',
        ]);
        $account->forceFill(['paystack_recipient_code' => $this->recipientCodeFor($seller), 'verified_at' => now()->subDays(2)])->save();

        $this->giveAvailable($seller, $available);

        return $seller->fresh();
    }

    protected function giveAvailable(User $seller, int $amount): void
    {
        $ledger = app(LedgerService::class);

        $ledger->post(
            $ledger->walletForSeller($seller->id),
            LedgerType::Release, LedgerDirection::Credit, LedgerBucket::Available,
            $amount,
        );
    }

    protected function availableOf(User $seller): int
    {
        return app(LedgerService::class)->walletForSeller($seller->id)->fresh()->available_balance;
    }

    /** The Paystack recipient code the helper gives a seller's bank account. Unique per seller, like the real thing. */
    protected function recipientCodeFor(User $seller): string
    {
        return 'RCP_test'.$seller->id;
    }
}