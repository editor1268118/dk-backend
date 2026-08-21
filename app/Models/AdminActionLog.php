<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AdminActionLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'admin_user_id',
        'action',
        'entity_type',
        'entity_id',
        'description',
        'old_values',
        'new_values',
        'metadata',
    ];

    /**
     * The admin user who performed the action.
     */
    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }

    /**
     * Log a new admin action.
     *
     * @param  int         $adminUserId
     * @param  string      $action       e.g. 'issue_resolved', 'issue_rejected', 'reschedule_expired'
     * @param  string      $entityType   e.g. 'service_booking'
     * @param  int         $entityId
     * @param  string|null $description  Human-readable note
     * @param  array|null  $metadata     Extra context (will be JSON-encoded)
     * @param  array|null  $oldValues    Previous values before the change
     * @param  array|null  $newValues    New values after the change
     * @return static
     */
    public static function record(
        int $adminUserId,
        string $action,
        string $entityType,
        int $entityId,
        ?string $description = null,
        ?array $metadata = null,
        ?array $oldValues = null,
        ?array $newValues = null
    ): self {
        return self::create([
            'admin_user_id' => $adminUserId,
            'action'        => $action,
            'entity_type'   => $entityType,
            'entity_id'     => $entityId,
            'description'   => $description,
            'old_values'    => $oldValues ? json_encode($oldValues) : null,
            'new_values'    => $newValues ? json_encode($newValues) : null,
            'metadata'      => $metadata ? json_encode($metadata) : null,
        ]);
    }
}
