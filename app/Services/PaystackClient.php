<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** Thin wrapper around the Paystack calls we need: collecting money, refunds, banks and payout recipients. Amounts are kobo. */
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

    // ------------------------------------------------------------------ banks and payout recipients

    /**
     * Every active Nigerian bank Paystack can pay to, sorted by name.
     *
     * @return list<array{name: string, code: string}>
     */
    public function banks(): array
    {
        $banks = [];
        $next = null;

        // Paystack pages this list with a cursor. The loop limit is a safety net, not an expected size.
        for ($page = 0; $page < 10; $page++) {
            $query = ['country' => 'nigeria', 'currency' => 'NGN', 'perPage' => 100, 'use_cursor' => 'true'];

            if ($next) {
                $query['next'] = $next;
            }

            $response = $this->readOnly()->get('/bank', $query);

            foreach ($this->data($response, 'banks') as $bank) {
                if (($bank['active'] ?? true) === false || empty($bank['code']) || empty($bank['name'])) {
                    continue;
                }

                $banks[(string) $bank['code']] = ['name' => (string) $bank['name'], 'code' => (string) $bank['code']];
            }

            $next = $response->json('meta.next');

            if (! $next) {
                break;
            }
        }

        $banks = array_values($banks);
        usort($banks, fn (array $a, array $b) => strcasecmp($a['name'], $b['name']));

        return $banks;
    }

    /**
     * Look up the real account name for a bank account.
     * Returns null when the number does not exist at that bank (a user mistake).
     * Throws PaystackException when Paystack itself is unreachable or failing.
     *
     * @return array{account_number: string, account_name: string}|null
     */
    public function resolveAccount(string $accountNumber, string $bankCode): ?array
    {
        try {
            $response = $this->readOnly()->get('/bank/resolve', [
                'account_number' => $accountNumber,
                'bank_code' => $bankCode,
            ]);
        } catch (ConnectionException) {
            throw new PaystackException('Paystack resolve account failed.');
        }

        // Paystack answers 422 (sometimes 404) when the number does not belong to that bank.
        if (in_array($response->status(), [404, 422], true)) {
            return null;
        }

        $data = $this->data($response, 'resolve account');

        return isset($data['account_name']) ? $data : null;
    }

    /**
     * Register a bank account with Paystack as someone we can send money to.
     * Returns Paystack's data; the part we keep is recipient_code. Not retried automatically.
     */
    public function createRecipient(string $name, string $accountNumber, string $bankCode): array
    {
        $response = $this->http()->post('/transferrecipient', [
            'type' => 'nuban',
            'name' => $name,
            'account_number' => $accountNumber,
            'bank_code' => $bankCode,
            'currency' => 'NGN',
        ]);

        $data = $this->data($response, 'create recipient');

        if (empty($data['recipient_code'])) {
            throw new PaystackException('Paystack create recipient failed.');
        }

        return $data;
    }

    // ------------------------------------------------------------------ plumbing

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

    /** For calls that only read: retried on a connection failure or a Paystack 5xx, never on a 4xx answer. */
    private function readOnly(): PendingRequest
    {
        return $this->http()->retry(
            2,
            300,
            fn ($exception) => $exception instanceof ConnectionException
                || ($exception instanceof RequestException && $exception->response->serverError()),
            throw: false,
        );
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