<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\PayoutStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminPayoutResource;
use App\Models\Payout;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class AdminPayoutController extends Controller
{
    /**
     * All payouts, newest first. Filters: ?status=processing, ?seller_id=12, ?reference=payout-... (or a
     * Paystack transfer code). A payout stuck in "pending" or "processing" for a long time is the one to look at.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::enum(PayoutStatus::class)],
            'seller_id' => ['sometimes', 'integer'],
            'reference' => ['sometimes', 'string', 'max:100'],
        ]);

        $payouts = Payout::query()
            ->with('seller.sellerProfile')
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($data['seller_id'] ?? null, fn ($q, $seller) => $q->where('seller_id', $seller))
            ->when($data['reference'] ?? null, fn ($q, $ref) => $q->where(
                fn ($q) => $q->where('paystack_reference', $ref)->orWhere('paystack_transfer_code', $ref)
            ))
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        return AdminPayoutResource::collection($payouts);
    }

    public function show(int $payout): AdminPayoutResource
    {
        return new AdminPayoutResource(Payout::with('seller.sellerProfile')->findOrFail($payout));
    }
}