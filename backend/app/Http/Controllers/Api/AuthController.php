<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Otp;
use App\Models\User;
use App\Services\SmsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Normalize Indian phone numbers to standard 10-digit format
     */
    protected function normalizePhone(?string $phone): ?string
    {
        if (!$phone) return null;
        $clean = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($clean) === 12 && str_starts_with($clean, '91')) {
            $clean = substr($clean, 2);
        }
        if (strlen($clean) === 11 && str_starts_with($clean, '0')) {
            $clean = substr($clean, 1);
        }
        return $clean;
    }

    /**
     * Dispatch SMS OTP for registration, login, or password reset
     */
    public function sendOtp(Request $request, SmsService $smsService)
    {
        if ($request->has('phone')) {
            $request->merge(['phone' => $this->normalizePhone($request->phone)]);
        }

        $request->validate([
            'phone' => ['required', 'string', 'regex:/^[6-9]\d{9}$/'],
            'purpose' => ['required', 'string', 'in:register,login,reset_password'],
        ], [
            'phone.regex' => 'Please provide a valid 10-digit Indian mobile number.',
        ]);

        $phone = preg_replace('/[^0-9]/', '', $request->phone);
        $purpose = $request->purpose;

        if ($purpose === 'register') {
            if (User::where('phone', $phone)->exists()) {
                throw ValidationException::withMessages([
                    'phone' => ['An account with this mobile number already exists. Please log in.'],
                ]);
            }
        } elseif ($purpose === 'login' || $purpose === 'reset_password') {
            if (!User::where('phone', $phone)->exists()) {
                throw ValidationException::withMessages([
                    'phone' => ['No account found associated with this mobile number.'],
                ]);
            }
        }

        // Generate OTP
        $otp = Otp::generate($phone, $purpose, $request->ip(), 5);

        // Dispatch SMS
        $result = $smsService->sendOtp($phone, $otp->otp, $purpose);

        if (!($result['success'] ?? false)) {
            // Clean up un-dispatched OTP so user is not blocked by cooldown
            $otp->delete();

            return response()->json([
                'status' => 'error',
                'message' => $result['message'] ?? 'Failed to deliver OTP to your mobile. Please try again.',
            ], 400);
        }

        $devOtp = null;
        if (app()->isLocal() && config('app.debug') && config('services.sms.driver') === 'local') {
            $devOtp = $result['dev_otp'] ?? null;
        }

        return response()->json([
            'status' => 'success',
            'message' => $result['message'] ?? 'OTP has been dispatched to your mobile number.',
            'phone' => $phone,
            'expires_in' => 300,
            'dev_otp' => $devOtp,
        ]);
    }

    /**
     * Verify an OTP directly
     */
    public function verifyOtp(Request $request)
    {
        if ($request->has('phone')) {
            $request->merge(['phone' => $this->normalizePhone($request->phone)]);
        }

        $request->validate([
            'phone' => ['required', 'string'],
            'otp' => ['required', 'string', 'digits:6'],
            'purpose' => ['required', 'string', 'in:register,login,reset_password'],
        ]);

        $verify = Otp::verify($request->phone, $request->otp, $request->purpose);

        if (!$verify['valid']) {
            throw ValidationException::withMessages([
                'otp' => [$verify['message']],
            ]);
        }

        return response()->json([
            'status' => 'success',
            'message' => $verify['message'],
        ]);
    }

    /**
     * Customer registration with mandatory OTP verification
     */
    public function register(Request $request)
    {
        if ($request->has('phone')) {
            $request->merge(['phone' => $this->normalizePhone($request->phone)]);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'phone' => ['required', 'string', 'max:20', 'unique:users', 'regex:/^[6-9]\d{9}$/'],
            'password' => 'required|string|min:8|confirmed',
            'otp' => 'required|string|digits:6',
        ], [
            'phone.regex' => 'Please provide a valid 10-digit Indian mobile number.',
            'password.min' => 'Password must be at least 8 characters long.',
        ]);

        $cleanPhone = preg_replace('/[^0-9]/', '', $validated['phone']);

        // Verify OTP
        $verify = Otp::verify($cleanPhone, $validated['otp'], 'register');
        if (!$verify['valid']) {
            throw ValidationException::withMessages([
                'otp' => [$verify['message']],
            ]);
        }

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $cleanPhone,
            'phone_verified_at' => now(),
            'password' => Hash::make($validated['password']),
            'role' => 'user',
            'wallet_balance' => 0.00,
            'status' => 'active',
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'status' => 'success',
            'message' => 'Account registered & mobile verified successfully.',
            'token' => $token,
            'user' => $user,
        ], 201);
    }

    /**
     * Passwordless / OTP Login via Mobile Number
     */
    public function loginWithOtp(Request $request)
    {
        if ($request->has('phone')) {
            $request->merge(['phone' => $this->normalizePhone($request->phone)]);
        }

        $request->validate([
            'phone' => ['required', 'string'],
            'otp' => ['required', 'string', 'digits:6'],
        ]);

        $cleanPhone = $request->phone;

        $verify = Otp::verify($cleanPhone, $request->otp, 'login');
        if (!$verify['valid']) {
            throw ValidationException::withMessages([
                'otp' => [$verify['message']],
            ]);
        }

        $user = User::where('phone', $cleanPhone)->first();
        if (!$user) {
            throw ValidationException::withMessages([
                'phone' => ['No account found for this mobile number.'],
            ]);
        }

        if ($user->status === 'blocked') {
            return response()->json([
                'status' => 'error',
                'message' => 'Your account has been deactivated. Please contact support.',
            ], 403);
        }

        $user->tokens()->delete();
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'status' => 'success',
            'message' => 'Logged in successfully via OTP.',
            'token' => $token,
            'user' => $user,
        ]);
    }

    /**
     * User / Admin login with Email or Phone
     */
    public function login(Request $request)
    {
        $request->validate([
            'identifier' => 'required|string',
            'password' => 'required|string',
        ]);

        $identifier = trim($request->input('identifier'));
        $cleanPhone = $this->normalizePhone($identifier);

        $user = User::where('email', $identifier)
            ->orWhere('phone', $identifier)
            ->when($cleanPhone, function ($query, $p) {
                $query->orWhere('phone', $p);
            })
            ->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'identifier' => ['Invalid login credentials.'],
            ]);
        }

        if ($user->status === 'blocked') {
            return response()->json([
                'status' => 'error',
                'message' => 'Your account has been deactivated. Please contact support.'
            ], 403);
        }

        // Clean old tokens and create fresh
        $user->tokens()->delete();
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'status' => 'success',
            'message' => 'Logged in successfully.',
            'token' => $token,
            'user' => $user,
        ]);
    }

    /**
     * User logout
     */
    public function logout(Request $request)
    {
        $token = $request->bearerToken();
        if ($token) {
            \Illuminate\Support\Facades\Cache::forget('auth_token_uid_' . hash('sha256', $token));
        }

        $currentToken = $request->user()->currentAccessToken();
        if ($currentToken) {
            $currentToken->delete();
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Logged out successfully.'
        ]);
    }

    /**
     * Get authenticated profile
     */
    public function me(Request $request)
    {
        return response()->json([
            'status' => 'success',
            'user' => $request->user(),
        ]);
    }

    /**
     * Update profile info
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,' . $user->id,
            'phone' => ['required', 'string', 'max:20', 'regex:/^[6-9]\d{9}$/', 'unique:users,phone,' . $user->id],
            'otp' => 'nullable|string|digits:6',
        ], [
            'phone.regex' => 'Please provide a valid 10-digit Indian mobile number.',
        ]);

        $cleanPhone = preg_replace('/[^0-9]/', '', $validated['phone']);

        // If phone number is being changed, require valid OTP verification
        if ($cleanPhone !== $user->phone) {
            if (empty($validated['otp'])) {
                throw ValidationException::withMessages([
                    'otp' => ['OTP verification is required to update your registered mobile number.'],
                ]);
            }

            $verify = Otp::verify($cleanPhone, $validated['otp'], 'register');
            if (!$verify['valid']) {
                throw ValidationException::withMessages([
                    'otp' => [$verify['message']],
                ]);
            }
            $user->phone_verified_at = now();
        }

        $user->update([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $cleanPhone,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Profile updated successfully.',
            'user' => $user->fresh(),
        ]);
    }

    /**
     * Change password
     */
    public function changePassword(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ], [
            'password.min' => 'New password must be at least 8 characters long.',
        ]);

        if (!Hash::check($request->current_password, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Current password does not match.'],
            ]);
        }

        $user->update([
            'password' => Hash::make($request->password),
        ]);

        // Revoke all other active sessions/tokens except the current one
        $currentTokenId = $user->currentAccessToken()?->id;
        if ($currentTokenId) {
            $user->tokens()->where('id', '!=', $currentTokenId)->delete();
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Password updated successfully.'
        ]);
    }

    /**
     * Reset password via mobile OTP verification
     */
    public function resetPassword(Request $request)
    {
        if ($request->has('phone')) {
            $request->merge(['phone' => $this->normalizePhone($request->phone)]);
        }

        $request->validate([
            'phone' => ['required', 'string', 'regex:/^[6-9]\d{9}$/'],
            'otp' => ['required', 'string', 'digits:6'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'phone.regex' => 'Please provide a valid 10-digit Indian mobile number.',
            'password.min' => 'Password must be at least 8 characters long.',
        ]);

        $cleanPhone = $request->phone;

        $verify = Otp::verify($cleanPhone, $request->otp, 'reset_password');
        if (!$verify['valid']) {
            throw ValidationException::withMessages([
                'otp' => [$verify['message']],
            ]);
        }

        $user = User::where('phone', $cleanPhone)->first();
        if (!$user) {
            throw ValidationException::withMessages([
                'phone' => ['No account found for this mobile number.'],
            ]);
        }

        $user->update([
            'password' => Hash::make($request->password),
        ]);

        // Revoke all existing tokens upon password reset
        $user->tokens()->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Password has been reset successfully. Please log in with your new password.',
        ]);
    }
}
