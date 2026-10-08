<?php

namespace Tests\Feature;

use App\Enums\RefundStatus;
use App\Models\Refund;
use App\Models\User;
use App\Services\RefundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\MakesMarketplaceData;
use Tests\TestCase;

/** The refund job and Paystack's webhooks can overlap. A finished refund must never be pushed backwards. */
class RefundRaceTest extends TestCase
{
    use MakesMarketplaceData, RefreshDatabase;

    /**
     * Paystack answers the refund request with $answer. If $webhookFirst is true, the "processed" webhook has
     * already been applied by the time that answer reaches us (it arrived while the request was in flight).
     */
    private function fakeRefundAnswer(string $answer, bool $webhookFirst): void
    {
        config(['marketplace.paystack.secret_key' => 'sk_test_secret']);

        Http::fake(function ($request) use ($answer, $webhookFirst) {
            if (! str_ends_with($request->url(), '/refund')) {
                return Http::response([], 404);
            }

            if ($webhookFirst) {
                Refund::query()->update(['status' => RefundStatus::Processed->value]);
            }

            return Http::response([
                'status' => true,
                'message' => 'Refund has been queued for processing',
                'data' => ['id' => 3018284, 'status' => $answer, 'amount' => $request['amount'], 'currency' => 'NGN'],
            ]);
        });
    }

    private function refundAnOrder(): Refund
    {
        $order = $this->makeEscrowedOrder(User::factory()->create(), $this->makeSeller());

        return app(RefundService::class)->refundOrder($order, 'buyer_cancelled', restock: false)->fresh();
    }

    public function test_a_processed_webhook_that_lands_mid_request_is_not_overwritten(): void
    {
        $this->fakeRefundAnswer('processing', webhookFirst: true);

        $refund = $this->refundAnOrder();

        $this->assertSame(RefundStatus::Processed, $refund->status);
        $this->assertSame('3018284', $refund->paystack_refund_id); // Paystack's id is still recorded
    }

    public function test_without_a_webhook_the_status_follows_paystacks_answer(): void
    {
        $this->fakeRefundAnswer('processing', webhookFirst: false);

        $refund = $this->refundAnOrder();

        $this->assertSame(RefundStatus::Processing, $refund->status);
        $this->assertSame('3018284', $refund->paystack_refund_id);
    }

    public function test_a_refund_paystack_has_only_queued_stays_pending(): void
    {
        $this->fakeRefundAnswer('pending', webhookFirst: false);

        $refund = $this->refundAnOrder();

        $this->assertSame(RefundStatus::Pending, $refund->status);
        $this->assertSame('3018284', $refund->paystack_refund_id);
    }
}