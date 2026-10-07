<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * One row of a conversation list. The controller loads buyer, seller.sellerProfile, listing, order and
 * latestMessage, and sets "unread_count" on each conversation.
 *
 * @mixin \App\Models\Conversation
 */
class ConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $me = $request->user();
        $iAmBuyer = $me?->id === $this->buyer_id;

        // A buyer sees the store; a seller sees the buyer's first name and initial.
        $otherName = $iAmBuyer
            ? ($this->seller?->sellerProfile?->store_name ?? ReviewResource::displayName($this->seller?->name))
            : ReviewResource::displayName($this->buyer?->name);

        $last = $this->latestMessage;

        return [
            'id' => $this->id,
            'kind' => $this->isOrderChat() ? 'order' : 'listing',
            'other_party' => [
                'name' => $otherName,
                'store_slug' => $iAmBuyer ? $this->seller?->sellerProfile?->slug : null,
            ],
            'listing' => $this->listing ? [
                'id' => $this->listing->id,
                'slug' => $this->listing->slug,
                'title' => $this->listing->title,
            ] : null,
            'order_number' => $this->order?->order_number,
            'last_message' => $last ? [
                'preview' => Str::limit($last->body, 100),
                'is_mine' => $last->sender_id === $me?->id,
                'sent_at' => $last->created_at?->toIso8601String(),
            ] : null,
            'unread_count' => (int) ($this->unread_count ?? 0),
            'last_message_at' => $this->last_message_at?->toIso8601String(),
        ];
    }
}