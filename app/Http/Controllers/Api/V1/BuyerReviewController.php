<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\OwnReviewResource;
use App\Models\Order;
use App\Services\ReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** A buyer reviewing the seller of one of their completed orders. */
class BuyerReviewController extends Controller
{
    /** The buyer's review of this order, or null when they have not written one yet. */
    public function show(Request $request, string $orderNumber): OwnReviewResource|JsonResponse
    {
        $review = $this->mine($request, $orderNumber)->review;

        return $review
            ? new OwnReviewResource($review->load('order', 'reviewer'))
            : response()->json(['data' => null]);
    }

    /** Rate the seller after a completed order. One review per order; it cannot be edited afterwards. */
    public function store(Request $request, string $orderNumber, ReviewService $reviews): JsonResponse
    {
        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $review = $reviews->create($request->user(), $this->mine($request, $orderNumber), $data['rating'], $data['comment'] ?? null);

        return OwnReviewResource::make($review->load('order', 'reviewer'))->response()->setStatusCode(201);
    }

    private function mine(Request $request, string $orderNumber): Order
    {
        return $request->user()->purchases()
            ->whereNotNull('paid_at')
            ->where('order_number', $orderNumber)
            ->firstOrFail();
    }
}