<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ListingStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\SearchListingsRequest;
use App\Http\Resources\ListingCardResource;
use App\Http\Resources\ListingResource;
use App\Models\Category;
use App\Models\Listing;
use App\Models\SellerProfile;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Public browsing and search. No login needed. */
class ListingController extends Controller
{
    public function index(SearchListingsRequest $request): AnonymousResourceCollection
    {
        $f = $request->validated();

        $query = Listing::query()
            ->active()
            ->with(['images', 'seller.sellerProfile']);

        if (! empty($f['q'])) {
            $this->applyTextSearch($query, $f['q']);
        }

        if (isset($f['category'])) {
            $category = Category::where('slug', $f['category'])->firstOrFail();
            // A top-level category also shows everything in its sub-categories (two levels supported).
            $ids = $category->children()->pluck('id')->push($category->id);
            $query->whereIn('category_id', $ids);
        }

        if (isset($f['seller'])) {
            $query->where('seller_id', SellerProfile::where('slug', $f['seller'])->value('user_id'));
        }

        if (isset($f['condition'])) {
            $query->where('condition', $f['condition']);
        }
        if (isset($f['state'])) {
            $query->where('state', $f['state']);
        }
        if (isset($f['city'])) {
            $query->where('city', $f['city']);
        }
        if (isset($f['min_price'])) {
            $query->where('price', '>=', Money::fromNaira($f['min_price']));
        }
        if (isset($f['max_price'])) {
            $query->where('price', '<=', Money::fromNaira($f['max_price']));
        }

        match ($f['sort'] ?? 'newest') {
            'price_asc' => $query->orderBy('price')->orderByDesc('id'),
            'price_desc' => $query->orderByDesc('price')->orderByDesc('id'),
            default => $query->orderByDesc('created_at')->orderByDesc('id'),
        };

        return ListingCardResource::collection(
            $query->paginate($f['per_page'] ?? 24)->withQueryString()
        );
    }

    public function show(Listing $listing): ListingResource
    {
        // Drafts, admin-removed listings and listings of suspended sellers are not public.
        // Sold-out ones stay visible (the page can say "sold out").
        abort_unless($listing->isPubliclyVisible(), 404);

        return new ListingResource($listing->load(['images', 'category', 'seller.sellerProfile']));
    }

    /** Every word must appear in the title or description. Basic for now; real search engine comes later. */
    private function applyTextSearch(Builder $query, string $text): void
    {
        $words = collect(preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY))->take(6);

        foreach ($words as $word) {
            $like = '%'.addcslashes($word, '\\%_').'%';

            $query->where(fn (Builder $q) => $q->where('title', 'like', $like)->orWhere('description', 'like', $like));
        }
    }
}
