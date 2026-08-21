<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\Request;

/**
 * Phase 11: Customer / User Notification Controller
 *
 * All endpoints require auth:sanctum.
 * Users can only access their own notifications.
 */
class NotificationController extends Controller
{
    /**
     * GET /api/notifications
     *
     * List authenticated user's notifications with optional filters.
     * Sorted by latest first, paginated.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $query = Notification::forUser($user->id);

        // Filter by read status
        if ($request->filled('read_status')) {
            switch ($request->read_status) {
                case 'read':
                    $query->read();
                    break;
                case 'unread':
                    $query->unread();
                    break;
                // 'all' — no filter
            }
        }

        // Filter by type
        if ($request->filled('type')) {
            $query->ofType($request->type);
        }

        // Filter by priority
        if ($request->filled('priority')) {
            $query->ofPriority($request->priority);
        }

        $perPage = min((int) $request->input('per_page', 20), 50);
        $notifications = $query->orderByDesc('created_at')->paginate($perPage);

        $items = collect($notifications->items())->map(function ($notification) {
            return $this->formatNotification($notification);
        });

        return response()->json([
            'notifications' => $items,
            'pagination' => [
                'current_page' => $notifications->currentPage(),
                'last_page'    => $notifications->lastPage(),
                'per_page'     => $notifications->perPage(),
                'total'        => $notifications->total(),
            ],
        ], 200);
    }

    /**
     * GET /api/notifications/unread-count
     *
     * Return count of unread notifications for the authenticated user.
     */
    public function unreadCount(Request $request)
    {
        $count = Notification::forUser($request->user()->id)
            ->unread()
            ->count();

        return response()->json([
            'unread_count' => $count,
        ], 200);
    }

    /**
     * POST /api/notifications/{id}/mark-read
     *
     * Mark a single notification as read. User can only mark own notification.
     */
    public function markRead(Request $request, $id)
    {
        $notification = Notification::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (!$notification) {
            return response()->json(['message' => 'Notification not found.'], 404);
        }

        if ($notification->read_at) {
            return response()->json([
                'message' => 'Notification already read.',
                'notification' => $this->formatNotification($notification),
            ], 200);
        }

        $notification->update(['read_at' => now()]);

        return response()->json([
            'message' => 'Notification marked as read.',
            'notification' => $this->formatNotification($notification->fresh()),
        ], 200);
    }

    /**
     * POST /api/notifications/mark-all-read
     *
     * Mark all authenticated user's unread notifications as read.
     */
    public function markAllRead(Request $request)
    {
        $count = Notification::forUser($request->user()->id)
            ->unread()
            ->update(['read_at' => now()]);

        return response()->json([
            'message' => "{$count} notifications marked as read.",
            'updated_count' => $count,
        ], 200);
    }

    /**
     * DELETE /api/notifications/{id}
     *
     * Delete a notification. User can only delete own notification.
     */
    public function destroy(Request $request, $id)
    {
        $notification = Notification::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (!$notification) {
            return response()->json(['message' => 'Notification not found.'], 404);
        }

        $notification->delete();

        return response()->json([
            'message' => 'Notification deleted.',
        ], 200);
    }

    /**
     * DELETE /api/notifications/clear-read
     *
     * Delete all read notifications of the authenticated user.
     */
    public function clearRead(Request $request)
    {
        $count = Notification::forUser($request->user()->id)
            ->read()
            ->delete();

        return response()->json([
            'message' => "{$count} read notifications cleared.",
            'deleted_count' => $count,
        ], 200);
    }

    // =========================================================================
    // HELPER: Format notification for API response
    // =========================================================================

    private function formatNotification(Notification $notification): array
    {
        return [
            'id'          => $notification->id,
            'title'       => $notification->title,
            'message'     => $notification->message,
            'type'        => $notification->type,
            'priority'    => $notification->priority,
            'entity_type' => $notification->entity_type,
            'entity_id'   => $notification->entity_id,
            'action_url'  => $notification->action_url,
            'data'        => $notification->data,
            'read_at'     => $notification->read_at,
            'is_read'     => $notification->is_read,
            'created_at'  => $notification->created_at,
        ];
    }
}
