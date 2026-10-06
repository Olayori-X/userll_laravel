<?php

namespace Tests\Feature;

use App\Enums\LedgerBucket;
use App\Enums\LedgerDirection;
use App\Enums\LedgerType;
use App\Enums\PayoutStatus;
use App\Models\LedgerEntry;
use App\Models\Payout;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\LedgerService;
use App\Services\PayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\MakesMarketplaceData;
use Tests\Concerns\PayoutTestHelpers;
use Tests\TestCase;

class PayoutOutcomeTest extends TestCase
{
    use MakesMarketplaceData, PayoutTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakePaystackTransfers();
    }

    /** The seller withdraws through the real endpoint. The queue is sync, so the transfer is sent before this returns. */
    private function withdraw(User $seller, int $amount = 1_000_000): Payout
    {
        $this->actingAs($seller, 'sanctum')->postJson('/api/v1/seller/payouts', ['amount' => $amount])->assertCreated();

        return Payout::where('seller_id', $seller->id)->latest('id')->firstOrFail();
    }

    private function sendTransferEvent(string $event, string $reference, string $secret = 'sk_test_secret')
    {
        $body = json_encode([
            'event' => $event,
            'data' => [
                'reference' => $reference,
                'status' => 'success',
                'amount' => 1_000_000,
                'currency' => 'NGN',
                'recipient' => ['name' => 'ADA OBI'],
            ],
        ]);

        return $this->call('POST', '/api/v1/webhooks/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, $secret),
        ], $body);
    }

    private function returnEntries(Payout $payout)
    {
        return LedgerEntry::where('payout_id', $payout->id)->where('type', LedgerType::PayoutReturn->value);
    }

    // ---------------------------------------------------------------- what Paystack says when we send

    public function test_a_transfer_paystack_completes_at_once_is_marked_paid(): void
    {
        $this->transferStatusOnCreate = 'success';
        $seller = $this->makePayoutReadySeller(5_000_000);

        $payout = $this->withdraw($seller);

        $this->assertSame(PayoutStatus::Paid, $payout->status);
        $this->assertNotNull($payout->processed_at);
        $this->assertNotNull($payout->paystack_transfer_code);
        $this->assertSame(4_000_000, $this->availableOf($seller)); // the money has left for good
        $this->assertSame(0, $this->returnEntries($payout)->count());
    }

    public function test_a_transfer_waiting_for_otp_stays_processing_and_keeps_the_money_reserved(): void
    {
        $this->transferStatusOnCreate = 'otp';
        $seller = $this->makePayoutReadySeller(5_000_000);

        $payout = $this->withdraw($seller);

        // It could still be approved in the dashboard, so giving the money back now could pay the seller twice.
        $this->assertSame(PayoutStatus::Processing, $payout->status);
        $this->assertSame(4_000_000, $this->availableOf($seller));
        $this->assertSame(0, $this->returnEntries($payout)->count());
    }

    public function test_a_transfer_paystack_refuses_is_failed_and_the_money_comes_back(): void
    {
        $this->transferMode = 'refuse';
        $seller = $this->makePayoutReadySeller(5_000_000);

        $payout = $this->withdraw($seller);

        $this->assertSame(PayoutStatus::Failed, $payout->status);
        $this->assertStringContainsString('balance is not enough', $payout->failure_reason); // kept for admins
        $this->assertCount(1, $this->sentTransfers);
        $this->assertSame(5_000_000, $this->availableOf($seller));

        $entry = $this->returnEntries($payout)->sole();
        $this->assertSame(LedgerDirection::Credit, $entry->direction);
        $this->assertSame(LedgerBucket::Available, $entry->bucket);
        $this->assertSame(1_000_000, $entry->amount);
        $this->assertSame(2, LedgerEntry::where('payout_id', $payout->id)->count()); // the reservation and its return

        // The seller is not blocked: they can simply ask again once Paystack accepts.
        $this->transferMode = 'accept';
        $this->withdraw($seller);
        $this->assertSame(4_000_000, $this->availableOf($seller));
    }

    public function test_a_refusal_for_a_transfer_paystack_already_holds_does_not_return_the_money(): void
    {
        $seller = $this->makePayoutReadySeller(5_000_000);

        // A payout whose request already reached Paystack (say the answer was lost), now refused as a duplicate.
        $payout = Payout::create([
            'seller_id' => $seller->id,
            'amount' => 1_000_000,
            'fee' => 7_500,
            'status' => PayoutStatus::Pending,
            'paystack_reference' => 'payout-'.Str::lower((string) Str::ulid()),
            'recipient_code' => $this->recipientCodeFor($seller),
            'bank_name' => 'Zenith Bank',
            'account_name' => 'ADA OBI',
            'account_last4' => '6789',
        ]);
        app(LedgerService::class)->reservePayout($payout);

        $this->paystackTransfers[$payout->paystack_reference] = ['status' => 'pending', 'transfer_code' => 'TRF_known'];
        $this->transferMode = 'refuse';

        app(PayoutService::class)->send($payout);

        $payout->refresh();
        $this->assertSame(PayoutStatus::Processing, $payout->status);
        $this->assertSame('TRF_known', $payout->paystack_transfer_code);
        $this->assertSame(4_000_000, $this->availableOf($seller)); // still reserved: the transfer exists
    }

    // ---------------------------------------------------------------- Paystack cannot be reached

    public function test_if_paystack_cannot_be_reached_the_money_stays_reserved_until_reconcile_settles_it(): void
    {
        $this->transferMode = 'down';
        $seller = $this->makePayoutReadySeller(5_000_000);
        $payouts = app(PayoutService::class);

        $payout = $this->withdraw($seller);

        // We cannot tell whether Paystack got it, so nothing is returned and nothing is sent twice.
        $this->assertSame(PayoutStatus::Processing, $payout->status);
        $this->assertSame(4_000_000, $this->availableOf($seller));
        $this->assertSame(0, $payouts->reconcileStale()); // too recent to be checked yet

        $this->travel(11)->minutes();

        // Still cannot reach Paystack: nothing changes.
        $this->verifyDown = true;
        $this->assertSame(1, $payouts->reconcileStale());
        $this->assertSame(PayoutStatus::Processing, $payout->fresh()->status);
        $this->assertSame(4_000_000, $this->availableOf($seller));

        // Paystack answers and has never heard of the payout: it never left, so the money goes back.
        $this->verifyDown = false;
        $this->assertSame(1, $payouts->reconcileStale());

        $payout->refresh();
        $this->assertSame(PayoutStatus::Failed, $payout->status);
        $this->assertStringContainsString('never received', $payout->failure_reason);
        $this->assertSame(5_000_000, $this->availableOf($seller));
        $this->assertSame(1, $this->returnEntries($payout)->count());

        $this->travelBack();
    }

    public function test_reconcile_applies_what_paystack_says_about_a_transfer_it_did_receive(): void
    {
        $this->transferMode = 'down'; // the answer to our request was lost...
        $seller = $this->makePayoutReadySeller(5_000_000);

        $payout = $this->withdraw($seller);
        $this->assertSame(PayoutStatus::Processing, $payout->status);

        // ...but Paystack did get it and has paid it.
        $this->paystackTransfers[$payout->paystack_reference] = ['status' => 'success', 'transfer_code' => 'TRF_lost_answer'];

        $this->travel(11)->minutes();
        app(PayoutService::class)->reconcileStale();
        $this->travelBack();

        $payout->refresh();
        $this->assertSame(PayoutStatus::Paid, $payout->status);
        $this->assertSame('TRF_lost_answer', $payout->paystack_transfer_code);
        $this->assertSame(4_000_000, $this->availableOf($seller));
    }

    public function test_the_reconcile_command_settles_stale_payouts(): void
    {
        $this->transferMode = 'down';
        $seller = $this->makePayoutReadySeller(5_000_000);
        $payout = $this->withdraw($seller);

        $this->artisan('marketplace:reconcile-payouts')->expectsOutput('Checked 0 payout(s).')->assertSuccessful();

        $this->travel(11)->minutes();
        $this->artisan('marketplace:reconcile-payouts')->expectsOutput('Checked 1 payout(s).')->assertSuccessful();
        $this->travelBack();

        $this->assertSame(PayoutStatus::Failed, $payout->fresh()->status);
        $this->assertSame(5_000_000, $this->availableOf($seller));
    }

    // ---------------------------------------------------------------- webhooks

    public function test_webhooks_settle_a_payout_as_paid_and_then_as_reversed(): void
    {
        $seller = $this->makePayoutReadySeller(5_000_000);
        $payout = $this->withdraw($seller);
        $reference = $payout->paystack_reference;

        $this->paystackTransfers[$reference]['status'] = 'success';
        $this->sendTransferEvent('transfer.success', $reference)->assertOk();

        $payout->refresh();
        $this->assertSame(PayoutStatus::Paid, $payout->status);
        $this->assertNotNull($payout->processed_at);
        $this->assertSame(4_000_000, $this->availableOf($seller));

        // Later the bank sends the money back.
        $this->paystackTransfers[$reference]['status'] = 'reversed';
        $this->sendTransferEvent('transfer.reversed', $reference)->assertOk();

        $payout->refresh();
        $this->assertSame(PayoutStatus::Reversed, $payout->status);
        $this->assertNotNull($payout->failure_reason);
        $this->assertSame(5_000_000, $this->availableOf($seller));
        $this->assertSame(1, $this->returnEntries($payout)->count());
    }

    public function test_a_failed_webhook_returns_the_money_exactly_once_even_if_delivered_twice(): void
    {
        $seller = $this->makePayoutReadySeller(5_000_000);
        $payout = $this->withdraw($seller);
        $reference = $payout->paystack_reference;

        $this->paystackTransfers[$reference]['status'] = 'failed';

        $this->sendTransferEvent('transfer.failed', $reference)->assertOk();
        $this->sendTransferEvent('transfer.failed', $reference)->assertOk(); // Paystack delivers it again

        $this->assertSame(PayoutStatus::Failed, $payout->fresh()->status);
        $this->assertSame(5_000_000, $this->availableOf($seller)); // not 6,000,000
        $this->assertSame(1, $this->returnEntries($payout)->count());
        $this->assertSame(1, WebhookEvent::where('event_type', 'transfer.failed')->count());
    }

    public function test_a_webhook_is_only_a_prompt_and_paystacks_own_record_decides(): void
    {
        $seller = $this->makePayoutReadySeller(5_000_000);
        $payout = $this->withdraw($seller);

        // The event claims success, but Paystack's record still says pending.
        $this->sendTransferEvent('transfer.success', $payout->paystack_reference)->assertOk();

        $this->assertSame(PayoutStatus::Processing, $payout->fresh()->status);
        $this->assertSame(4_000_000, $this->availableOf($seller));
    }

    public function test_webhook_with_a_bad_signature_or_an_unknown_payout_changes_nothing(): void
    {
        $seller = $this->makePayoutReadySeller(5_000_000);
        $payout = $this->withdraw($seller);
        $this->paystackTransfers[$payout->paystack_reference]['status'] = 'failed';

        $this->sendTransferEvent('transfer.failed', $payout->paystack_reference, 'sk_test_wrong')->assertUnauthorized();
        $this->assertSame(PayoutStatus::Processing, $payout->fresh()->status);
        $this->assertSame(0, WebhookEvent::count());

        $this->sendTransferEvent('transfer.failed', 'payout-nobody-knows-this-one')->assertOk();
        $this->assertSame(PayoutStatus::Processing, $payout->fresh()->status);
        $this->assertSame(4_000_000, $this->availableOf($seller));
    }

    public function test_a_webhook_is_retried_when_paystack_cannot_confirm_it(): void
    {
        $seller = $this->makePayoutReadySeller(5_000_000);
        $payout = $this->withdraw($seller);
        $reference = $payout->paystack_reference;
        $this->paystackTransfers[$reference]['status'] = 'success';

        $this->verifyDown = true;
        $this->sendTransferEvent('transfer.success', $reference)->assertStatus(500); // Paystack will send it again

        $this->assertNull(WebhookEvent::where('event_type', 'transfer.success')->firstOrFail()->processed_at);
        $this->assertSame(PayoutStatus::Processing, $payout->fresh()->status);

        $this->verifyDown = false;
        $this->sendTransferEvent('transfer.success', $reference)->assertOk();

        $this->assertSame(PayoutStatus::Paid, $payout->fresh()->status);
        $this->assertNotNull(WebhookEvent::where('event_type', 'transfer.success')->firstOrFail()->processed_at);
    }

    // ---------------------------------------------------------------- the rules that protect the money

    public function test_money_is_returned_at_most_once_and_a_returned_payout_never_comes_back_to_life(): void
    {
        $seller = $this->makePayoutReadySeller(5_000_000);
        $payout = $this->withdraw($seller);
        $payouts = app(PayoutService::class);

        $payouts->applyTransferStatus($payout, PayoutStatus::Failed, null, 'first');
        $payouts->applyTransferStatus($payout, PayoutStatus::Failed, null, 'again');
        $payouts->applyTransferStatus($payout, PayoutStatus::Reversed);   // from failed: not allowed
        $payouts->applyTransferStatus($payout, PayoutStatus::Paid);       // from failed: not allowed

        $this->assertSame(PayoutStatus::Failed, $payout->fresh()->status);
        $this->assertSame(5_000_000, $this->availableOf($seller));
        $this->assertSame(1, $this->returnEntries($payout)->count());
    }

        public function test_a_paid_payout_cannot_be_turned_into_a_failure(): void
    {
        $seller = $this->makePayoutReadySeller(5_000_000);
        $payout = $this->withdraw($seller);
        $code = $payout->paystack_transfer_code; // Paystack gave this when it accepted the transfer
        $payouts = app(PayoutService::class);

        $payouts->applyTransferStatus($payout, PayoutStatus::Paid, 'TRF_done'); // a different code must not replace the first
        $payouts->applyTransferStatus($payout, PayoutStatus::Failed, null, 'a stale, late message');

        $payout->refresh();
        $this->assertSame(PayoutStatus::Paid, $payout->status);
        $this->assertSame($code, $payout->paystack_transfer_code);
        $this->assertSame(4_000_000, $this->availableOf($seller)); // the money did leave: nothing was handed back
        $this->assertSame(0, $this->returnEntries($payout)->count());
    }
}