<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * The buyer's orders. Only orders that were actually paid appear here:
 * unpaid checkouts are reached through GET /checkouts/{reference}.
 */
class OrderController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['status' => ['sometimes', Rule::enum(OrderStatus::class)]]);

        $query = $request->user()->purchases()
            ->whereNotNull('paid_at')
            ->with(['items', 'seller.sellerProfile'])
            ->latest('id');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        return OrderResource::collection($query->paginate(20)->withQueryString());
    }

    public function show(Request $request, string $orderNumber): OrderResource
    {
        $order = $request->user()->purchases()
            ->whereNotNull('paid_at')
            ->where('order_number', $orderNumber)
            ->with(['items', 'seller.sellerProfile'])
            ->firstOrFail();

        return new OrderResource($order);
    }
}
