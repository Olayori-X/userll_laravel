<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CheckoutResource;
use App\Models\Checkout;
use App\Services\CheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CheckoutController extends Controller
{
    /** Turn the cart into a checkout (one payment) with one order per seller. Payment itself is step 4. */
    public function store(Request $request, CheckoutService $checkouts): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'address_id' => ['required', 'integer', Rule::exists('addresses', 'id')->where('user_id', $user->id)],
        ]);

        $checkout = $checkouts->create($user, $user->addresses()->findOrFail($data['address_id']));

        return (new CheckoutResource($checkout->load('orders.items', 'orders.seller.sellerProfile')))
            ->response()
            ->setStatusCode(201);
    }

    /** A buyer's own checkout, e.g. to resume payment. */
    public function show(Request $request, string $reference): CheckoutResource
    {
        $checkout = Checkout::where('buyer_id', $request->user()->id)
            ->where('reference', $reference)
            ->with('orders.items', 'orders.seller.sellerProfile')
            ->firstOrFail();

        return new CheckoutResource($checkout);
    }
}
