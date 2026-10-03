<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\CheckoutResource;
use App\Models\Checkout;
use App\Services\PaymentService;
use App\Services\PaystackException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    /** Start paying for a checkout. The frontend sends the buyer to the returned authorization_url. */
    public function pay(Request $request, string $reference, PaymentService $payments): JsonResponse
    {
        $checkout = $this->mine($request, $reference);

        try {
            $data = $payments->initialize($checkout, $request->user());
        } catch (PaystackException) {
            return response()->json(['message' => 'The payment provider is unavailable. Please try again in a moment.'], 502);
        }

        return response()->json(['data' => $data]);
    }

    /**
     * Called by the frontend when the buyer returns from Paystack. It asks Paystack directly what happened
     * (the browser is never trusted) and covers the case where the webhook is slow.
     */
    public function verify(Request $request, string $reference, PaymentService $payments): CheckoutResource|JsonResponse
    {
        $checkout = $this->mine($request, $reference);

        try {
            foreach ($checkout->payments()->where('status', PaymentStatus::Pending->value)->get() as $payment) {
                $payments->verifyWithGateway($payment);
            }
        } catch (PaystackException) {
            return response()->json(['message' => 'Could not check the payment right now. Please try again in a moment.'], 502);
        }

        return new CheckoutResource($checkout->fresh()->load('orders.items', 'orders.seller.sellerProfile'));
    }

    private function mine(Request $request, string $reference): Checkout
    {
        return Checkout::where('buyer_id', $request->user()->id)->where('reference', $reference)->firstOrFail();
    }
}
