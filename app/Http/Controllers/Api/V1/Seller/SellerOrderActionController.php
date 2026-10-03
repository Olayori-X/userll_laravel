<?php

namespace App\Http\Controllers\Api\V1\Seller;

use App\Http\Controllers\Controller;
use App\Http\Resources\SellerOrderResource;
use App\Models\Order;
use App\Services\OrderFulfilmentService;
use Illuminate\Http\Request;

/** What a seller can do with one of their paid orders. */
class SellerOrderActionController extends Controller
{
    /** Goods handed over. Needs tracking details (courier + number, or the rider's name and phone). */
    public function ship(Request $request, string $orderNumber, OrderFulfilmentService $fulfilment): SellerOrderResource
    {
        $data = $request->validate(['tracking_info' => ['required', 'string', 'min:3', 'max:500']]);

        $fulfilment->ship($this->mine($request, $orderNumber), $data['tracking_info']);

        return $this->respond($request, $orderNumber);
    }

    /** Seller cannot fulfil the order: buyer is refunded in full. Only before shipping. */
    public function cancel(Request $request, string $orderNumber, OrderFulfilmentService $fulfilment): SellerOrderResource
    {
        $fulfilment->cancelBeforeShipping($this->mine($request, $orderNumber), 'seller_cancelled');

        return $this->respond($request, $orderNumber);
    }

    private function mine(Request $request, string $orderNumber): Order
    {
        return $request->user()->sales()
            ->whereNotNull('paid_at')
            ->where('order_number', $orderNumber)
            ->firstOrFail();
    }

    private function respond(Request $request, string $orderNumber): SellerOrderResource
    {
        return new SellerOrderResource($this->mine($request, $orderNumber)->load('items'));
    }
}
