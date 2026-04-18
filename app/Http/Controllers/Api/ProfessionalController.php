<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\RegisterProfessionalRequest;
use App\Http\Requests\Api\UpdateProfessionalProfileRequest;
use Illuminate\Http\Request;
use App\Models\Role;

class ProfessionalController extends Controller
{
    /**
     * Register an authenticated user as a professional/provider.
     */
    public function registerProfessional(RegisterProfessionalRequest $request)
    {
        $user = $request->user();

        // 1. Assign provider role explicitly if not already assigned
        $providerRole = Role::where('slug', 'provider')->first();
        if ($providerRole && !$user->roles->contains('id', $providerRole->id)) {
            $user->roles()->attach($providerRole->id);
            // Refresh user roles relationship so it propagates down into the response correctly
            $user->load('roles');
        }

        // 2. Prevent duplicate provider profile creation
        if ($user->providerProfile) {
            return response()->json([
                'message' => 'You are already registered as a professional.',
            ], 400); // 400 Bad Request
        }

        // 3. Create the provider profile
        $providerProfile = $user->providerProfile()->create([
            'professional_title'  => $request->professional_title,
            'business_name'       => $request->business_name,
            'category'            => $request->category,
            'bio'                 => $request->bio,
            'experience_years'    => $request->experience_years,
            'is_verified'         => false,
            'verification_status' => 'pending',
        ]);

        return response()->json([
            'message'         => 'Successfully registered as a professional.',
            'user'            => $user->only(['id', 'name', 'email', 'phone', 'status']),
            'roles'           => $user->roles->pluck('slug'),
            'providerProfile' => $providerProfile,
        ], 201);
    }

    /**
     * Get the current user's professional profile.
     */
    public function getProfile(Request $request)
    {
        $user = $request->user();

        $providerProfile = $user->providerProfile;

        if (!$providerProfile) {
            return response()->json([
                'message' => 'Professional profile not found.',
            ], 404);
        }

        return response()->json([
            'user'            => $user->only(['id', 'name', 'email', 'phone', 'status']),
            'providerProfile' => $providerProfile,
        ], 200);
    }

    /**
     * Update the current user's professional profile.
     */
    public function updateProfile(UpdateProfessionalProfileRequest $request)
    {
        $user = $request->user();

        $providerProfile = $user->providerProfile;

        if (!$providerProfile) {
            return response()->json([
                'message' => 'Professional profile not found.',
            ], 404);
        }

        // Update allowable fields
        $providerProfile->update([
            'professional_title' => $request->professional_title,
            'business_name'      => $request->business_name,
            'category'           => $request->category,
            'bio'                => $request->bio,
            'experience_years'   => $request->experience_years,
        ]);

        return response()->json([
            'message'         => 'Professional profile updated successfully.',
            'providerProfile' => $providerProfile,
        ], 200);
    }
}
