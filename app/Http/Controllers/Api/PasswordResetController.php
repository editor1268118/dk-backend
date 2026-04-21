<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ForgotPasswordRequest;
use App\Http\Requests\Api\ResetPasswordRequest;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Hash;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Str;
use App\Models\User;

class PasswordResetController extends Controller
{
    /**
     * Send a reset link to the given user.
     *
     * @param  \App\Http\Requests\Api\ForgotPasswordRequest  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function forgotPassword(ForgotPasswordRequest $request)
    {
        $status = Password::broker()->sendResetLink(
            $request->only('email')
        );

        if ($status == Password::RESET_LINK_SENT) {
            return response()->json([
                'message' => 'Password reset link sent successfully.'
            ], 200);
        }

        // Technically, Laravel standard returns a validation error format or simple error
        return response()->json([
            'message' => __($status)
        ], 400);
    }

    /**
     * Reset the given user's password.
     *
     * @param  \App\Http\Requests\Api\ResetPasswordRequest  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function resetPassword(ResetPasswordRequest $request)
    {
        $status = Password::broker()->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->forceFill([
                    'password' => Hash::make($password)
                ])->setRememberToken(Str::random(60));

                $user->save();

                event(new PasswordReset($user));
            }
        );

        if ($status == Password::PASSWORD_RESET) {
            return response()->json([
                'message' => 'Password has been reset successfully.'
            ], 200);
        }

        return response()->json([
            'message' => __($status)
        ], 400);
    }
}
