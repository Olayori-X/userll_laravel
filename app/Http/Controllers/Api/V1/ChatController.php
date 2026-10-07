<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ListingStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\Listing;
use App\Models\Order;
use App\Services\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ChatController extends Controller
{
    private const PAGE = 50;

    /** The user's conversations that have at least one message, most recently active first. */
    public function index(Request $request, ChatService $chat): AnonymousResourceCollection
    {
        $user = $request->user();

        $conversations = Conversation::involving($user->id)
            ->whereNotNull('last_message_at')
            ->with(['buyer', 'seller.sellerProfile', 'listing', 'order', 'latestMessage'])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $counts = $chat->unreadByConversation($user, $conversations->pluck('id')->all());
        $conversations->getCollection()->each(fn (Conversation $c) => $c->setAttribute('unread_count', $counts[$c->id] ?? 0));

        return ConversationResource::collection($conversations);
    }

    /** Just the number, for the message icon. */
    public function unreadCount(Request $request, ChatService $chat): JsonResponse
    {
        return response()->json(['data' => ['unread_count' => $chat->unreadTotal($request->user())]]);
    }

    /** Ask a seller about a listing. Returns the existing conversation if there already is one. */
    public function startForListing(Request $request, Listing $listing, ChatService $chat): JsonResponse
    {
        // Same visibility as the public listing page: drafts and removed listings do not exist for buyers.
        abort_unless(in_array($listing->status, [ListingStatus::Active, ListingStatus::SoldOut], true), 404);

        return $this->conversationResponse($request, $chat->openForListing($request->user(), $listing), $chat);
    }

    /** Chat about an order. The buyer or the seller of that order can open it. */
    public function startForOrder(Request $request, string $orderNumber, ChatService $chat): JsonResponse
    {
        $user = $request->user();

        $order = Order::where('order_number', $orderNumber)
            ->where(fn ($q) => $q->where('buyer_id', $user->id)->orWhere('seller_id', $user->id))
            ->firstOrFail(); // someone else's order is a 404

        return $this->conversationResponse($request, $chat->openForOrder($user, $order), $chat);
    }

    /** A conversation and its latest messages. Add ?before=<message id> to load older ones. */
    public function show(Request $request, int $conversation, ChatService $chat): JsonResponse
    {
        $user = $request->user();
        $conv = Conversation::involving($user->id)->findOrFail($conversation);

        $request->validate(['before' => ['sometimes', 'integer', 'min:1']]);

        $page = $conv->messages()
            ->when($request->integer('before'), fn ($q, $before) => $q->where('id', '<', $before))
            ->orderByDesc('id')
            ->limit(self::PAGE + 1)
            ->get();

        $hasMore = $page->count() > self::PAGE;
        $messages = $page->take(self::PAGE)->reverse()->values(); // oldest first, the way a chat is read

        return response()->json(['data' => [
            'conversation' => $this->conversationData($request, $conv, $chat),
            'messages' => MessageResource::collection($messages)->resolve($request),
            'has_more' => $hasMore,
        ]]);
    }

    /** The user has the conversation open: everything sent to them in it is now read. */
    public function markRead(Request $request, int $conversation, ChatService $chat): JsonResponse
    {
        $user = $request->user();
        $conv = Conversation::involving($user->id)->findOrFail($conversation);

        $marked = $chat->markRead($user, $conv);

        return response()->json(['data' => ['marked' => $marked, 'unread_count' => $chat->unreadTotal($user)]]);
    }

    public function send(Request $request, int $conversation, ChatService $chat): JsonResponse
    {
        $user = $request->user();
        $conv = Conversation::involving($user->id)->findOrFail($conversation);

        $data = $request->validate(['body' => ['required', 'string', 'max:'.ChatService::MAX_BODY]]);

        $message = $chat->send($user, $conv, $data['body']);
        $chat->markRead($user, $conv); // replying means you have read what came before

        return MessageResource::make($message)->response()->setStatusCode(201);
    }

    // ------------------------------------------------------------------ internals

    private function conversationResponse(Request $request, Conversation $conversation, ChatService $chat): JsonResponse
    {
        return response()->json(
            ['data' => $this->conversationData($request, $conversation, $chat)],
            $conversation->wasRecentlyCreated ? 201 : 200,
        );
    }

    /** A conversation as the list screen shows it, with its unread count for this user. */
    private function conversationData(Request $request, Conversation $conversation, ChatService $chat): array
    {
        $conversation->load(['buyer', 'seller.sellerProfile', 'listing', 'order', 'latestMessage']);
        $conversation->setAttribute('unread_count', $chat->unreadByConversation($request->user(), [$conversation->id])[$conversation->id] ?? 0);

        return (new ConversationResource($conversation))->resolve($request);
    }
}