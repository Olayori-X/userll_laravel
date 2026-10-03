<?php

namespace App\Http\Controllers\Api\V1\Seller;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\SellerOrderResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** Orders for the logged-in seller's own sales. Shipping and payout actions arrive in step 4. */
class SellerOrderController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['status' => ['sometimes', Rule::enum(OrderStatus::class)]]);

        $query = $request->user()->sales()
            ->whereNotNull('paid_at')
            ->with('items')
            ->latest('id');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        return SellerOrderResource::collection($query->paginate(20)->withQueryString());
    }

    public function show(Request $request, string $orderNumber): SellerOrderResource
    {
        $order = $request->user()->sales()
            ->whereNotNull('paid_at')
            ->where('order_number', $orderNumber)
            ->with('items')
            ->firstOrFail();

        return new SellerOrderResource($order);
    }
}
