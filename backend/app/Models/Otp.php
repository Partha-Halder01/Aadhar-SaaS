<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Otp extends Model
{
    use HasFactory;

    protected $fillable = [
        'phone',
        'otp',
        'purpose',
        'expires_at',
        'verified_at',
        'ip_address',
        'attempts',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    /**
     * Generate a new 6-digit OTP for a phone number
     */
    public static function generate(string $phone, string $purpose = 'register', ?string $ip = null, int $expiryMinutes = 5, bool $bypassRateLimits = false): self
    {
        // Clean phone (digits only)
        $cleanPhone = preg_replace('/[^0-9]/', '', $phone);

        if (!$bypassRateLimits) {
            // 1. Cooldown check: enforce 60s cooldown per phone
            $recent = static::where('phone', $cleanPhone)
                ->where('purpose', $purpose)
                ->where('created_at', '>=', now()->subSeconds(60))
                ->latest()
                ->first();

            if ($recent) {
                $waitSeconds = max(1, (int) ceil(60 - now()->diffInSeconds($recent->created_at)));
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'phone' => ["Please wait {$waitSeconds} seconds before requesting a new OTP."],
                ]);
            }

            // 2. Hourly rate check: max 5 OTP requests per hour per phone
            $hourlyCount = static::where('phone', $cleanPhone)
                ->where('created_at', '>=', now()->subHour())
                ->count();

            if ($hourlyCount >= 5) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'phone' => ['Maximum OTP requests for this mobile number exceeded for this hour. Please try again later.'],
                ]);
            }
        }

        // Invalidate previous unverified OTPs for this phone and purpose
        static::where('phone', $cleanPhone)
            ->where('purpose', $purpose)
            ->whereNull('verified_at')
            ->delete();

        $code = (string) random_int(100000, 999999);

        return static::create([
            'phone' => $cleanPhone,
            'otp' => $code,
            'purpose' => $purpose,
            'expires_at' => now()->addMinutes($expiryMinutes),
            'ip_address' => $ip,
            'attempts' => 0,
        ]);
    }

    /**
     * Verify an OTP code
     */
    public static function verify(string $phone, string $otp, string $purpose = 'register'): array
    {
        $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
        $cleanOtp = trim($otp);

        $record = static::where('phone', $cleanPhone)
            ->where('purpose', $purpose)
            ->latest()
            ->first();

        if (!$record) {
            return [
                'valid' => false,
                'message' => 'No OTP request found for this phone number. Please request an OTP first.',
            ];
        }

        if ($record->verified_at !== null) {
            return [
                'valid' => false,
                'message' => 'This OTP has already been used. Please request a new code.',
            ];
        }

        if ($record->expires_at->isPast()) {
            return [
                'valid' => false,
                'message' => 'This OTP has expired. Please request a new code.',
            ];
        }

        if ($record->attempts >= 5) {
            return [
                'valid' => false,
                'message' => 'Maximum verification attempts exceeded. Please request a new OTP.',
            ];
        }

        if (!hash_equals($record->otp, $cleanOtp)) {
            $record->increment('attempts');
            $remaining = max(0, 5 - $record->attempts);
            return [
                'valid' => false,
                'message' => "Invalid OTP code. {$remaining} attempts remaining.",
            ];
        }

        $record->update(['verified_at' => now()]);

        return [
            'valid' => true,
            'message' => 'OTP verified successfully.',
            'otp' => $record,
        ];
    }

    /**
     * Check if a phone has a recent valid verification
     */
    public static function isRecentlyVerified(string $phone, string $purpose = 'register', int $validityMinutes = 15): bool
    {
        $cleanPhone = preg_replace('/[^0-9]/', '', $phone);

        return static::where('phone', $cleanPhone)
            ->where('purpose', $purpose)
            ->whereNotNull('verified_at')
            ->where('verified_at', '>=', now()->subMinutes($validityMinutes))
            ->exists();
    }
}
