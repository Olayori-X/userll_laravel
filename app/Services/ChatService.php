<?php

namespace App\Services;

use App\Enums\ListingStatus;
use App\Models\Conversation;
use App\Models\Listing;
use App\Models\Message;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ChatService
{
    public const MAX_BODY = 2000;

    public function __construct(private Notifier $notifier)
    {
    }

    // ------------------------------------------------------------------ starting a conversation

    /** A buyer asks the seller about a listing. One conversation per buyer, seller and listing; asking again returns it. */
    public function openForListing(User $buyer, Listing $listing): Conversation
    {
        $this->requireActive($buyer);

        if (! in_array($listing->status, [ListingStatus::Active, ListingStatus::SoldOut], true)) {
            throw ValidationException::withMessages(['listing' => 'This listing is not available.']);
        }

        if ($listing->seller_id === $buyer->id) {
            throw ValidationException::withMessages(['listing' => 'You cannot message yourself about your own listing.']);
        }

        $seller = User::find($listing->seller_id);

        if (! $seller || ! $seller->isActive()) {
            throw ValidationException::withMessages(['listing' => 'This seller is not available right now.']);
        }

        $keys = ['buyer_id' => $buyer->id, 'seller_id' => $listing->seller_id, 'listing_id' => $listing->id];

        try {
            return Conversation::firstOrCreate($keys);
        } catch (UniqueConstraintViolationException) {
            return Conversation::where($keys)->firstOrFail(); // a double click: the other request created it first
        }
    }

    /** The buyer or the seller opens the conversation about an order. One per order, and only once it is paid. */
    public function openForOrder(User $user, Order $order): Conversation
    {
        if ($user->id !== $order->buyer_id && $user->id !== $order->seller_id) {
            throw ValidationException::withMessages(['order' => 'This is not your order.']);
        }

        if ($order->paid_at === null) {
            throw ValidationException::withMessages(['order' => 'You can chat about an order once it is paid.']);
        }

        try {
            return Conversation::firstOrCreate(
                ['order_id' => $order->id],
                ['buyer_id' => $order->buyer_id, 'seller_id' => $order->seller_id],
            );
        } catch (UniqueConstraintViolationException) {
            return Conversation::where('order_id', $order->id)->firstOrFail();
        }
    }

    // ------------------------------------------------------------------ messages

    /** Send a text message. Both people must be active accounts. The recipient is always the other person. */
    public function send(User $sender, Conversation $conversation, string $body): Message
    {
        if (! $conversation->includes($sender)) {
            throw ValidationException::withMessages(['conversation' => 'You are not part of this conversation.']);
        }

        $body = trim($body);

        if ($body === '' || Str::length($body) > self::MAX_BODY) {
            throw ValidationException::withMessages(['body' => 'A message must be between 1 and '.self::MAX_BODY.' characters.']);
        }

        $this->requireActive($sender);

        $recipient = User::find($conversation->otherPartyId($sender));

        if (! $recipient || ! $recipient->isActive()) {
            throw ValidationException::withMessages(['conversation' => 'This person is not available right now.']);
        }

        $message = DB::transaction(function () use ($sender, $conversation, $recipient, $body) {
            $message = Message::create([
                'conversation_id' => $conversation->id,
                'sender_id' => $sender->id,
                'recipient_id' => $recipient->id,
                'body' => $body,
            ]);

            $conversation->update(['last_message_at' => now()]);

            return $message;
        });

        // Only the very first message of a conversation rings the bell. After that, the unread counts tell them.
        if ($conversation->messages()->count() === 1) {
            $this->notifier->conversationStarted($conversation, $message);
        }

        return $message;
    }

    /** The reader has seen the conversation: everything sent to them in it is now read. @return int how many were marked */
    public function markRead(User $reader, Conversation $conversation): int
    {
        if (! $conversation->includes($reader)) {
            return 0;
        }

        return Message::where('conversation_id', $conversation->id)
            ->where('recipient_id', $reader->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    // ------------------------------------------------------------------ unread counts

    /** Every unread message addressed to this user: the number on the bell. */
    public function unreadTotal(User $user): int
    {
        return Message::where('recipient_id', $user->id)->unread()->count();
    }

    /**
     * Unread messages per conversation, for a list screen. One query for the whole list.
     *
     * @param  list<int>  $conversationIds
     * @return Collection<int, int> conversation id => unread count
     */
    public function unreadByConversation(User $user, array $conversationIds): Collection
    {
        return Message::where('recipient_id', $user->id)
            ->unread()
            ->whereIn('conversation_id', $conversationIds)
            ->selectRaw('conversation_id, COUNT(*) as total')
            ->groupBy('conversation_id')
            ->pluck('total', 'conversation_id')
            ->map(fn ($total) => (int) $total);
    }

    // ------------------------------------------------------------------ internals

    private function requireActive(User $user): void
    {
        if (! $user->isActive()) {
            throw ValidationException::withMessages(['account' => 'You cannot send messages right now.']);
        }
    }
}