<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminReviewResource;
use App\Models\Review;
use App\Services\ReviewService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminReviewController extends Controller
{
    /**
     * All reviews, newest first. Filters: ?hidden=1 (only hidden) or ?hidden=0 (only visible),
     * ?seller_id=12, ?rating=1, ?max_rating=2 (that many stars or fewer).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'hidden' => ['sometimes', 'boolean'],
            'seller_id' => ['sometimes', 'integer'],
            'rating' => ['sometimes', 'integer', 'between:1,5'],
            'max_rating' => ['sometimes', 'integer', 'between:1,5'],
        ]);

        $reviews = Review::query()
            ->with('order', 'reviewer', 'seller.sellerProfile', 'hiddenBy')
            ->when(array_key_exists('hidden', $data), fn ($q) => $q->where('is_hidden', (bool) $data['hidden']))
            ->when($data['seller_id'] ?? null, fn ($q, $id) => $q->where('seller_id', $id))
            ->when($data['rating'] ?? null, fn ($q, $stars) => $q->where('rating', $stars))
            ->when($data['max_rating'] ?? null, fn ($q, $stars) => $q->where('rating', '<=', $stars))
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        return AdminReviewResource::collection($reviews);
    }

    public function show(int $review): AdminReviewResource
    {
        return new AdminReviewResource(
            Review::with('order', 'reviewer', 'seller.sellerProfile', 'hiddenBy')->findOrFail($review)
        );
    }

    /** Take a review out of public lists and the seller's average. The reason is shown to its buyer and seller. */
    public function hide(Request $request, int $review, ReviewService $reviews): AdminReviewResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']]);

        $hidden = $reviews->hide(Review::findOrFail($review), $request->user(), $data['reason']);

        return new AdminReviewResource($hidden->load('order', 'reviewer', 'seller.sellerProfile', 'hiddenBy'));
    }

    public function unhide(Request $request, int $review, ReviewService $reviews): AdminReviewResource
    {
        $restored = $reviews->unhide(Review::findOrFail($review), $request->user());

        return new AdminReviewResource($restored->load('order', 'reviewer', 'seller.sellerProfile', 'hiddenBy'));
    }
}