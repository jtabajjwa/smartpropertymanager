<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Mail\PasswordResetCodeMail;
use App\Models\PasswordResetCode;
use App\Models\User;
use App\Services\CurrentLandlord;
use App\Support\PlanFeatures;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Mobile app sign-in for landlords and their staff: one Sanctum token per
 * device. The same checks as the web sign-in (active user), plus the setup
 * steps the app walks through (verify email, change password, accept terms).
 */
class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'],
        ]);

        $user = User::where('email', $data['email'])->first();
        if (!$user || !Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'Invalid email or password.']);
        }
        if (!$user->isActive()) {
            throw ValidationException::withMessages(['email' => 'Your account has been deactivated. Contact an administrator.']);
        }

        // One token per device: signing in again on the same device replaces it.
        $user->tokens()->where('name', $data['device_name'])->delete();
        $token = $user->createToken($data['device_name'], ['landlord'])->plainTextToken;

        return response()->json(['token' => $token] + $this->profile($user));
    }

    public function me(Request $request)
    {
        return response()->json($this->profile($request->user()));
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Signed out.']);
    }

    public function changePassword(Request $request)
    {
        $user = $request->user();
        $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', Password::defaults(), 'confirmed', 'different:current_password'],
        ]);
        if (!Hash::check($request->current_password, $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'That is not your current password.']);
        }
        $user->update(['password' => $request->password, 'must_change_password' => false]);

        // Other devices sign in again with the new password.
        $user->tokens()->where('id', '!=', $user->currentAccessToken()?->id)->delete();

        return response()->json(['message' => 'Password changed.'] + $this->profile($user->fresh()));
    }

    public function acceptTerms(Request $request)
    {
        $request->validate(['terms' => ['accepted']], ['terms.accepted' => 'Please agree to the Terms of Service and Privacy Policy.']);
        $user = $request->user();
        if ($user->isLandlordAdmin() && $user->landlord) {
            $user->landlord->acceptTerms($user, $request->ip());
        }

        return response()->json($this->profile($user->fresh()));
    }

    public function resendVerification(Request $request)
    {
        $user = $request->user();
        if (!$user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return response()->json(['message' => 'We sent a new verification link to '.$user->email.'.']);
    }

    /** A 6-digit code by email, as on the web (same codes, 10 minutes). */
    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => ['required', 'email']]);
        $user = User::where('email', $request->email)->first();

        if ($user && $user->isActive()) {
            PasswordResetCode::where('user_id', $user->id)->where('used', false)->update(['used' => true]);
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            PasswordResetCode::create(['user_id' => $user->id, 'code' => $code, 'expires_at' => now()->addMinutes(10)]);
            Mail::to($user->email)->send(new PasswordResetCodeMail($user->name, $code));
        }

        return response()->json(['message' => 'If an account uses that email, we sent it a 6-digit code.']);
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'digits:6'],
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
        ]);
        $user = User::where('email', $request->email)->first();
        $reset = $user ? PasswordResetCode::where('user_id', $user->id)->where('code', $request->code)
            ->where('used', false)->where('expires_at', '>', now())->latest()->first() : null;

        if (!$reset) {
            throw ValidationException::withMessages(['code' => 'Invalid or expired code.']);
        }
        $reset->update(['used' => true]);
        $user->update(['password' => $request->password, 'must_change_password' => false]);
        $user->tokens()->delete();

        return response()->json(['message' => 'Password reset. Sign in with your new password.']);
    }

    /** Who's signed in, their organisation, what their plan includes, and any setup step still to do. */
    protected function profile(User $user): array
    {
        $landlord = $user->landlord;
        if ($landlord) {
            CurrentLandlord::set($landlord->id);
        }

        return [
            'user' => [
                'id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'phone' => $user->phone,
                'role' => $user->role, 'is_admin' => $user->isAdmin(),
            ],
            'landlord' => $landlord ? [
                'id' => $landlord->id,
                'name' => $landlord->name,
                'logo_url' => $landlord->logoUrl(),
                'plan' => $landlord->plan?->name,
                'subscription_state' => $landlord->subscriptionState(),
                'access_level' => $landlord->accessLevel(),
                'read_only' => $landlord->isReadOnly(),
                'current_period_end' => $landlord->current_period_end?->toDateString(),
            ] : null,
            'features' => $landlord ? collect(array_keys(PlanFeatures::FEATURES))->filter(fn ($f) => PlanFeatures::allows($f))->values() : [],
            'setup' => [
                'email_verified' => $user->hasVerifiedEmail(),
                'must_change_password' => (bool) $user->must_change_password,
                'terms_required' => $user->isLandlordAdmin() && $landlord && !$landlord->hasAcceptedCurrentTerms(),
                'terms_url' => config('legal.terms_url'),
                'privacy_url' => config('legal.privacy_url'),
            ],
        ];
    }
}
