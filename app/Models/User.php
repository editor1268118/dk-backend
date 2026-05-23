<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Models\ServiceBooking;
use App\Models\BookingVendorOffer;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'status',
    ];

    public function roles()
    {
        return $this->belongsToMany(Role::class);
    }

    public function hasRole($roleSlug)
    {
        return $this->roles()->where('slug', $roleSlug)->exists();
    }

    public function hasAnyRole(array $roleSlugs)
    {
        return $this->roles()->whereIn('slug', $roleSlugs)->exists();
    }

    public function assignRole($roleSlug)
    {
        $role = Role::where('slug', $roleSlug)->first();
        if ($role) {
            $this->roles()->syncWithoutDetaching([$role->id]);
        }
    }

    public function userProfile()
    {
        return $this->hasOne(UserProfile::class);
    }

    public function providerProfile()
    {
        return $this->hasOne(ProviderProfile::class);
    }

    public function fundraiserProfile()
    {
        return $this->hasOne(FundraiserProfile::class);
    }

    /**
     * @deprecated Old provider-created services. Deprecated under new flow.
     * Vendors now select from platform_services instead of creating their own.
     */
    public function services()
    {
        return $this->hasMany(Service::class, 'provider_user_id');
    }

    /**
     * Get provider's selected services (pivot records).
     */
    public function providerSelectedServices()
    {
        return $this->hasMany(ProviderSelectedService::class, 'provider_user_id');
    }

    /**
     * Get the platform services this provider has selected.
     */
    public function selectedPlatformServices()
    {
        return $this->belongsToMany(PlatformService::class, 'provider_selected_services', 'provider_user_id', 'platform_service_id')
            ->withPivot('is_active')
            ->withTimestamps();
    }

    /**
     * Get the provider's service areas.
     */
    public function providerServiceAreas()
    {
        return $this->hasMany(ProviderServiceArea::class, 'provider_user_id');
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * Service bookings made by this user (new flow).
     */
    public function serviceBookings()
    {
        return $this->hasMany(ServiceBooking::class);
    }

    /**
     * Service bookings assigned to this provider (new flow).
     */
    public function assignedServiceBookings()
    {
        return $this->hasMany(ServiceBooking::class, 'assigned_provider_user_id');
    }

    /**
     * Job offers received by this provider.
     */
    public function receivedBookingOffers()
    {
        return $this->hasMany(BookingVendorOffer::class, 'provider_user_id');
    }

    public function donationsMade()
    {
        return $this->hasMany(Donation::class, 'donor_user_id');
    }

    public function donationsReceived()
    {
        return $this->hasMany(Donation::class, 'fundraiser_user_id');
    }

    /**
     * @deprecated Old availability slots. Deprecated under new flow.
     * Vendors no longer create availability slots directly.
     */
    public function serviceAvailabilities()
    {
        return $this->hasMany(ServiceAvailability::class, 'provider_user_id');
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    public function bookingsReceived()
    {
        return $this->hasMany(Booking::class, 'provider_user_id');
    }

    public function professionalWallet()
    {
        return $this->hasOne(ProfessionalWallet::class, 'provider_user_id');
    }

    public function professionalWalletTransactions()
    {
        return $this->hasMany(ProfessionalWalletTransaction::class, 'provider_user_id');
    }
}
