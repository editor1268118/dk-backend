<?php

namespace Tests\Feature;

use App\Models\{User, Role, PlatformService, ServiceBooking, Notification};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingRescheduleFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_reschedule_reaches_assigned_professional_and_can_be_accepted(): void
    {
        $customer = User::factory()->create();
        $provider = User::factory()->create();
        $otherProvider = User::factory()->create();
        $role = Role::create(['name' => 'Provider', 'slug' => 'provider']);
        $provider->roles()->attach($role);
        $otherProvider->roles()->attach($role);
        $service = PlatformService::create([
            'name' => 'Test service', 'slug' => 'test-reschedule', 'customer_price' => 100,
            'vendor_payout_percentage' => 80, 'platform_percentage' => 20,
            'is_active' => true, 'requires_start_otp' => true,
        ]);
        $booking = ServiceBooking::create([
            'booking_reference' => 'DK-TEST-RESCHEDULE', 'user_id' => $customer->id,
            'platform_service_id' => $service->id, 'assigned_provider_user_id' => $provider->id,
            'preferred_date' => now()->addDays(3)->toDateString(), 'preferred_time' => '12:00',
            'customer_price' => 100, 'vendor_payout_percentage' => 80,
            'vendor_expected_payout' => 80, 'platform_amount' => 20,
            'payment_status' => 'paid', 'status' => 'confirmed',
        ]);
        Sanctum::actingAs($customer);
        $this->postJson("/api/bookings/{$booking->id}/reschedule", [
            'preferred_date' => now()->addDays(4)->toDateString(),
            'preferred_time' => '14:00', 'reason' => 'Customer schedule changed',
        ])->assertOk();
        $this->assertSame('reschedule_pending_provider_confirmation', $booking->fresh()->status);
        $this->assertNull($booking->fresh()->reschedule_reconfirmation_deadline_at);
        // Legacy requests with an elapsed deadline must remain actionable too.
        $booking->update(['reschedule_reconfirmation_deadline_at' => now()->subDay()]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $provider->id, 'entity_id' => $booking->id,
            'action_url' => '/dashboard?tab=bookings_received',
        ]);
        Sanctum::actingAs($provider);
        $this->postJson('/api/system/bookings/auto-confirm-expired-windows')->assertForbidden();
        $this->getJson('/api/professional/bookings')->assertOk()
            ->assertJsonPath('bookings.0.id', $booking->id)
            ->assertJsonPath('bookings.0.can_accept_rescheduled_time', true)
            ->assertJsonPath('bookings.0.preferred_time', '14:00');
        Sanctum::actingAs($otherProvider);
        $this->getJson('/api/professional/bookings')->assertOk()->assertJsonCount(0, 'bookings');
        Sanctum::actingAs($provider);
        $this->postJson("/api/professional/bookings/{$booking->id}/accept-rescheduled-time")->assertOk();
        $this->assertSame('confirmed', $booking->fresh()->status);
        $this->assertNotEmpty($booking->fresh()->start_otp);

        // The scheduler must confirm with an OTP, not just change the status.
        $booking->update(['status' => 'vendor_accepted', 'action_window_ends_at' => now()->subMinute(), 'start_otp' => null]);
        $this->artisan('bookings:auto-confirm')->assertExitCode(0);
        $this->assertSame('confirmed', $booking->fresh()->status);
        $this->assertNotEmpty($booking->fresh()->start_otp);

        $booking->update(['status' => 'reschedule_pending_provider_confirmation', 'reschedule_reconfirmation_deadline_at' => now()->subMinute()]);
        $this->artisan('bookings:auto-confirm')->assertExitCode(0);
        $this->assertSame($provider->id, $booking->fresh()->assigned_provider_user_id);
        $this->assertSame('reschedule_pending_provider_confirmation', $booking->fresh()->status);
    }
}
