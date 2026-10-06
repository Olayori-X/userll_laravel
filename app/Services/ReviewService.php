<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Review;
use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReviewService
{
    public function __construct(private Notifier $notifier)
    {
    }

    // ------------------------------------------------------------------ the buyer reviews the seller

    /** One review per completed order, written by its buyer. The rating is 1 to 5; the comment is optional. */
    public function create(User $buyer, Order $order, int $rating, ?string $comment): Review
    {
        if ($rating < 1 || $rating > 5) {
            throw ValidationException::withMessages(['rating' => 'The rating must be from 1 to 5.']);
        }

        $review = DB::transaction(function () use ($buyer, $order, $rating, $comment) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->buyer_id !== $buyer->id) {
                throw ValidationException::withMessages(['order' => 'You can only review your own orders.']);
            }

            if ($order->status !== OrderStatus::Completed) {
                throw ValidationException::withMessages(['order' => 'You can review an order once it is completed.']);
            }

            try {
                $review = Review::create([
                    'order_id' => $order->id,
                    'reviewer_id' => $buyer->id,
                    'seller_id' => $order->seller_id,
                    'rating' => $rating,
                    'comment' => $this->clean($comment),
                ]);
            } catch (UniqueConstraintViolationException) {
                // Two quick requests: the second one lands here.
                throw ValidationException::withMessages(['order' => 'You have already reviewed this order.']);
            }

            $this->recompute($order->seller_id);

            return $review;
        });

        $this->notifier->reviewReceived($review);

        return $review;
    }

    // ------------------------------------------------------------------ the seller replies

    /** The seller's one public reply. It cannot be edited or replaced, and a hidden review cannot be replied to. */
    public function reply(User $seller, Review $review, string $reply): Review
    {
        $review = DB::transaction(function () use ($seller, $review, $reply) {
            $locked = Review::whereKey($review->id)->lockForUpdate()->firstOrFail();

            if ($locked->seller_id !== $seller->id) {
                throw ValidationException::withMessages(['review' => 'You can only reply to reviews of your own store.']);
            }

            if ($locked->is_hidden) {
                throw ValidationException::withMessages(['review' => 'This review is hidden and cannot be replied to.']);
            }

            if ($locked->seller_reply !== null) {
                throw ValidationException::withMessages(['review' => 'You have already replied to this review.']);
            }

            // Not fillable: only this service writes the reply.
            $locked->forceFill(['seller_reply' => trim($reply), 'seller_replied_at' => now()])->save();

            return $locked;
        });

        $this->notifier->reviewReplied($review);

        return $review;
    }

    // ------------------------------------------------------------------ an admin moderates

    /** Take a review out of public lists and out of the seller's average. It stays visible to its buyer and seller. */
    public function hide(Review $review, User $admin, string $reason): Review
    {
        return DB::transaction(function () use ($review, $admin, $reason) {
            $locked = Review::whereKey($review->id)->lockForUpdate()->firstOrFail();

            if ($locked->is_hidden) {
                throw ValidationException::withMessages(['review' => 'This review is already hidden.']);
            }

            $locked->forceFill([
                'is_hidden' => true,
                'hidden_reason' => trim($reason),
                'hidden_by' => $admin->id,
                'hidden_at' => now(),
            ])->save();

            $this->recompute($locked->seller_id);

            return $locked;
        });
    }

    public function unhide(Review $review, User $admin): Review
    {
        return DB::transaction(function () use ($review) {
            $locked = Review::whereKey($review->id)->lockForUpdate()->firstOrFail();

            if (! $locked->is_hidden) {
                throw ValidationException::withMessages(['review' => 'This review is not hidden.']);
            }

            $locked->forceFill(['is_hidden' => false, 'hidden_reason' => null, 'hidden_by' => null, 'hidden_at' => null])->save();

            $this->recompute($locked->seller_id);

            return $locked;
        });
    }

    // ------------------------------------------------------------------ the rating summary

    /**
     * Recount the seller's visible reviews and store the count and average on their profile. Recomputing
     * (instead of adding and subtracting) means the numbers can never drift. The profile row is locked first
     * so two reviews arriving together cannot overwrite each other's result. Call inside a transaction.
     */
    public function recompute(int $sellerId): void
    {
        $profile = SellerProfile::where('user_id', $sellerId)->lockForUpdate()->first();

        if (! $profile) {
            return;
        }

        $stats = Review::visible()
            ->where('seller_id', $sellerId)
            ->selectRaw('COUNT(*) as total, AVG(rating) as average')
            ->first();

        $count = (int) ($stats->total ?? 0);

        $profile->forceFill([
            'reviews_count' => $count,
            'rating_average' => $count > 0 ? round((float) $stats->average, 2) : 0,
        ])->save();
    }

    private function clean(?string $comment): ?string
    {
        $comment = trim((string) $comment);

        return $comment === '' ? null : $comment;
    }
}