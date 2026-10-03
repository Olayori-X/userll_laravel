<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\RefundStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\RefundResource;
use App\Models\Refund;
use App\Services\RefundService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class AdminRefundController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['status' => ['sometimes', Rule::enum(RefundStatus::class)]]);

        $query = Refund::with('payment', 'order')->latest('id');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        return RefundResource::collection($query->paginate(30)->withQueryString());
    }

    /** Send a failed refund again. Check the Paystack dashboard first so the buyer is not refunded twice. */
    public function retry(int $refund, RefundService $refunds): RefundResource
    {
        return new RefundResource($refunds->retry(Refund::findOrFail($refund))->load('payment', 'order'));
    }
}
