<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ServiceBooking extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_reference',
        'user_id',
        'platform_service_id',
        'assigned_provider_user_id',
        'customer_name',
        'customer_phone',
        'customer_email',
        'service_country',
        'service_state',
        'service_city',
        'service_pincode',
        'service_address_line_1',
        'service_address_line_2',
        'preferred_date',
        'preferred_time',
        'customer_price',
        'vendor_payout_percentage',
        'vendor_expected_payout',
        'platform_amount',
        'payment_status',
        'status',
        'notes',
        'cancellation_rule_version',
        'vendor_accepted_at',
        'action_window_ends_at',
        'confirmed_at',
        'vendor_window_cancelled_at',
        'vendor_change_requested_at',
        'vendor_change_reason',
        'cancellation_reason',
        'cancelled_at',
        // Phase 6: OTP & Job lifecycle fields
        'otp_required',
        'start_otp',
        'start_otp_generated_at',
        'start_otp_verified_at',
        'job_started_at',
        'job_completed_at',
        'no_start_marked_at',
        // Phase 7: Payout & Review fields
        'payout_status',
        'payout_released_at',
        'payout_release_reference',
        'customer_review_submitted_at',
        // Phase 8: Cancel, Reschedule, Issue Reporting
        'cancelled_by',
        'cancellation_fee',
        'refund_amount',
        'cancellation_policy_snapshot',
        'reschedule_count',
        'last_rescheduled_at',
        'original_preferred_date',
        'original_preferred_time',
        'reschedule_reason',
        'previous_assigned_provider_user_id',
        'reschedule_reconfirmation_deadline_at',
        'reschedule_reconfirmed_at',
        'issue_reported_at',
        'issue_status',
        'issue_description',
        // Phase 9: Admin issue resolution fields
        'issue_resolution_note',
        'issue_resolved_at',
        'issue_resolved_by',
        // Phase 9: Admin control panel fields
        'refund_status',
        'refund_note',
    ];

    protected $casts = [
        'preferred_date' => 'date',
        'customer_price' => 'decimal:2',
        'vendor_payout_percentage' => 'decimal:2',
        'vendor_expected_payout' => 'decimal:2',
        'platform_amount' => 'decimal:2',
        'vendor_accepted_at' => 'datetime',
        'action_window_ends_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'vendor_window_cancelled_at' => 'datetime',
        'vendor_change_requested_at' => 'datetime',
        'cancelled_at' => 'datetime',
        // Phase 6: OTP & Job lifecycle casts
        'otp_required' => 'boolean',
        'start_otp_generated_at' => 'datetime',
        'start_otp_verified_at' => 'datetime',
        'job_started_at' => 'datetime',
        'job_completed_at' => 'datetime',
        'no_start_marked_at' => 'datetime',
        // Phase 7: Payout & Review casts
        'payout_released_at' => 'datetime',
        'customer_review_submitted_at' => 'datetime',
        // Phase 8: Casts
        'cancellation_fee' => 'decimal:2',
        'refund_amount' => 'decimal:2',
        'last_rescheduled_at' => 'datetime',
        'original_preferred_date' => 'date',
        'reschedule_reconfirmation_deadline_at' => 'datetime',
        'reschedule_reconfirmed_at' => 'datetime',
        'issue_reported_at' => 'datetime',
        // Phase 9: Admin issue resolution casts
        'issue_resolved_at' => 'datetime',
    ];

    /**
     * Booking status constants.
     */
    const STATUS_JOB_CREATED = 'job_created';
    const STATUS_PAYMENT_PENDING = 'payment_pending';
    const STATUS_PAID = 'paid';
    const STATUS_BROADCASTED = 'broadcasted';
    const STATUS_VENDOR_ACCEPTED = 'vendor_accepted';
    const STATUS_CONFIRMED = 'confirmed';
    const STATUS_CANCELLED_WITHIN_WINDOW = 'cancelled_within_window';

    const STATUS_NO_VENDOR_AVAILABLE = 'no_vendor_available';
    const STATUS_CANCELLED_BY_VENDOR = 'cancelled_by_vendor';
    
    const STATUS_RESCHEDULE_PENDING_PROVIDER_CONFIRMATION = 'reschedule_pending_provider_confirmation';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_REVIEW_PENDING = 'review_pending'; // Kept for backward compat; Phase 7 treats as completed
    const STATUS_FAILED_NO_START = 'failed_no_start';
    // Phase 7: Completed status
    // Phase 7: Completed status
    const STATUS_COMPLETED = 'completed';
    // Phase 8: Cancel, Reschedule, Issue statuses
    const STATUS_CANCELLED_BY_USER = 'cancelled_by_user';
    const STATUS_RESCHEDULED = 'rescheduled';
    const STATUS_ISSUE_REPORTED = 'issue_reported';
    // Phase 9: Admin cancellation
    const STATUS_CANCELLED_BY_ADMIN = 'cancelled_by_admin';

    const MAX_RESCHEDULES = 3;

    /**
     * Payout status constants.
     */
    const PAYOUT_PENDING = 'pending';
    const PAYOUT_RELEASED = 'released';
    const PAYOUT_ON_HOLD = 'on_hold';

    /**
     * Payment status constants.
     */
    const PAYMENT_PENDING = 'pending';
    const PAYMENT_PAID = 'paid';

    /**
     * Generate a unique booking reference.
     */
    public static function generateBookingReference(): string
    {
        do {
            $reference = 'DK-' . strtoupper(Str::random(8));
        } while (self::where('booking_reference', $reference)->exists());

        return $reference;
    }

    // ==========================================
    // RELATIONSHIPS
    // ==========================================

    /**
     * All wallet transactions associated with this booking.
     */
    public function walletTransactions()
    {
        return $this->hasMany(ProfessionalWalletTransaction::class, 'booking_id');
    }

    /**
     * The user (customer) who made this booking.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The platform service booked.
     */
    public function platformService()
    {
        return $this->belongsTo(PlatformService::class);
    }

    /**
     * The provider assigned to this booking (nullable until accepted).
     */
    public function assignedProvider()
    {
        return $this->belongsTo(User::class, 'assigned_provider_user_id');
    }

    /**
     * All vendor offers for this booking.
     */
    public function vendorOffers()
    {
        return $this->hasMany(BookingVendorOffer::class, 'booking_id');
    }

    /**
     * The customer review for this booking (Phase 7).
     */
    public function review()
    {
        return $this->hasOne(BookingReview::class, 'booking_id');
    }

    // ==========================================
    // HELPER METHODS
    // ==========================================

    /**
     * Check if a vendor has already been assigned.
     */
    public function isAssigned(): bool
    {
        return !is_null($this->assigned_provider_user_id);
    }

    /**
     * Check if the booking is in a broadcastable state.
     */
    public function isBroadcastable(): bool
    {
        return in_array($this->status, [self::STATUS_PAID, self::STATUS_BROADCASTED]);
    }

    // ==========================================
    // PHASE 6: OTP & JOB HELPERS
    // ==========================================

    /**
     * Generate a 6-digit start OTP for this booking.
     * Saves the OTP and generation timestamp.
     */
    public function generateStartOtp(): self
    {
        $this->update([
            'start_otp' => str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT),
            'start_otp_generated_at' => now(),
        ]);

        return $this;
    }

    /**
     * Check if the vendor can start this job.
     * Job can be started only when status is confirmed.
     */
    public function canStartJob(): bool
    {
        return $this->status === self::STATUS_CONFIRMED;
    }

    /**
     * Check if the vendor can complete this job.
     * Job can be completed only when status is in_progress.
     */
    public function canCompleteJob(): bool
    {
        return $this->status === self::STATUS_IN_PROGRESS;
    }

    /**
     * Check if this booking is in a completed-equivalent state.
     * Phase 7: treats both 'completed' and legacy 'review_pending' as completed.
     */
    public function isCompleted(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_REVIEW_PENDING]);
    }

    /**
     * Check if a customer can submit a review for this booking.
     * Review is allowed only after completion and if no review exists yet.
     */
    public function canSubmitReview(int $userId): bool
    {
        return $this->isCompleted()
            && $this->user_id === $userId
            && !$this->review;
    }

    // ==========================================
    // PHASE 8: CANCEL, RESCHEDULE, ISSUE HELPERS
    // ==========================================

    /**
     * Get the combined preferred date and time as a Carbon instance.
     */
    public function getServiceDateTime(): ?\Carbon\Carbon
    {
        if (!$this->preferred_date || !$this->preferred_time) {
            return null;
        }

        try {
            return \Carbon\Carbon::parse($this->preferred_date->format('Y-m-d') . ' ' . $this->preferred_time);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Check if customer can cancel this booking.
     */
    public function canCustomerCancel(): bool
    {
        return in_array($this->status, [
            self::STATUS_BROADCASTED,
            self::STATUS_VENDOR_ACCEPTED,
            self::STATUS_CONFIRMED,
        ]);
    }

    /**
     * Check if customer can reschedule this booking.
     */
    public function canCustomerReschedule(): bool
    {
        return $this->canCustomerCancel() && $this->reschedule_count < self::MAX_RESCHEDULES;
    }

    /**
     * Check if customer can report an issue (Phase 8).
     */
    public function canReportIssue(int $userId): bool
    {
        return $this->isCompleted()
            && $this->user_id === $userId
            && is_null($this->issue_reported_at);
    }
}
