<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \Illuminate\Notifications\DatabaseNotification */
class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = (array) $this->data;

        return [
            'id' => $this->id,
            'kind' => $data['kind'] ?? null,   // e.g. "order.paid": the frontend can pick an icon from it
            'title' => $data['title'] ?? null,
            'body' => $data['body'] ?? null,
            'link' => $data['link'] ?? null,   // a frontend path, or null
            'meta' => $data['meta'] ?? [],
            'is_read' => $this->read_at !== null,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}