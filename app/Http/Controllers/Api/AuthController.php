<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(RegisterRequest $request)
    {
        try {
            DB::beginTransaction();

            // 1. Create User
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'phone' => $request->phone,
            ]);

            // 2. Assign Roles
            $roles = Role::whereIn('slug', $request->roles)->get();
            $user->roles()->attach($roles->pluck('id'));

            // 3. Create UserProfile (Base)
            $user->userProfile()->create($request->only([
                'first_name',
                'last_name',
                'gender',
                'date_of_birth',
                'address_line_1',
                'address_line_2',
                'city',
                'state',
                'country',
                'postal_code'
            ]));

            // 4. Conditionally create ProviderProfile
            if ($user->hasRole('provider')) {
                $user->providerProfile()->create($request->only([
                    'business_name',
                    'professional_title',
                    'category',
                    'bio',
                    'experience_years'
                ]));
            }

            // 5. Conditionally create FundraiserProfile
            if ($user->hasAnyRole(['fundraiser', 'institution'])) {
                $user->fundraiserProfile()->create($request->only([
                    'fundraiser_type',
                    'organization_name',
                    'registration_number',
                    'cause_title',
                    'cause_description'
                ]));
            }

            DB::commit();

            // Load relations and create token
            $user->load(['roles', 'userProfile', 'providerProfile', 'fundraiserProfile']);
            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'message' => 'Registration successful',
                'access_token' => $token,
                'token_type' => 'Bearer',
                'user' => $user
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Registration failed: ' . $e->getMessage()], 500);
        }
    }

    public function login(LoginRequest $request)
    {
        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $user->load(['roles', 'userProfile', 'providerProfile', 'fundraiserProfile']);
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Successfully logged out'
        ]);
    }

    public function me(Request $request)
    {
        $user = $request->user()->load(['roles', 'userProfile', 'providerProfile', 'fundraiserProfile']);
        return response()->json([
            'user' => $user
        ]);
    }
}
