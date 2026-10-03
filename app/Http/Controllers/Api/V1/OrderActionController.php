<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\DisputeService;
use App\Services\OrderFulfilmentService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** What a buyer can do with one of their paid orders. */
class OrderActionController extends Controller
{
    /** "I received it": releases the seller's money. */
    public function confirm(Request $request, string $orderNumber, OrderFulfilmentService $fulfilment): OrderResource
    {
        $fulfilment->complete($this->mine($request, $orderNumber));

        return $this->respond($request, $orderNumber);
    }

    /** Change of mind before the seller ships: full refund. */
    public function cancel(Request $request, string $orderNumber, OrderFulfilmentService $fulfilment): OrderResource
    {
        $fulfilment->cancelBeforeShipping($this->mine($request, $orderNumber), 'buyer_cancelled');

        return $this->respond($request, $orderNumber);
    }

    /** "There is a problem": freezes the order until an admin decides. */
    public function dispute(Request $request, string $orderNumber, DisputeService $disputes): OrderResource
    {
        $data = $request->validate([
            'reason' => ['required', Rule::in(DisputeService::REASONS)],
            'details' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $disputes->open($this->mine($request, $orderNumber), $request->user(), $data['reason'], $data['details']);

        return $this->respond($request, $orderNumber);
    }

    private function mine(Request $request, string $orderNumber): Order
    {
        return $request->user()->purchases()
            ->whereNotNull('paid_at')
            ->where('order_number', $orderNumber)
            ->firstOrFail();
    }

    private function respond(Request $request, string $orderNumber): OrderResource
    {
        return new OrderResource($this->mine($request, $orderNumber)->load('items', 'seller.sellerProfile'));
    }
}
