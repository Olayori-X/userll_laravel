<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** Thin wrapper around the two Paystack calls we need for collecting money. Amounts are kobo. */
class PaystackClient
{
    /**
     * Start a payment. Returns Paystack's data: authorization_url, access_code, reference.
     * Not retried: a retry after a lost response would hit "duplicate reference".
     */
    public function initialize(string $email, int $amountKobo, string $reference, string $callbackUrl, array $metadata = []): array
    {
        $response = $this->http()->post('/transaction/initialize', [
            'email' => $email,
            'amount' => $amountKobo,
            'currency' => 'NGN',
            'reference' => $reference,
            'callback_url' => $callbackUrl,
            'metadata' => $metadata,
        ]);

        return $this->data($response, 'initialize');
    }

    /** Ask Paystack what really happened to a payment. This, not the browser, is the source of truth. */
    public function verify(string $reference): array
    {
        $response = $this->http()
            ->retry(2, 300, throw: false) // safe to retry: it only reads
            ->get('/transaction/verify/'.rawurlencode($reference));

        return $this->data($response, 'verify');
    }

    /**
     * Ask Paystack to send money back to the buyer (partial or full). Not retried automatically:
     * a retry after a lost response could refund the buyer twice.
     */
    public function refund(string $transactionReference, int $amountKobo, string $note): array
    {
        $response = $this->http()->post('/refund', [
            'transaction' => $transactionReference,
            'amount' => $amountKobo,
            'currency' => 'NGN',
            'customer_note' => $note,
            'merchant_note' => $note,
        ]);

        return $this->data($response, 'refund');
    }

    private function http(): PendingRequest
    {
        $key = config('marketplace.paystack.secret_key');

        if (! $key) {
            throw new PaystackException('Paystack is not configured (PAYSTACK_SECRET_KEY is empty).');
        }

        return Http::baseUrl(rtrim(config('marketplace.paystack.base_url'), '/'))
            ->withToken($key)
            ->acceptJson()
            ->timeout(15);
    }

    private function data(Response $response, string $action): array
    {
        if ($response->failed() || $response->json('status') !== true) {
            Log::error("Paystack {$action} failed", [
                'http_status' => $response->status(),
                'message' => $response->json('message'),
            ]);

            throw new PaystackException("Paystack {$action} failed.");
        }

        return $response->json('data') ?? [];
    }
}
