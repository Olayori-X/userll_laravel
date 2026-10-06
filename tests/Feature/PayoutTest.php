<?php

namespace Tests\Feature;

use App\Enums\LedgerBucket;
use App\Enums\LedgerDirection;
use App\Enums\LedgerType;
use App\Enums\PayoutStatus;
use App\Models\LedgerEntry;
use App\Models\Payout;
use App\Models\PayoutAccount;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\PayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\MakesMarketplaceData;
use Tests\Concerns\PayoutTestHelpers;
use Tests\TestCase;

class PayoutTest extends TestCase
{
    use MakesMarketplaceData, PayoutTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakePaystackTransfers();
    }

    private function requestPayout(User $seller, int $amount)
    {
        return $this->actingAs($seller, 'sanctum')->postJson('/api/v1/seller/payouts', ['amount' => $amount]);
    }

    /** A withdrawal that must be refused: it names the problem, takes no money and sends nothing to Paystack. */
    private function assertRefused(User $seller, int $amount, string $errorKey): void
    {
        $before = $this->availableOf($seller);

        $this->requestPayout($seller, $amount)->assertUnprocessable()->assertJsonValidationErrors($errorKey);

        $this->assertSame(0, Payout::where('seller_id', $seller->id)->count());
        $this->assertSame($before, $this->availableOf($seller));
        $this->assertSame([], $this->sentTransfers);
    }

    // ---------------------------------------------------------------- the fee

    public function test_fee_follows_paystacks_tiers_and_adds_stamp_duty_from_10k_naira(): void
    {
        $payouts = app(PayoutService::class);

        $this->assertSame(1_000, $payouts->fee(100_000));      // ₦1,000: ₦10
        $this->assertSame(1_000, $payouts->fee(500_000));      // ₦5,000: still ₦10
        $this->assertSame(2_500, $payouts->fee(500_001));      // just over ₦5,000: ₦25
        $this->assertSame(2_500, $payouts->fee(999_999));      // just under ₦10,000: no stamp duty
        $this->assertSame(7_500, $payouts->fee(1_000_000));    // ₦10,000: ₦25 + ₦50 stamp duty
        $this->assertSame(7_500, $payouts->fee(5_000_000));    // ₦50,000: ₦25 + ₦50
        $this->assertSame(10_000, $payouts->fee(5_000_001));   // over ₦50,000: ₦50 + ₦50

        $this->assertSame(['amount' => 100_000, 'fee' => 1_000, 'net' => 99_000], $payouts->quote(100_000));
    }

    public function test_seller_can_ask_what_a_withdrawal_would_cost(): void
    {
        $seller = $this->makePayoutReadySeller();

        $this->actingAs($seller, 'sanctum')->getJson('/api/v1/seller/payouts/quote?amount=2000000')
            ->assertOk()
            ->assertJsonPath('data.amount', 2_000_000)
            ->assertJsonPath('data.fee', 7_500)
            ->assertJsonPath('data.net', 1_992_500)
            ->assertJsonPath('data.min_payout', 100_000);

        $this->actingAs($seller, 'sanctum')->getJson('/api/v1/seller/payouts/quote')->assertUnprocessable();
        $this->actingAs($seller, 'sanctum')->getJson('/api/v1/seller/payouts/quote?amount=0')->assertUnprocessable();
    }

    // ---------------------------------------------------------------- who can use it

    public function test_only_sellers_withdraw_and_only_admins_see_all_payouts(): void
    {
        $this->getJson('/api/v1/seller/wallet')->assertUnauthorized();

        $buyer = User::factory()->create();
        $this->actingAs($buyer, 'sanctum')->getJson('/api/v1/seller/wallet')->assertForbidden();
        $this->requestPayout($buyer, 1_000_000)->assertForbidden();

        $seller = $this->makePayoutReadySeller();
        $this->actingAs($seller, 'sanctum')->getJson('/api/v1/admin/payouts')->assertForbidden();
    }

    // ---------------------------------------------------------------- a successful request

    public function test_a_withdrawal_reserves_the_money_and_sends_the_net_amount_to_the_snapshotted_account(): void
    {
        $seller = $this->makePayoutReadySeller(5_000_000);

        $response = $this->requestPayout($seller, 2_000_000)
            ->assertCreated()
            ->assertJsonPath('data.amount', 2_000_000)
            ->assertJsonPath('data.fee', 7_500)
            ->assertJsonPath('data.net_amount', 1_992_500)
            ->assertJsonPath('data.status', 'pending') // the state when the money was reserved; the job moves it on
            ->assertJsonPath('data.bank_name', 'Zenith Bank')
            ->assertJsonPath('data.account_number', '******6789');

        $payout = Payout::firstOrFail();

        // The seller's balance dropped by the full amount straight away.
        $this->assertSame(3_000_000, $this->availableOf($seller));

        // One ledger row records it.
        $entry = LedgerEntry::where('payout_id', $payout->id)->sole();
        $this->assertSame(LedgerType::Payout, $entry->type);
        $this->assertSame(LedgerDirection::Debit, $entry->direction);
        $this->assertSame(LedgerBucket::Available, $entry->bucket);
        $this->assertSame(2_000_000, $entry->amount);

        // Paystack was asked for the amount minus the fee, to the recipient saved at request time.
        $this->assertCount(1, $this->sentTransfers);
        $sent = $this->sentTransfers[0];
        $this->assertSame(1_992_500, $sent['amount']);
        $this->assertSame($this->recipientCodeFor($seller), $sent['recipient']);
        $this->assertSame('balance', $sent['source']);
        $this->assertSame('NGN', $sent['currency']);
        $this->assertSame($payout->paystack_reference, $sent['reference']);
        $this->assertMatchesRegularExpression('/^payout-[a-z0-9]{26}$/', $payout->paystack_reference);

        // The queue ran the job: Paystack accepted it, so the payout is now processing.
        $payout->refresh();
        $this->assertSame(PayoutStatus::Processing, $payout->status);
        $this->assertNotNull($payout->paystack_transfer_code);
        $this->assertSame($this->recipientCodeFor($seller), $payout->recipient_code);
        $this->assertSame('6789', $payout->account_last4);

        // Nothing internal reaches the seller.
        $body = $response->getContent();
        $this->assertStringNotContainsString($this->recipientCodeFor($seller), $body);
        $this->assertStringNotContainsString('0123456789', $body);
        $this->assertStringNotContainsString($payout->paystack_reference, $body);
    }

    public function test_withdrawing_the_whole_balance_is_allowed(): void
    {
        $seller = $this->makePayoutReadySeller(300_000);

        $this->requestPayout($seller, 300_000)->assertCreated()->assertJsonPath('data.net_amount', 299_000);

        $this->assertSame(0, $this->availableOf($seller));
    }

    // ---------------------------------------------------------------- things that must be refused

    public function test_an_unverified_seller_cannot_withdraw(): void
    {
        $seller = $this->makePayoutReadySeller();
        SellerProfile::where('user_id', $seller->id)->update(['kyc_status' => 'pending']);

        $this->assertRefused($seller, 1_000_000, 'kyc');
    }

    public function test_a_seller_without_a_verified_bank_account_cannot_withdraw(): void
    {
        $noAccount = $this->makePayoutReadySeller();
        PayoutAccount::where('user_id', $noAccount->id)->delete();
        $this->assertRefused($noAccount, 1_000_000, 'payout_account');

        $unverified = $this->makePayoutReadySeller();
        PayoutAccount::where('user_id', $unverified->id)->update(['verified_at' => null]);
        $this->assertRefused($unverified, 1_000_000, 'payout_account');
    }

    public function test_a_freshly_changed_bank_account_holds_withdrawals(): void
    {
        $seller = $this->makePayoutReadySeller();
        PayoutAccount::where('user_id', $seller->id)->update(['verified_at' => now()]);

        $this->assertRefused($seller, 1_000_000, 'payout_account');

        $this->travel(25)->hours();

        $this->requestPayout($seller, 1_000_000)->assertCreated();

        $this->travelBack();
    }

    public function test_the_amount_must_fit_the_rules(): void
    {
        $seller = $this->makePayoutReadySeller(5_000_000);

        $this->assertRefused($seller, 99_999, 'amount');     // below the minimum
        $this->assertRefused($seller, 5_000_001, 'amount');  // more than the available balance

        $rich = $this->makePayoutReadySeller(2_000_000_000);
        $this->assertRefused($rich, 1_000_000_001, 'amount'); // above Paystack's single-transfer limit
    }

    public function test_pending_escrow_money_cannot_be_withdrawn(): void
    {
        $seller = $this->makePayoutReadySeller(500_000);
        $buyer = User::factory()->create();
        $this->makeEscrowedOrder($buyer, $seller, 10_000_000); // lands in the pending bucket

        $this->assertRefused($seller, 1_000_000, 'amount');
    }

    public function test_only_one_withdrawal_can_be_in_progress_at_a_time(): void
    {
        $seller = $this->makePayoutReadySeller(5_000_000);

        $this->requestPayout($seller, 1_000_000)->assertCreated();

        $this->requestPayout($seller, 1_000_000)->assertUnprocessable()->assertJsonValidationErrors('payout');

        $this->assertSame(1, Payout::count());
        $this->assertCount(1, $this->sentTransfers);
        $this->assertSame(4_000_000, $this->availableOf($seller));
    }

    // ---------------------------------------------------------------- what the seller sees

    public function test_the_wallet_summary_explains_what_blocks_a_withdrawal(): void
    {
        $newSeller = $this->makeSeller();

        $response = $this->actingAs($newSeller, 'sanctum')->getJson('/api/v1/seller/wallet')
            ->assertOk()
            ->assertJsonPath('data.can_withdraw', false)
            ->assertJsonPath('data.payout_account', null);

        $this->assertSame(['kyc', 'payout_account', 'balance_too_low'], $response->json('data.blockers.*.code'));
    }

    public function test_the_wallet_summary_for_a_ready_seller_and_one_with_a_withdrawal_in_progress(): void
    {
        $seller = $this->makePayoutReadySeller(5_000_000);

        $this->actingAs($seller, 'sanctum')->getJson('/api/v1/seller/wallet')
            ->assertOk()
            ->assertJsonPath('data.available_balance', 5_000_000)
            ->assertJsonPath('data.min_payout', 100_000)
            ->assertJsonPath('data.can_withdraw', true)
            ->assertJsonPath('data.blockers', [])
            ->assertJsonPath('data.payout_account.account_number', '******6789')
            ->assertJsonPath('data.open_payout', null);

        $this->requestPayout($seller, 1_000_000)->assertCreated();

        $response = $this->actingAs($seller, 'sanctum')->getJson('/api/v1/seller/wallet')
            ->assertOk()
            ->assertJsonPath('data.available_balance', 4_000_000)
            ->assertJsonPath('data.can_withdraw', false)
            ->assertJsonPath('data.open_payout.status', 'processing');

        $this->assertSame(['payout_in_progress'], $response->json('data.blockers.*.code'));
    }

    public function test_a_seller_sees_only_their_own_payouts_and_never_paystacks_error_text(): void
    {
        $mine = $this->makePayoutReadySeller();
        $other = $this->makePayoutReadySeller();

        $this->requestPayout($mine, 1_000_000)->assertCreated();
        $this->requestPayout($other, 1_000_000)->assertCreated();

        $myPayout = Payout::where('seller_id', $mine->id)->sole();
        $myPayout->update(['status' => 'failed', 'failure_reason' => 'Paystack refused: Your balance is not enough']);

        $response = $this->actingAs($mine, 'sanctum')->getJson('/api/v1/seller/payouts')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $myPayout->id)
            ->assertJsonPath('data.0.status', 'failed')
            ->assertJsonMissingPath('data.0.failure_reason')
            ->assertJsonMissingPath('data.0.paystack_reference');

        $this->assertStringNotContainsString('balance is not enough', $response->getContent());
        $this->assertStringContainsString('back in your available balance', $response->json('data.0.message'));
    }

    // ---------------------------------------------------------------- the admin list

    public function test_admin_sees_all_payouts_with_filters_and_paystacks_references(): void
    {
        $admin = $this->makeAdmin();
        $first = $this->makePayoutReadySeller();
        $second = $this->makePayoutReadySeller();

        $this->requestPayout($first, 1_000_000)->assertCreated();
        $this->requestPayout($second, 1_500_000)->assertCreated();

        $failed = Payout::where('seller_id', $second->id)->sole();
        $failed->update(['status' => 'failed', 'failure_reason' => 'Paystack refused: Your balance is not enough']);

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/payouts')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/payouts?status=failed')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $failed->id)
            ->assertJsonPath('data.0.failure_reason', 'Paystack refused: Your balance is not enough')
            ->assertJsonPath('data.0.paystack_reference', $failed->paystack_reference);

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/payouts?seller_id='.$first->id)
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/payouts?reference='.$failed->paystack_reference)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $failed->id);

        $this->actingAs($admin, 'sanctum')->getJson("/api/v1/admin/payouts/{$failed->id}")
            ->assertOk()
            ->assertJsonPath('data.seller.id', $second->id)
            ->assertJsonPath('data.net_amount', 1_500_000 - 7_500);

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/payouts?status=nonsense')->assertUnprocessable();
    }
}