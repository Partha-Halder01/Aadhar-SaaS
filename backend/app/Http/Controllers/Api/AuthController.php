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
     * Dispatch SMS OTP for registration, login, or password reset
     */
    public function sendOtp(Request $request, SmsService $smsService)
    {
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
            return response()->json([
                'status' => 'error',
                'message' => $result['message'] ?? 'Failed to deliver OTP to your mobile. Please try again.',
            ], 400);
        }

        return response()->json([
            'status' => 'success',
            'message' => $result['message'] ?? 'OTP has been dispatched to your mobile number.',
            'phone' => $phone,
            'expires_in' => 300,
            'dev_otp' => $result['dev_otp'] ?? null,
        ]);
    }

    /**
     * Verify an OTP directly
     */
    public function verifyOtp(Request $request)
    {
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
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'phone' => ['required', 'string', 'max:20', 'unique:users', 'regex:/^[6-9]\d{9}$/'],
            'password' => 'required|string|min:6|confirmed',
            'otp' => 'required|string|digits:6',
        ], [
            'phone.regex' => 'Please provide a valid 10-digit Indian mobile number.',
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
        $request->validate([
            'phone' => ['required', 'string'],
            'otp' => ['required', 'string', 'digits:6'],
        ]);

        $cleanPhone = preg_replace('/[^0-9]/', '', $request->phone);

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

        $identifier = $request->input('identifier');
        $user = User::where('email', $identifier)
            ->orWhere('phone', $identifier)
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
        $request->user()->currentAccessToken()->delete();

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
            'phone' => 'required|string|max:20|unique:users,phone,' . $user->id,
        ]);

        $user->update($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Profile updated successfully.',
            'user' => $user,
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
            'password' => 'required|string|min:6|confirmed',
        ]);

        if (!Hash::check($request->current_password, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Current password does not match.'],
            ]);
        }

        $user->update([
            'password' => Hash::make($request->password),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Password updated successfully.'
        ]);
    }
}
