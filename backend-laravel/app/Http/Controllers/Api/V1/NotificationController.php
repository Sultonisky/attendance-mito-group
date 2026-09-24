<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Notification\AdminNotificationResource;
use App\Services\Notification\SyncAdminNotificationFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * SUPER_ADMIN notification inbox.
 *
 * Authorization is enforced by `role:SUPER_ADMIN` on the route.
 */
class NotificationController extends Controller
{
    public function index(Request $request, SyncAdminNotificationFeed $sync): JsonResponse
    {
        $user = $request->user();
        $sync->execute($user);

        $notifications = $user->notifications()
            ->latest()
            ->limit(50)
            ->get();

        return response()->json([
            'success' => true,
            'data' => AdminNotificationResource::collection($notifications),
            'meta' => [
                'unread_count' => $user->unreadNotifications()->count(),
            ],
        ]);
    }

    public function unreadCount(Request $request, SyncAdminNotificationFeed $sync): JsonResponse
    {
        $user = $request->user();
        $sync->execute($user);

        return response()->json([
            'success' => true,
            'data' => [
                'unread_count' => $user->unreadNotifications()->count(),
            ],
        ]);
    }

    public function markRead(Request $request, string $notification): JsonResponse
    {
        $row = $this->findOwnedNotification($request, $notification);
        $row->markAsRead();

        return response()->json([
            'success' => true,
            'data' => new AdminNotificationResource($row->fresh()),
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json([
            'success' => true,
            'data' => [
                'unread_count' => 0,
            ],
        ]);
    }

    private function findOwnedNotification(Request $request, string $id): DatabaseNotification
    {
        /** @var DatabaseNotification $notification */
        $notification = $request->user()
            ->notifications()
            ->whereKey($id)
            ->firstOrFail();

        return $notification;
    }
}
