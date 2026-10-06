<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class NotificationController extends Controller
{
    /** The signed-in user's inbox, newest first. Add ?unread=1 to see only unread ones. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $query = $request->boolean('unread') ? $user->unreadNotifications() : $user->notifications();

        return NotificationResource::collection($query->latest()->paginate(20)->withQueryString())
            ->additional(['unread_count' => $user->unreadNotifications()->count()]);
    }

    /** Just the number, for the bell icon. */
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['data' => ['unread_count' => $request->user()->unreadNotifications()->count()]]);
    }

    public function markRead(Request $request, string $notification): NotificationResource
    {
        $found = $request->user()->notifications()->whereKey($notification)->firstOrFail(); // only their own

        $found->markAsRead();

        return new NotificationResource($found->fresh());
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['data' => ['unread_count' => 0]]);
    }
}