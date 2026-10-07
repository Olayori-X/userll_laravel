<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReviewResource;
use App\Models\Review;
use App\Models\SellerProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SellerReviewController extends Controller
{
    /** A store's visible reviews, newest first. Add ?rating=5 to see only reviews with that many stars. */
    public function index(Request $request, SellerProfile $sellerProfile): AnonymousResourceCollection
    {
        $data = $request->validate(['rating' => ['sometimes', 'integer', 'between:1,5']]);

        $reviews = Review::visible()
            ->where('seller_id', $sellerProfile->user_id)
            ->with('reviewer')
            ->when($data['rating'] ?? null, fn ($q, $rating) => $q->where('rating', $rating))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        // How many visible reviews gave each star rating: [5 => 12, 4 => 3, 3 => 0, 2 => 0, 1 => 1].
        $counts = Review::visible()
            ->where('seller_id', $sellerProfile->user_id)
            ->selectRaw('rating, COUNT(*) as total')
            ->groupBy('rating')
            ->pluck('total', 'rating');

        $distribution = [];
        foreach ([5, 4, 3, 2, 1] as $stars) {
            $distribution[$stars] = (int) ($counts[$stars] ?? 0);
        }

        return ReviewResource::collection($reviews)->additional([
            'summary' => [
                'reviews_count' => (int) $sellerProfile->reviews_count,
                'rating_average' => (float) $sellerProfile->rating_average,
                'distribution' => $distribution,
            ],
        ]);
    }
}