<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Enums\UserStatus;
use App\Models\Conversation;
use App\Models\Listing;
use App\Models\Message;
use App\Models\Order;
use App\Models\User;
use App\Notifications\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\MakesMarketplaceData;
use Tests\TestCase;

class ChatTest extends TestCase
{
    use MakesMarketplaceData, RefreshDatabase;

    // ---------------------------------------------------------------- helpers

    private function listingFor(User $seller, array $attributes = []): Listing
    {
        return Listing::factory()->create(['seller_id' => $seller->id] + $attributes);
    }

    private function startAbout(User $buyer, Listing $listing)
    {
        return $this->actingAs($buyer, 'sanctum')->postJson("/api/v1/listings/{$listing->slug}/conversation");
    }

    private function say(User $user, int $conversationId, string $body)
    {
        return $this->actingAs($user, 'sanctum')->postJson("/api/v1/conversations/{$conversationId}/messages", ['body' => $body]);
    }

    /** A conversation between a buyer and a seller about a fresh listing, opened through the real endpoint. */
    private function openChat(User $buyer, User $seller): int
    {
        return $this->startAbout($buyer, $this->listingFor($seller))->json('data.id');
    }

    private function suspend(User $user): void
    {
        $user->forceFill(['status' => UserStatus::Suspended])->save();
    }

    private function countKind(User $user, string $kind): int
    {
        return Notification::sent($user, UserNotification::class)
            ->filter(fn (UserNotification $n) => $n->kind === $kind)
            ->count();
    }

    // ---------------------------------------------------------------- starting a conversation about a listing

    public function test_a_buyer_starts_a_conversation_and_asking_again_returns_the_same_one(): void
    {
        $seller = $this->makeSeller('Ada Stores');
        $buyer = User::factory()->create();
        $listing = $this->listingFor($seller);

        $first = $this->startAbout($buyer, $listing)
            ->assertCreated()
            ->assertJsonPath('data.kind', 'listing')
            ->assertJsonPath('data.other_party.name', 'Ada Stores')
            ->assertJsonPath('data.other_party.store_slug', $seller->sellerProfile->slug)
            ->assertJsonPath('data.listing.title', $listing->title)
            ->assertJsonPath('data.order_number', null)
            ->assertJsonPath('data.unread_count', 0);

        $second = $this->startAbout($buyer, $listing)->assertOk(); // not 201: nothing new was created

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Conversation::count());

        // A conversation nobody has written in yet does not clutter the list.
        $this->actingAs($buyer, 'sanctum')->getJson('/api/v1/conversations')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($seller, 'sanctum')->getJson('/api/v1/conversations')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_the_rules_for_starting_a_conversation_about_a_listing(): void
    {
        $seller = $this->makeSeller();
        $buyer = User::factory()->create();
        $listing = $this->listingFor($seller);

        $this->postJson("/api/v1/listings/{$listing->slug}/conversation")->assertUnauthorized();

        // You cannot message yourself about your own listing.
        $this->startAbout($seller, $listing)->assertUnprocessable()->assertJsonValidationErrors('listing');

        // Drafts and removed listings do not exist for buyers; sold-out ones still do.
        $this->startAbout($buyer, $this->listingFor($seller, ['status' => ListingStatus::Draft]))->assertNotFound();
        $this->startAbout($buyer, $this->listingFor($seller, ['status' => ListingStatus::Removed]))->assertNotFound();
        $this->startAbout($buyer, $this->listingFor($seller, ['status' => ListingStatus::SoldOut, 'stock' => 0]))->assertCreated();

        $this->startAbout($buyer, $this->listingFor($seller))->assertCreated();

        // A suspended buyer cannot start one, and nobody can start one with a suspended seller.
        $suspendedBuyer = User::factory()->create();
        $this->suspend($suspendedBuyer);
        $this->startAbout($suspendedBuyer, $listing)->assertUnprocessable()->assertJsonValidationErrors('account');

        $otherSeller = $this->makeSeller();
        $otherListing = $this->listingFor($otherSeller);
        $this->suspend($otherSeller);
        $this->startAbout($buyer, $otherListing)->assertUnprocessable()->assertJsonValidationErrors('listing');
    }

    // ---------------------------------------------------------------- sending, reading and unread counts

    public function test_messages_flow_both_ways_with_unread_counts_and_read_receipts(): void
    {
        $seller = $this->makeSeller('Ada Stores');
        $buyer = User::factory()->create(['name' => 'Ada Obi', 'email' => 'ada.private@example.com']);
        $conversationId = $this->openChat($buyer, $seller);

        $sent = $this->say($buyer, $conversationId, '  Is this still available?  ')
            ->assertCreated()
            ->assertJsonPath('data.body', 'Is this still available?') // trimmed
            ->assertJsonPath('data.is_mine', true)
            ->assertJsonPath('data.is_read', false);

        // The seller sees one unread message, from a first name and initial, never an email.
        $list = $this->actingAs($seller, 'sanctum')->getJson('/api/v1/conversations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.other_party.name', 'Ada O.')
            ->assertJsonPath('data.0.other_party.store_slug', null)
            ->assertJsonPath('data.0.unread_count', 1)
            ->assertJsonPath('data.0.last_message.preview', 'Is this still available?')
            ->assertJsonPath('data.0.last_message.is_mine', false);
        $this->assertStringNotContainsString('ada.private@example.com', $list->getContent());

        $this->actingAs($seller, 'sanctum')->getJson('/api/v1/conversations/unread-count')->assertOk()->assertJsonPath('data.unread_count', 1);
        $this->actingAs($buyer, 'sanctum')->getJson('/api/v1/conversations/unread-count')->assertOk()->assertJsonPath('data.unread_count', 0);

        // The seller opens the conversation: they see the message as theirs-not, with no read status of their own.
        $this->actingAs($seller, 'sanctum')->getJson("/api/v1/conversations/{$conversationId}")
            ->assertOk()
            ->assertJsonCount(1, 'data.messages')
            ->assertJsonPath('data.messages.0.is_mine', false)
            ->assertJsonPath('data.messages.0.is_read', null)
            ->assertJsonPath('data.has_more', false)
            ->assertJsonPath('data.conversation.unread_count', 1); // opening alone does not mark anything read

        $this->actingAs($seller, 'sanctum')->postJson("/api/v1/conversations/{$conversationId}/read")
            ->assertOk()->assertJsonPath('data.marked', 1)->assertJsonPath('data.unread_count', 0);

        // Marking again changes nothing.
        $this->actingAs($seller, 'sanctum')->postJson("/api/v1/conversations/{$conversationId}/read")
            ->assertOk()->assertJsonPath('data.marked', 0);

        // The buyer now sees their own message as read.
        $this->actingAs($buyer, 'sanctum')->getJson("/api/v1/conversations/{$conversationId}")
            ->assertOk()->assertJsonPath('data.messages.0.is_read', true);
    }

    public function test_replying_marks_what_came_before_as_read(): void
    {
        $seller = $this->makeSeller();
        $buyer = User::factory()->create();
        $conversationId = $this->openChat($buyer, $seller);

        $this->say($buyer, $conversationId, 'Hello?')->assertCreated();
        $this->say($buyer, $conversationId, 'Anyone there?')->assertCreated();

        $this->actingAs($seller, 'sanctum')->getJson('/api/v1/conversations/unread-count')->assertJsonPath('data.unread_count', 2);

        $this->say($seller, $conversationId, 'Yes, it is available.')->assertCreated(); // no explicit read call

        $this->actingAs($seller, 'sanctum')->getJson('/api/v1/conversations/unread-count')->assertJsonPath('data.unread_count', 0);
        $this->actingAs($buyer, 'sanctum')->getJson('/api/v1/conversations/unread-count')->assertJsonPath('data.unread_count', 1);
    }

    public function test_unread_counts_are_kept_per_conversation_and_the_list_is_newest_first(): void
    {
        $buyer = User::factory()->create();
        $sellerOne = $this->makeSeller('Store One');
        $sellerTwo = $this->makeSeller('Store Two');

        $a = $this->openChat($buyer, $sellerOne);
        $b = $this->openChat($buyer, $sellerTwo);

        $this->say($buyer, $a, 'Question for store one')->assertCreated();
        $this->travel(1)->minutes();
        $this->say($buyer, $b, 'Question for store two')->assertCreated();

        $this->actingAs($buyer, 'sanctum')->getJson('/api/v1/conversations')
            ->assertOk()->assertJsonPath('data.0.id', $b)->assertJsonPath('data.1.id', $a);

        $this->travel(1)->minutes();
        $this->say($sellerOne, $a, 'Answer one')->assertCreated();
        $this->say($sellerOne, $a, 'Answer two')->assertCreated();
        $this->say($sellerTwo, $b, 'Answer three')->assertCreated();
        $this->travelBack();

        $list = $this->actingAs($buyer, 'sanctum')->getJson('/api/v1/conversations')->assertOk();
        $byId = collect($list->json('data'))->keyBy('id');

        $this->assertSame(2, $byId[$a]['unread_count']);
        $this->assertSame(1, $byId[$b]['unread_count']);
        $this->actingAs($buyer, 'sanctum')->getJson('/api/v1/conversations/unread-count')->assertJsonPath('data.unread_count', 3);

        // Newest activity first: store two answered last.
        $this->assertSame($b, $list->json('data.0.id'));
    }

    public function test_only_the_first_message_of_a_conversation_notifies_the_other_side(): void
    {
        Notification::fake();
        $seller = $this->makeSeller();
        $buyer = User::factory()->create(['name' => 'Ada Obi']);
        $listing = $this->listingFor($seller, ['title' => 'Samsung Galaxy A54']);
        $conversationId = $this->startAbout($buyer, $listing)->json('data.id');

        $this->assertSame(0, $this->countKind($seller, 'chat.started')); // opening alone says nothing

        $this->say($buyer, $conversationId, 'Is the screen original?')->assertCreated();
        $this->say($buyer, $conversationId, 'Can you do a lower price?')->assertCreated();
        $this->say($seller, $conversationId, 'Yes, it is original.')->assertCreated();
        $this->say($buyer, $conversationId, 'Thanks')->assertCreated();

        $this->assertSame(1, $this->countKind($seller, 'chat.started'));
        $this->assertSame(0, $this->countKind($buyer, 'chat.started'));

        $note = Notification::sent($seller, UserNotification::class)->first(fn ($n) => $n->kind === 'chat.started');
        $this->assertSame('New message from Ada O.', $note->title);
        $this->assertStringContainsString('Samsung Galaxy A54', $note->body);
        $this->assertStringContainsString('Is the screen original?', $note->body);
        $this->assertFalse($note->email); // in-app only
    }

    public function test_message_bodies_must_be_valid(): void
    {
        $seller = $this->makeSeller();
        $buyer = User::factory()->create();
        $conversationId = $this->openChat($buyer, $seller);

        $this->say($buyer, $conversationId, '')->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->say($buyer, $conversationId, '     ')->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->say($buyer, $conversationId, str_repeat('a', 2001))->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->say($buyer, $conversationId, str_repeat('a', 2000))->assertCreated(); // exactly the limit is fine

        $this->assertSame(1, Message::count());
    }

    // ---------------------------------------------------------------- privacy and account state

    public function test_strangers_cannot_see_read_or_write_in_someone_elses_conversation(): void
    {
        $seller = $this->makeSeller();
        $buyer = User::factory()->create();
        $stranger = User::factory()->create();
        $conversationId = $this->openChat($buyer, $seller);
        $this->say($buyer, $conversationId, 'A private question')->assertCreated();

        $this->app['auth']->forgetGuards(); // the buyer is still "logged in" from the calls above
        $this->getJson("/api/v1/conversations/{$conversationId}")->assertUnauthorized();

        $this->actingAs($stranger, 'sanctum')->getJson("/api/v1/conversations/{$conversationId}")->assertNotFound();
        $this->actingAs($stranger, 'sanctum')->postJson("/api/v1/conversations/{$conversationId}/read")->assertNotFound();
        $this->say($stranger, $conversationId, 'Butting in')->assertNotFound();

        $this->actingAs($stranger, 'sanctum')->getJson('/api/v1/conversations')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($stranger, 'sanctum')->getJson('/api/v1/conversations/unread-count')->assertJsonPath('data.unread_count', 0);

        $this->assertSame(1, Message::count());
        $this->assertNull(Message::firstOrFail()->read_at); // the stranger's "read" attempt changed nothing
    }

    public function test_a_suspended_account_cannot_send_and_nobody_can_send_to_one(): void
    {
        $seller = $this->makeSeller();
        $buyer = User::factory()->create();
        $conversationId = $this->openChat($buyer, $seller);
        $this->say($buyer, $conversationId, 'Hello')->assertCreated();

        $this->suspend($seller);

        // The buyer cannot message a suspended seller...
        $this->say($buyer, $conversationId, 'Are you there?')->assertUnprocessable()->assertJsonValidationErrors('conversation');
        // ...and the suspended seller cannot message anyone.
        $this->say($seller, $conversationId, 'Hi')->assertUnprocessable()->assertJsonValidationErrors('account');

        $this->assertSame(1, Message::count());
    }

    // ---------------------------------------------------------------- conversations about an order

    public function test_the_buyer_and_the_seller_of_a_paid_order_can_chat_about_it(): void
    {
        $buyer = User::factory()->create(['name' => 'Ada Obi']);
        $seller = $this->makeSeller('Ada Stores');
        $stranger = User::factory()->create();
        $order = $this->makeEscrowedOrder($buyer, $seller);

        $first = $this->actingAs($buyer, 'sanctum')->postJson("/api/v1/orders/{$order->order_number}/conversation")
            ->assertCreated()
            ->assertJsonPath('data.kind', 'order')
            ->assertJsonPath('data.order_number', $order->order_number)
            ->assertJsonPath('data.listing', null)
            ->assertJsonPath('data.other_party.name', 'Ada Stores');

        // The seller opening the same order gets the same conversation, seeing the buyer as a first name and initial.
        $second = $this->actingAs($seller, 'sanctum')->postJson("/api/v1/orders/{$order->order_number}/conversation")
            ->assertOk()
            ->assertJsonPath('data.other_party.name', 'Ada O.');

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Conversation::count());

        $this->say($seller, $first->json('data.id'), 'Your order is packed and ships tomorrow.')->assertCreated();
        $this->say($buyer, $first->json('data.id'), 'Great, thank you.')->assertCreated();

        // Someone else's order is not found.
        $this->actingAs($stranger, 'sanctum')->postJson("/api/v1/orders/{$order->order_number}/conversation")->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->postJson("/api/v1/orders/{$order->order_number}/conversation")->assertUnauthorized();
    }

    public function test_an_unpaid_order_has_no_chat_yet(): void
    {
        $buyer = User::factory()->create();
        $seller = $this->makeSeller();
        $order = $this->makeEscrowedOrder($buyer, $seller);
        Order::whereKey($order->id)->update(['paid_at' => null]);

        $this->actingAs($buyer, 'sanctum')->postJson("/api/v1/orders/{$order->order_number}/conversation")
            ->assertUnprocessable()->assertJsonValidationErrors('order');

        $this->assertSame(0, Conversation::count());
    }

    // ---------------------------------------------------------------- long conversations and limits

    public function test_a_long_conversation_loads_in_pages_oldest_first(): void
    {
        $seller = $this->makeSeller();
        $buyer = User::factory()->create();
        $conversationId = $this->openChat($buyer, $seller);

        foreach (range(1, 55) as $i) {
            Message::create([
                'conversation_id' => $conversationId,
                'sender_id' => $i % 2 ? $buyer->id : $seller->id,
                'recipient_id' => $i % 2 ? $seller->id : $buyer->id,
                'body' => "m{$i}",
            ]);
        }

        $page = $this->actingAs($buyer, 'sanctum')->getJson("/api/v1/conversations/{$conversationId}")
            ->assertOk()
            ->assertJsonCount(50, 'data.messages')
            ->assertJsonPath('data.has_more', true)
            ->assertJsonPath('data.messages.0.body', 'm6')      // the newest 50, read oldest first
            ->assertJsonPath('data.messages.49.body', 'm55');

        $older = $this->actingAs($buyer, 'sanctum')
            ->getJson("/api/v1/conversations/{$conversationId}?before=".$page->json('data.messages.0.id'))
            ->assertOk()
            ->assertJsonCount(5, 'data.messages')
            ->assertJsonPath('data.has_more', false)
            ->assertJsonPath('data.messages.0.body', 'm1')
            ->assertJsonPath('data.messages.4.body', 'm5');

        $this->actingAs($buyer, 'sanctum')->getJson("/api/v1/conversations/{$conversationId}?before=abc")->assertUnprocessable();
    }

    public function test_sending_is_limited_to_thirty_messages_a_minute(): void
    {
        $seller = $this->makeSeller();
        $buyer = User::factory()->create();
        $conversationId = $this->openChat($buyer, $seller);

        foreach (range(1, 30) as $i) {
            $this->say($buyer, $conversationId, "message {$i}")->assertCreated();
        }

        $this->say($buyer, $conversationId, 'one too many')->assertStatus(429);

        // The send limit is its own counter: starting a conversation (a different action) is unaffected.
        $this->startAbout($buyer, $this->listingFor($seller))->assertCreated();

        $this->assertSame(30, Message::count());
    }
}