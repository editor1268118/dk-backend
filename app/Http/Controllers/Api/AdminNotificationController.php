<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\Request;

/**
 * Phase 11: Admin Notification Controller
 *
 * All endpoints require auth:sanctum + admin middleware.
 * Admin can view all notifications across all users.
 */
class AdminNotificationController extends Controller
{
    /**
     * GET /api/admin/control/notifications
     *
     * List all notifications with filters and pagination.
     */
    public function index(Request $request)
    {
        $query = Notification::with('user:id,name,email');

        // Filter by user
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        // Filter by type
        if ($request->filled('type')) {
            $query->ofType($request->type);
        }

        // Filter by priority
        if ($request->filled('priority')) {
            $query->ofPriority($request->priority);
        }

        // Filter by read status
        if ($request->filled('read_status')) {
            switch ($request->read_status) {
                case 'read':
                    $query->read();
                    break;
                case 'unread':
                    $query->unread();
                    break;
            }
        }

        // Date filters
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        // Search (in title, message)
        if ($request->filled('search')) {
            $search = '%' . $request->search . '%';
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', $search)
                  ->orWhere('message', 'like', $search)
                  ->orWhereHas('user', function ($uq) use ($search) {
                      $uq->where('name', 'like', $search)
                         ->orWhere('email', 'like', $search);
                  });
            });
        }

        $perPage = min((int) $request->input('per_page', 20), 100);
        $notifications = $query->orderByDesc('created_at')->paginate($perPage);

        $items = collect($notifications->items())->map(function ($notification) {
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
                'recipient'   => $notification->user ? [
                    'id'    => $notification->user->id,
                    'name'  => $notification->user->name,
                    'email' => $notification->user->email,
                ] : null,
                'created_at'  => $notification->created_at,
            ];
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
     * GET /api/admin/control/notifications/overview
     *
     * Return notification statistics and latest admin alerts.
     */
    public function overview(Request $request)
    {
        $totalNotifications = Notification::count();
        $unreadNotifications = Notification::unread()->count();
        $urgentNotifications = Notification::ofPriority(Notification::PRIORITY_URGENT)->count();
        $highPriorityNotifications = Notification::ofPriority(Notification::PRIORITY_HIGH)->count();
        $todayNotifications = Notification::whereDate('created_at', today())->count();

        // Notifications by type
        $notificationsByType = Notification::selectRaw('type, count(*) as count')
            ->groupBy('type')
            ->orderByDesc('count')
            ->pluck('count', 'type');

        // Latest admin alerts (last 10)
        $latestAdminAlerts = Notification::where('type', Notification::TYPE_ADMIN_ALERT)
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->take(10)
            ->get()
            ->map(function ($n) {
                return [
                    'id'         => $n->id,
                    'title'      => $n->title,
                    'message'    => $n->message,
                    'priority'   => $n->priority,
                    'recipient'  => $n->user?->name,
                    'is_read'    => $n->is_read,
                    'created_at' => $n->created_at,
                ];
            });

        return response()->json([
            'overview' => [
                'total_notifications'        => $totalNotifications,
                'unread_notifications'       => $unreadNotifications,
                'urgent_notifications'       => $urgentNotifications,
                'high_priority_notifications'=> $highPriorityNotifications,
                'today_notifications'        => $todayNotifications,
                'notifications_by_type'      => $notificationsByType,
                'latest_admin_alerts'        => $latestAdminAlerts,
            ],
        ], 200);
    }
}
