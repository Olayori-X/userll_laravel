<?php

namespace App\Http\Controllers\Api\V1\Seller;

use App\Http\Controllers\Controller;
use App\Http\Resources\OwnReviewResource;
use App\Models\Review;
use App\Services\ReviewService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SellerReviewController extends Controller
{
    /** Reviews of the seller's store, newest first. Add ?needs_reply=1 to see only the ones still waiting for a reply. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $seller = $request->user();

        $reviews = Review::where('seller_id', $seller->id)
            ->with('order', 'reviewer')
            ->when($request->boolean('needs_reply'), fn ($q) => $q->whereNull('seller_reply')->where('is_hidden', false))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        $profile = $seller->sellerProfile;

        return OwnReviewResource::collection($reviews)->additional([
            'summary' => [
                'reviews_count' => (int) $profile->reviews_count,
                'rating_average' => (float) $profile->rating_average,
            ],
        ]);
    }

    /** The seller's one public reply to a review. It cannot be edited afterwards. */
    public function reply(Request $request, int $review, ReviewService $reviews): OwnReviewResource
    {
        $data = $request->validate(['reply' => ['required', 'string', 'min:2', 'max:500']]);

        $mine = Review::where('seller_id', $request->user()->id)->findOrFail($review); // someone else's review is a 404

        return new OwnReviewResource($reviews->reply($request->user(), $mine, $data['reply'])->load('order', 'reviewer'));
    }
}