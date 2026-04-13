<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

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

    public function services()
    {
        return $this->hasMany(Service::class, 'provider_user_id');
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
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
}
