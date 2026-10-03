<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\DisputeResource;
use App\Models\Dispute;
use App\Services\DisputeService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class AdminDisputeController extends Controller
{
    /** Disputes, by default the open ones. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['status' => ['sometimes', Rule::in(['open', 'resolved'])]]);

        return DisputeResource::collection(
            Dispute::where('status', $request->input('status', 'open'))
                ->with('order.seller.sellerProfile', 'opener')
                ->oldest('id')
                ->paginate(30)
                ->withQueryString()
        );
    }

    public function resolve(Request $request, int $dispute, DisputeService $disputes): DisputeResource
    {
        $data = $request->validate([
            'resolution' => ['required', Rule::in(['release', 'refund'])], // release = pay the seller, refund = pay the buyer
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $resolved = $disputes->resolve(Dispute::findOrFail($dispute), $request->user(), $data['resolution'], $data['note'] ?? null);

        return new DisputeResource($resolved->load('order.seller.sellerProfile', 'opener'));
    }
}
