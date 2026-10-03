<?php

namespace Tests\Feature;

use App\Enums\LedgerBucket;
use App\Enums\LedgerDirection;
use App\Enums\LedgerType;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class LedgerTest extends TestCase
{
    use RefreshDatabase;

    private function ledgerPost(LedgerService $ledger, $wallet, string $direction, string $bucket, int $amount)
    {
        return $ledger->post(
            $wallet,
            LedgerType::EscrowHold,
            LedgerDirection::from($direction),
            LedgerBucket::from($bucket),
            $amount,
        );
    }

    public function test_credits_and_debits_move_the_balance_and_record_balance_after(): void
    {
        $ledger = app(LedgerService::class);
        $wallet = $ledger->walletForSeller(User::factory()->create()->id);

        $first = $this->ledgerPost($ledger, $wallet, 'credit', 'pending', 1_000);
        $second = $this->ledgerPost($ledger, $wallet, 'debit', 'pending', 400);

        $this->assertSame(1_000, $first->balance_after);
        $this->assertSame(600, $second->balance_after);
        $this->assertSame(600, $wallet->fresh()->pending_balance);
        $this->assertSame(0, $wallet->fresh()->available_balance);
        $this->assertSame(2, LedgerEntry::count());
    }

    public function test_cannot_spend_more_than_the_balance(): void
    {
        $ledger = app(LedgerService::class);
        $wallet = $ledger->walletForSeller(User::factory()->create()->id);
        $this->ledgerPost($ledger, $wallet, 'credit', 'available', 500);

        try {
            $this->ledgerPost($ledger, $wallet, 'debit', 'available', 501);
            $this->fail('Expected an insufficient funds error.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(500, $wallet->fresh()->available_balance);
        $this->assertSame(1, LedgerEntry::count()); // the failed debit left no trace
    }

    public function test_amount_must_be_positive(): void
    {
        $ledger = app(LedgerService::class);
        $wallet = $ledger->walletForSeller(User::factory()->create()->id);

        $this->expectException(InvalidArgumentException::class);
        $this->ledgerPost($ledger, $wallet, 'credit', 'pending', 0);
    }

    public function test_ledger_rows_cannot_be_changed_or_deleted(): void
    {
        $ledger = app(LedgerService::class);
        $wallet = $ledger->walletForSeller(User::factory()->create()->id);
        $entry = $this->ledgerPost($ledger, $wallet, 'credit', 'pending', 1_000);

        try {
            $entry->update(['amount' => 1]);
            $this->fail('Updating a ledger entry must throw.');
        } catch (LogicException) {
            // expected
        }

        try {
            $entry->delete();
            $this->fail('Deleting a ledger entry must throw.');
        } catch (LogicException) {
            // expected
        }

        $this->assertSame(1_000, LedgerEntry::first()->amount);
    }

    public function test_wallets_are_created_once(): void
    {
        $ledger = app(LedgerService::class);
        $user = User::factory()->create();

        $this->assertSame($ledger->walletForSeller($user->id)->id, $ledger->walletForSeller($user->id)->id);
        $this->assertSame($ledger->platformWallet()->id, $ledger->platformWallet()->id);
    }
}
