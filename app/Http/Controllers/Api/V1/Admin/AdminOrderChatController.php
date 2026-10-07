<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Dispute;
use App\Models\Message;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminOrderChatController extends Controller
{
    private const PAGE = 50;

    /**
     * The chat between a buyer and a seller about one order, read-only, newest 50 messages (add ?before=<message id>
     * for older ones). Allowed only for an order that has a dispute. The read itself is recorded in the audit log.
     */
    public function show(Request $request, string $orderNumber): JsonResponse
    {
        $request->validate(['before' => ['sometimes', 'integer', 'min:1']]);

        $order = Order::where('order_number', $orderNumber)->firstOrFail();
        $dispute = Dispute::where('order_id', $order->id)->latest('id')->first();

        if (! $dispute) {
            return response()->json(['message' => "An order's chat can be read only when the order has a dispute."], 403);
        }

        $conversation = Conversation::where('order_id', $order->id)->with(['buyer', 'seller.sellerProfile'])->first();

        if (! $conversation) {
            return response()->json(['message' => 'There is no chat for this order.'], 404);
        }

        $page = $conversation->messages()
            ->when($request->integer('before'), fn ($q, $before) => $q->where('id', '<', $before))
            ->orderByDesc('id')
            ->limit(self::PAGE + 1)
            ->get();

        $hasMore = $page->count() > self::PAGE;
        $messages = $page->take(self::PAGE)->reverse()->values(); // oldest first, the way a chat is read

        return response()->json(['data' => [
            'order_number' => $order->order_number,
            'dispute' => ['id' => $dispute->id, 'status' => $dispute->status, 'reason' => $dispute->reason],
            'buyer' => ['id' => $conversation->buyer_id, 'name' => $conversation->buyer?->name],
            'seller' => [
                'id' => $conversation->seller_id,
                'name' => $conversation->seller?->name,
                'store_name' => $conversation->seller?->sellerProfile?->store_name,
            ],
            'messages' => $messages->map(fn (Message $message) => [
                'id' => $message->id,
                'from' => $message->sender_id === $conversation->buyer_id ? 'buyer' : 'seller',
                'body' => $message->body,
                'sent_at' => $message->created_at?->toIso8601String(),
                'read' => $message->read_at !== null,
            ])->all(),
            'has_more' => $hasMore,
        ]]);
    }
}