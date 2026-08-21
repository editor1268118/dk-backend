<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Phase 11: In-App Notification Service
 *
 * All notification creation is wrapped in try/catch so it NEVER
 * fails the parent action (booking, order, payout, etc.).
 *
 * No SMS, WhatsApp, email, browser push, or WebSocket integrations.
 * Database notifications only, consumed via frontend polling.
 */
class NotificationService
{
    /**
     * Create a single in-app notification.
     *
     * @param int    $userId
     * @param string $title
     * @param string|null $message
     * @param string $type
     * @param array  $options  Optional: priority, entity_type, entity_id, action_url, data
     * @return Notification|null
     */
    public function createNotification(
        int $userId,
        string $title,
        ?string $message,
        string $type,
        array $options = []
    ): ?Notification {
        try {
            return Notification::create([
                'user_id'     => $userId,
                'title'       => $title,
                'message'     => $message,
                'type'        => $type,
                'priority'    => $options['priority'] ?? Notification::PRIORITY_NORMAL,
                'entity_type' => $options['entity_type'] ?? null,
                'entity_id'   => $options['entity_id'] ?? null,
                'action_url'  => $options['action_url'] ?? null,
                'data'        => $options['data'] ?? null,
            ]);
        } catch (\Exception $e) {
            Log::warning("NotificationService: Failed to create notification for user {$userId}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Notify a specific user (alias for clarity).
     */
    public function notifyUser(
        int $userId,
        string $title,
        ?string $message,
        string $type,
        array $options = []
    ): ?Notification {
        return $this->createNotification($userId, $title, $message, $type, $options);
    }

    /**
     * Notify a provider/professional (semantic alias).
     */
    public function notifyProvider(
        int $providerUserId,
        string $title,
        ?string $message,
        string $type,
        array $options = []
    ): ?Notification {
        return $this->createNotification($providerUserId, $title, $message, $type, $options);
    }

    /**
     * Notify all admin users.
     *
     * Finds all users with role slug = 'admin' and creates a notification for each.
     */
    public function notifyAdmins(
        string $title,
        ?string $message,
        string $type,
        array $options = []
    ): void {
        try {
            $adminUserIds = User::whereHas('roles', function ($q) {
                $q->where('slug', 'admin');
            })->pluck('id');

            foreach ($adminUserIds as $adminId) {
                $this->createNotification($adminId, $title, $message, $type, $options);
            }
        } catch (\Exception $e) {
            Log::warning("NotificationService: Failed to notify admins: " . $e->getMessage());
        }
    }
}
