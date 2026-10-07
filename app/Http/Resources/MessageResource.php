<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Message */
class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $mine = $this->sender_id === $request->user()?->id;

        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'body' => $this->body,
            'is_mine' => $mine,
            // The sender can see whether the other side has read it. The recipient's own reading status stays private.
            'is_read' => $mine ? $this->read_at !== null : null,
            'sent_at' => $this->created_at?->toIso8601String(),
        ];
    }
}