<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Review;
use App\Models\SellerProfile;
use App\Models\User;
use App\Notifications\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\MakesMarketplaceData;
use Tests\TestCase;

class ReviewTest extends TestCase
{
    use MakesMarketplaceData, RefreshDatabase;

    // ---------------------------------------------------------------- helpers

    private function completedOrder(User $buyer, User $seller): Order
    {
        return $this->makeEscrowedOrder($buyer, $seller, 10_000_000, [
            'status' => OrderStatus::Completed,
            'delivered_at' => now()->subDay(),
            'released_at' => now(),
        ]);
    }

    private function writeReview(User $buyer, Order $order, int $rating = 5, ?string $comment = 'Arrived quickly and as described.')
    {
        return $this->actingAs($buyer, 'sanctum')->postJson("/api/v1/orders/{$order->order_number}/review", [
            'rating' => $rating,
            'comment' => $comment,
        ]);
    }

    /** A completed order from a fresh buyer, already reviewed through the real endpoint. */
    private function reviewedBy(User $seller, int $rating, string $buyerName = 'Chidi Eze'): Review
    {
        $buyer = User::factory()->create(['name' => $buyerName]);
        $order = $this->completedOrder($buyer, $seller);
        $this->writeReview($buyer, $order, $rating)->assertCreated();

        return Review::where('order_id', $order->id)->firstOrFail();
    }

    private function profileOf(User $seller): SellerProfile
    {
        return SellerProfile::where('user_id', $seller->id)->firstOrFail();
    }

    private function countKind(User $user, string $kind): int
    {
        return Notification::sent($user, UserNotification::class)
            ->filter(fn (UserNotification $n) => $n->kind === $kind)
            ->count();
    }

    // ---------------------------------------------------------------- the buyer writes a review

    public function test_a_buyer_reviews_a_completed_order_and_the_seller_is_told(): void
    {
        Notification::fake();
        $buyer = User::factory()->create(['name' => 'Ada Obi']);
        $seller = $this->makeSeller();
        $order = $this->completedOrder($buyer, $seller);

        $this->actingAs($buyer, 'sanctum')->getJson("/api/v1/orders/{$order->order_number}/review")
            ->assertOk()->assertJsonPath('data', null);

        $this->writeReview($buyer, $order, 4, '  Good, but the box was dented.  ')
            ->assertCreated()
            ->assertJsonPath('data.rating', 4)
            ->assertJsonPath('data.comment', 'Good, but the box was dented.') // trimmed
            ->assertJsonPath('data.reviewer', 'Ada O.')
            ->assertJsonPath('data.order_number', $order->order_number)
            ->assertJsonPath('data.seller_reply', null);

        $review = Review::firstOrFail();
        $this->assertSame($buyer->id, $review->reviewer_id);
        $this->assertSame($seller->id, $review->seller_id);
        $this->assertFalse($review->is_hidden);

        $profile = $this->profileOf($seller);
        $this->assertSame(1, $profile->reviews_count);
        $this->assertEqualsWithDelta(4.0, (float) $profile->rating_average, 0.001);

        $this->assertSame(1, $this->countKind($seller, 'review.received'));
        $this->assertSame(0, $this->countKind($buyer, 'review.received'));

        $this->actingAs($buyer, 'sanctum')->getJson("/api/v1/orders/{$order->order_number}/review")
            ->assertOk()->assertJsonPath('data.id', $review->id);
    }

    public function test_a_comment_is_optional(): void
    {
        $buyer = User::factory()->create();
        $order = $this->completedOrder($buyer, $this->makeSeller());

        $this->actingAs($buyer, 'sanctum')->postJson("/api/v1/orders/{$order->order_number}/review", ['rating' => 5])
            ->assertCreated()->assertJsonPath('data.comment', null);
    }

    public function test_the_rules_for_who_can_review_what(): void
    {
        $buyer = User::factory()->create();
        $seller = $this->makeSeller();

        // Guests cannot review.
        $order = $this->completedOrder($buyer, $seller);
        $this->postJson("/api/v1/orders/{$order->order_number}/review", ['rating' => 5])->assertUnauthorized();

        // An order that is not completed cannot be reviewed.
        $shipped = $this->makeEscrowedOrder($buyer, $seller, 10_000_000, ['status' => OrderStatus::Shipped, 'shipped_at' => now()]);
        $this->writeReview($buyer, $shipped)->assertUnprocessable()->assertJsonValidationErrors('order');

        $paid = $this->makeEscrowedOrder($buyer, $seller);
        $this->writeReview($buyer, $paid)->assertUnprocessable()->assertJsonValidationErrors('order');

        // Someone else's order is simply not found.
        $stranger = User::factory()->create();
        $this->writeReview($stranger, $order)->assertNotFound();
        $this->actingAs($stranger, 'sanctum')->getJson("/api/v1/orders/{$order->order_number}/review")->assertNotFound();

        $this->assertSame(0, Review::count());
        $this->assertSame(0, $this->profileOf($seller)->reviews_count);
    }

    public function test_the_rating_and_comment_must_be_valid(): void
    {
        $buyer = User::factory()->create();
        $order = $this->completedOrder($buyer, $this->makeSeller());

        $this->writeReview($buyer, $order, 0)->assertUnprocessable()->assertJsonValidationErrors('rating');
        $this->writeReview($buyer, $order, 6)->assertUnprocessable()->assertJsonValidationErrors('rating');
        $this->actingAs($buyer, 'sanctum')->postJson("/api/v1/orders/{$order->order_number}/review", [])
            ->assertUnprocessable()->assertJsonValidationErrors('rating');
        $this->writeReview($buyer, $order, 5, str_repeat('a', 1001))->assertUnprocessable()->assertJsonValidationErrors('comment');

        $this->assertSame(0, Review::count());
    }

    public function test_an_order_can_only_be_reviewed_once(): void
    {
        $buyer = User::factory()->create();
        $seller = $this->makeSeller();
        $order = $this->completedOrder($buyer, $seller);

        $this->writeReview($buyer, $order, 5)->assertCreated();
        $this->writeReview($buyer, $order, 1)->assertUnprocessable()->assertJsonValidationErrors('order');

        $this->assertSame(1, Review::count());
        $this->assertSame(5, Review::firstOrFail()->rating); // the first review stands
        $this->assertSame(1, $this->profileOf($seller)->reviews_count);
    }

    // ---------------------------------------------------------------- the average stays correct

    public function test_the_average_follows_reviews_through_hiding_and_unhiding(): void
    {
        $admin = $this->makeAdmin();
        $seller = $this->makeSeller();

        $this->reviewedBy($seller, 5);
        $this->reviewedBy($seller, 4);
        $oneStar = $this->reviewedBy($seller, 1);

        $profile = $this->profileOf($seller);
        $this->assertSame(3, $profile->reviews_count);
        $this->assertEqualsWithDelta(3.33, (float) $profile->rating_average, 0.001);

        // Hiding the 1-star review takes it out of the average.
        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/reviews/{$oneStar->id}/hide", ['reason' => 'Abusive language about the seller.'])
            ->assertOk()
            ->assertJsonPath('data.is_hidden', true)
            ->assertJsonPath('data.hidden_by', $admin->name);

        $profile = $this->profileOf($seller);
        $this->assertSame(2, $profile->reviews_count);
        $this->assertEqualsWithDelta(4.5, (float) $profile->rating_average, 0.001);

        // Bringing it back puts it in again.
        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/reviews/{$oneStar->id}/unhide")
            ->assertOk()->assertJsonPath('data.is_hidden', false)->assertJsonPath('data.hidden_reason', null);

        $profile = $this->profileOf($seller);
        $this->assertSame(3, $profile->reviews_count);
        $this->assertEqualsWithDelta(3.33, (float) $profile->rating_average, 0.001);
    }

    public function test_hiding_the_only_review_resets_the_average_to_zero(): void
    {
        $admin = $this->makeAdmin();
        $seller = $this->makeSeller();
        $only = $this->reviewedBy($seller, 2);

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/reviews/{$only->id}/hide", ['reason' => 'Not about this seller.'])->assertOk();

        $profile = $this->profileOf($seller);
        $this->assertSame(0, $profile->reviews_count);
        $this->assertEqualsWithDelta(0.0, (float) $profile->rating_average, 0.001);
    }

    public function test_moderation_rules(): void
    {
        $admin = $this->makeAdmin();
        $seller = $this->makeSeller();
        $review = $this->reviewedBy($seller, 3);

        // Only admins moderate.
        $this->actingAs($seller, 'sanctum')->postJson("/api/v1/admin/reviews/{$review->id}/hide", ['reason' => 'I do not like it'])->assertForbidden();

        // A reason is required, and it must say something.
        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/reviews/{$review->id}/hide", [])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/reviews/{$review->id}/hide", ['reason' => 'no'])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');

        // Cannot unhide a visible review, or hide a hidden one twice.
        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/reviews/{$review->id}/unhide")
            ->assertUnprocessable()->assertJsonValidationErrors('review');

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/reviews/{$review->id}/hide", ['reason' => 'Contains a phone number.'])->assertOk();
        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/reviews/{$review->id}/hide", ['reason' => 'Contains a phone number.'])
            ->assertUnprocessable()->assertJsonValidationErrors('review');
    }

    // ---------------------------------------------------------------- what the public sees

    public function test_the_public_sees_visible_reviews_with_a_summary_and_nothing_private(): void
    {
        $admin = $this->makeAdmin();
        $seller = $this->makeSeller();
        $slug = $seller->sellerProfile->slug;

        $buyer = User::factory()->create(['name' => 'Ada Obi', 'email' => 'ada.private@example.com']);
        $order = $this->completedOrder($buyer, $seller);
        $this->writeReview($buyer, $order, 5, 'Perfect.')->assertCreated();

        $this->reviewedBy($seller, 5);
        $this->reviewedBy($seller, 4);
        $hidden = $this->reviewedBy($seller, 1);
        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/reviews/{$hidden->id}/hide", ['reason' => 'Abusive language.'])->assertOk();

        // No login needed.
        $response = $this->getJson("/api/v1/sellers/{$slug}/reviews")
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('summary.reviews_count', 3)
            ->assertJsonPath('summary.distribution.5', 2)
            ->assertJsonPath('summary.distribution.4', 1)
            ->assertJsonPath('summary.distribution.1', 0); // the hidden one is not counted

        $this->assertEqualsWithDelta(4.67, (float) $response->json('summary.rating_average'), 0.001);
        $this->assertContains('Ada O.', $response->json('data.*.reviewer'));

        // Nothing that identifies the reviewer or reveals moderation reaches the public.
        $body = $response->getContent();
        $this->assertStringNotContainsString('ada.private@example.com', $body);
        $this->assertStringNotContainsString('Abusive language', $body);
        $this->assertStringNotContainsString($order->order_number, $body);
        $this->assertStringNotContainsString('reviewer_id', $body);
        $this->assertStringNotContainsString('hidden', $body);

        // Filtering by stars.
        $this->getJson("/api/v1/sellers/{$slug}/reviews?rating=4")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/sellers/{$slug}/reviews?rating=7")->assertUnprocessable();

        // The store page itself carries the summary.
        $this->getJson("/api/v1/sellers/{$slug}")
            ->assertOk()
            ->assertJsonPath('data.reviews_count', 3)
            ->assertJsonMissingPath('data.email');

        $this->getJson('/api/v1/sellers/no-such-store/reviews')->assertNotFound();
    }

    public function test_a_hidden_review_is_still_visible_to_its_buyer_and_seller_with_the_reason(): void
    {
        $admin = $this->makeAdmin();
        $seller = $this->makeSeller();
        $buyer = User::factory()->create();
        $order = $this->completedOrder($buyer, $seller);
        $this->writeReview($buyer, $order, 1, 'Awful.')->assertCreated();
        $review = Review::firstOrFail();

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/reviews/{$review->id}/hide", ['reason' => 'Breaks our review rules.'])->assertOk();

        $this->actingAs($buyer, 'sanctum')->getJson("/api/v1/orders/{$order->order_number}/review")
            ->assertOk()
            ->assertJsonPath('data.is_hidden', true)
            ->assertJsonPath('data.hidden_reason', 'Breaks our review rules.')
            ->assertJsonMissingPath('data.hidden_by');

        $this->actingAs($seller, 'sanctum')->getJson('/api/v1/seller/reviews')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.is_hidden', true)
            ->assertJsonPath('data.0.can_reply', false)
            ->assertJsonPath('summary.reviews_count', 0);

        $this->getJson("/api/v1/sellers/{$seller->sellerProfile->slug}/reviews")->assertOk()->assertJsonCount(0, 'data');
    }

    // ---------------------------------------------------------------- the seller replies

    public function test_a_seller_replies_once_and_the_buyer_is_told(): void
    {
        Notification::fake();
        $seller = $this->makeSeller();
        $buyer = User::factory()->create();
        $order = $this->completedOrder($buyer, $seller);
        $this->writeReview($buyer, $order, 3, 'It was okay.')->assertCreated();
        $review = Review::firstOrFail();

        $this->actingAs($seller, 'sanctum')->getJson('/api/v1/seller/reviews?needs_reply=1')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.can_reply', true);

        $this->actingAs($seller, 'sanctum')->postJson("/api/v1/seller/reviews/{$review->id}/reply", ['reply' => '  Thank you, we will pack it better.  '])
            ->assertOk()
            ->assertJsonPath('data.seller_reply', 'Thank you, we will pack it better.')
            ->assertJsonPath('data.can_reply', false);

        $this->assertSame(1, $this->countKind($buyer, 'review.replied'));

        // One reply only, and it cannot be replaced.
        $this->actingAs($seller, 'sanctum')->postJson("/api/v1/seller/reviews/{$review->id}/reply", ['reply' => 'Let me change that.'])
            ->assertUnprocessable()->assertJsonValidationErrors('review');
        $this->assertSame('Thank you, we will pack it better.', $review->fresh()->seller_reply);
        $this->assertSame(1, $this->countKind($buyer, 'review.replied'));

        // The reply is public, and the review no longer waits for one.
        $this->getJson("/api/v1/sellers/{$seller->sellerProfile->slug}/reviews")
            ->assertOk()->assertJsonPath('data.0.seller_reply', 'Thank you, we will pack it better.');
        $this->actingAs($seller, 'sanctum')->getJson('/api/v1/seller/reviews?needs_reply=1')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_reply_rules(): void
    {
        $seller = $this->makeSeller();
        $other = $this->makeSeller();
        $review = $this->reviewedBy($seller, 4);

        // Another seller cannot even see it.
        $this->actingAs($other, 'sanctum')->postJson("/api/v1/seller/reviews/{$review->id}/reply", ['reply' => 'Not mine to answer.'])->assertNotFound();

        // A buyer who is not a seller cannot reply at all.
        $this->actingAs(User::factory()->create(), 'sanctum')->postJson("/api/v1/seller/reviews/{$review->id}/reply", ['reply' => 'Hello'])->assertForbidden();

        // The reply must fit.
        $this->actingAs($seller, 'sanctum')->postJson("/api/v1/seller/reviews/{$review->id}/reply", ['reply' => 'a'])
            ->assertUnprocessable()->assertJsonValidationErrors('reply');
        $this->actingAs($seller, 'sanctum')->postJson("/api/v1/seller/reviews/{$review->id}/reply", ['reply' => str_repeat('a', 501)])
            ->assertUnprocessable()->assertJsonValidationErrors('reply');

        $this->assertNull($review->fresh()->seller_reply);

        // The other seller's own list does not include this review.
        $this->actingAs($other, 'sanctum')->getJson('/api/v1/seller/reviews')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_hidden_review_cannot_be_replied_to(): void
    {
        $admin = $this->makeAdmin();
        $seller = $this->makeSeller();
        $review = $this->reviewedBy($seller, 1);
        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/reviews/{$review->id}/hide", ['reason' => 'Breaks our review rules.'])->assertOk();

        $this->actingAs($seller, 'sanctum')->postJson("/api/v1/seller/reviews/{$review->id}/reply", ['reply' => 'This is not true.'])
            ->assertUnprocessable()->assertJsonValidationErrors('review');

        $this->assertNull($review->fresh()->seller_reply);
    }

    // ---------------------------------------------------------------- what the admin sees

    public function test_the_admin_list_filters_and_shows_who_is_behind_a_review(): void
    {
        $admin = $this->makeAdmin();
        $seller = $this->makeSeller();
        $otherSeller = $this->makeSeller();

        $low = $this->reviewedBy($seller, 1, 'Ngozi Bello');
        $this->reviewedBy($seller, 5);
        $this->reviewedBy($otherSeller, 2);
        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/reviews/{$low->id}/hide", ['reason' => 'Abusive language.'])->assertOk();

        $this->actingAs($seller, 'sanctum')->getJson('/api/v1/admin/reviews')->assertForbidden();

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/reviews')->assertOk()->assertJsonCount(3, 'data');
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/reviews?hidden=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $low->id);
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/reviews?hidden=0')->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/reviews?max_rating=2')->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/reviews?seller_id='.$seller->id)->assertOk()->assertJsonCount(2, 'data');

        $this->actingAs($admin, 'sanctum')->getJson("/api/v1/admin/reviews/{$low->id}")
            ->assertOk()
            ->assertJsonPath('data.reviewer.name', 'Ngozi Bello')
            ->assertJsonPath('data.hidden_reason', 'Abusive language.')
            ->assertJsonPath('data.hidden_by', $admin->name)
            ->assertJsonPath('data.seller.store_name', $seller->sellerProfile->store_name);

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/reviews?rating=9')->assertUnprocessable();
    }
}