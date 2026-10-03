<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Http;

trait FakesPaystackRefunds
{
    /** 'ok' or 'down' (Paystack answers with a server error). */
    protected string $refundGateway = 'ok';

    protected function fakePaystackRefunds(): void
    {
        config(['marketplace.paystack.secret_key' => 'sk_test_secret']);

        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/refund')) {
                if ($this->refundGateway === 'down') {
                    return Http::response(['status' => false, 'message' => 'Server error'], 500);
                }

                return Http::response([
                    'status' => true,
                    'message' => 'Refund has been queued for processing',
                    'data' => ['id' => 3018284, 'status' => 'pending', 'amount' => $request['amount'], 'currency' => 'NGN'],
                ]);
            }

            return Http::response([], 404);
        });
    }
}
