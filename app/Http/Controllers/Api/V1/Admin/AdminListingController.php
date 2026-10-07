<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ListingStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminListingResource;
use App\Models\Listing;
use App\Services\ListingModerationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class AdminListingController extends Controller
{
    private const WITH = ['images', 'category', 'seller.sellerProfile'];

    /**
     * Every listing, newest first (listings a seller deleted themselves are not included).
     * Filters: ?status=removed (draft, active, sold_out, removed), ?seller_id=12, ?search=iphone (title).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::enum(ListingStatus::class)],
            'seller_id' => ['sometimes', 'integer'],
            'search' => ['sometimes', 'string', 'max:100'],
        ]);

        $listings = Listing::query()
            ->with(self::WITH)
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($data['seller_id'] ?? null, fn ($q, $id) => $q->where('seller_id', $id))
            ->when($data['search'] ?? null, fn ($q, $text) => $q->where('title', 'like', '%'.addcslashes($text, '%_\\').'%'))
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        return AdminListingResource::collection($listings);
    }

    public function show(int $listing): AdminListingResource
    {
        return new AdminListingResource(Listing::with(self::WITH)->findOrFail($listing));
    }

    /** Take a listing off the market. The reason is shown to the seller, so it must say what to fix. */
    public function remove(Request $request, int $listing, ListingModerationService $moderation): AdminListingResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']]);

        $removed = $moderation->remove(Listing::findOrFail($listing), $data['reason']);

        return new AdminListingResource($removed->load(self::WITH));
    }

    /** Bring a removed listing back as a draft. */
    public function restore(int $listing, ListingModerationService $moderation): AdminListingResource
    {
        $restored = $moderation->restore(Listing::findOrFail($listing));

        return new AdminListingResource($restored->load(self::WITH));
    }
}