<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\WebhookEvent;
use App\Services\PaymentService;
use App\Services\RefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * Paystack calls this when something happens to a payment. Anyone on the internet can call it,
 * so the signature is checked first, and money is only credited after we ask Paystack to confirm.
 */
class PaystackWebhookController extends Controller
{
    public function handle(Request $request, PaymentService $payments, RefundService $refunds): JsonResponse
    {
        $secret = config('marketplace.paystack.secret_key');
        $body = $request->getContent(); // the raw body: the signature is computed over exactly these bytes
        $signature = (string) $request->header('x-paystack-signature');

        if (! $secret || ! hash_equals(hash_hmac('sha512', $body, $secret), $signature)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $payload = json_decode($body, true);
        if (! is_array($payload) || ! isset($payload['event'])) {
            return response()->json(['message' => 'Bad payload.'], 400);
        }

        $event = (string) $payload['event'];
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        // Payment events carry "reference"; refund events carry the payment's "transaction_reference".
        $reference = (string) ($data['reference'] ?? $data['transaction_reference'] ?? '');
        $isRefund = str_starts_with($event, 'refund.');
        $refundReference = (string) ($data['refund_reference'] ?? '');

        // For refunds the key also holds the amount and Paystack's refund reference, so two refunds of
        // the same amount on one payment are not mistaken for one event delivered twice.
        $eventKey = $event.':'.$reference;
        if ($isRefund) {
            $eventKey .= ':'.($data['amount'] ?? '');
            if ($refundReference !== '') {
                $eventKey .= ':'.$refundReference;
            }
        }

        // Remember every event once (only safe fields: no card or customer details).
        $record = WebhookEvent::firstOrCreate(
            ['provider' => 'paystack', 'event_key' => $eventKey],
            ['event_type' => $event, 'payload' => ['event' => $event, 'data' => Arr::only($data, PaymentService::SAFE_FIELDS)]],
        );

        if ($record->processed_at === null) {
            // If this throws (e.g. Paystack unreachable) we answer 500 and Paystack sends the event again.
            if ($event === 'charge.success') {
                $payment = Payment::where('paystack_reference', $reference)->first();

                if ($payment) {
                    $payments->verifyWithGateway($payment); // confirm with Paystack; the webhook body is not trusted for amounts
                }
            }

            if ($isRefund) {
                $refunds->applyGatewayEvent($event, $reference, (int) ($data['amount'] ?? 0), $refundReference ?: null);
            }

            $record->update(['processed_at' => now()]);
        }

        return response()->json(['message' => 'ok']);
    }
}