<?php

namespace Tests\Feature;

use App\Models\PayoutAccount;
use App\Models\Wallet;
use App\Services\LedgerService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesMarketplaceData;
use Tests\Concerns\PayoutTestHelpers;
use Tests\TestCase;

/** The platform wallet is unique, the login limiter watches whole addresses, and bank numbers are encrypted. */
class HardeningFixesTest extends TestCase
{
    use MakesMarketplaceData, PayoutTestHelpers, RefreshDatabase;

    // ---------------------------------------------------------------- one platform wallet

    public function test_there_is_exactly_one_platform_wallet_and_the_ledger_reuses_it(): void
    {
        $this->assertSame(1, Wallet::where('type', 'platform')->count());

        $ledger = app(LedgerService::class);
        $first = $ledger->platformWallet();
        $second = $ledger->platformWallet();

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Wallet::where('type', 'platform')->count());
    }

    public function test_a_second_platform_wallet_cannot_be_inserted(): void
    {
        $this->expectException(UniqueConstraintViolationException::class);

        Wallet::create(['type' => 'platform', 'singleton_key' => 'platform']);
    }

    public function test_many_seller_wallets_are_still_allowed(): void
    {
        $ledger = app(LedgerService::class);

        $ledger->walletForSeller($this->makeSeller()->id);
        $ledger->walletForSeller($this->makeSeller()->id);

        $this->assertSame(2, Wallet::where('type', 'seller')->count());
        $this->assertSame(1, Wallet::where('type', 'platform')->count());
    }

    public function test_losing_the_creation_race_returns_the_winners_wallet(): void
    {
        // No platform wallet yet. Right after our lookup finds nothing, another request creates it.
        Wallet::where('type', 'platform')->delete();

        $winnerId = null;
        DB::listen(function ($query) use (&$winnerId) {
            if ($winnerId === null && str_contains($query->sql, 'select') && str_contains($query->sql, 'from "wallets"')) {
                $winnerId = 0; // set first, so the insert below cannot trigger this listener again
                $winnerId = DB::table('wallets')->insertGetId([
                    'type' => 'platform', 'singleton_key' => 'platform',
                    'pending_balance' => 0, 'available_balance' => 0,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        $wallet = app(LedgerService::class)->platformWallet();

        $this->assertNotNull($winnerId);
        $this->assertSame($winnerId, $wallet->id);
        $this->assertSame(1, Wallet::where('type', 'platform')->count());
    }

    // ---------------------------------------------------------------- login limiter

    public function test_one_address_cannot_try_unlimited_different_accounts(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => "nobody{$i}@example.com", 'password' => 'Wrong-pass1'])
                ->assertStatus(401); // wrong credentials, but still allowed to try
        }

        $this->postJson('/api/v1/auth/login', ['email' => 'nobody31@example.com', 'password' => 'Wrong-pass1'])
            ->assertStatus(429);

        // A different address is not affected.
        $this->withServerVariables(['REMOTE_ADDR' => '10.20.30.40'])
            ->postJson('/api/v1/auth/login', ['email' => 'nobody32@example.com', 'password' => 'Wrong-pass1'])
            ->assertStatus(401);
    }

    // ---------------------------------------------------------------- encrypted account numbers

    public function test_the_account_number_is_encrypted_at_rest_but_reads_back_normally(): void
    {
        $seller = $this->makePayoutReadySeller();

        $raw = DB::table('payout_accounts')->where('user_id', $seller->id)->value('account_number');

        $this->assertStringNotContainsString('0123456789', $raw);
        $this->assertNotSame('0123456789', $raw);

        $account = PayoutAccount::where('user_id', $seller->id)->firstOrFail();
        $this->assertSame('0123456789', $account->account_number);
        $this->assertSame('******6789', $account->maskedNumber());
    }

    public function test_a_payout_still_records_the_last_four_digits(): void
    {
        $this->fakePaystackTransfers();
        $seller = $this->makePayoutReadySeller();

        $this->actingAs($seller, 'sanctum')
            ->postJson('/api/v1/seller/payouts', ['amount' => 1_000_000])
            ->assertCreated();

        $this->assertSame('6789', DB::table('payouts')->where('seller_id', $seller->id)->value('account_last4'));
    }
}