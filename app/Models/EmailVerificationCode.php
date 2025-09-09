<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class EmailVerificationCode extends Model
{
    use HasFactory;

    protected $fillable = [
        'email',
        'code',
        'expires_at',
        'used_at'
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    /**
     * Create a new verification code for an email
     */
    public static function createForEmail(string $email): self
    {
        // Invalidate any existing codes for this email
        self::where('email', $email)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        // Generate 6-digit code
        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        return self::create([
            'email' => $email,
            'code' => $code,
            'expires_at' => now()->addMinutes(15), // 15 minutes expiry
        ]);
    }

    /**
     * Verify a code for an email
     */
    public static function verifyCode(string $email, string $code): bool
    {
        $verificationCode = self::where('email', $email)
            ->where('code', $code)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();

        if ($verificationCode) {
            $verificationCode->update(['used_at' => now()]);
            return true;
        }

        return false;
    }

    /**
     * Check if email has pending verification
     */
    public static function hasPendingVerification(string $email): bool
    {
        return self::where('email', $email)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->exists();
    }
}
