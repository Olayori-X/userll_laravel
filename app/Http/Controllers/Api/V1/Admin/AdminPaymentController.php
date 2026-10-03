<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminPaymentResource;
use App\Http\Resources\RefundResource;
use App\Models\Payment;
use App\Services\RefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class AdminPaymentController extends Controller
{
    /** Payments, by default the ones that need refunding. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['status' => ['sometimes', Rule::enum(PaymentStatus::class)]]);

        $status = $request->input('status', PaymentStatus::NeedsRefund->value);

        return AdminPaymentResource::collection(
            Payment::where('status', $status)->with('checkout')->latest('id')->paginate(30)->withQueryString()
        );
    }

    /** Send a "needs_refund" payment back to the buyer. */
    public function refund(int $payment, RefundService $refunds): JsonResponse
    {
        $refund = $refunds->refundPayment(Payment::findOrFail($payment), 'manual_refund');

        return (new RefundResource($refund->load('payment', 'order')))->response()->setStatusCode(201);
    }
}
