<?php

namespace App\Domain\Security\Services;

use App\Models\User;
use Illuminate\Support\Str;
use InvalidArgumentException;

class MfaService
{
    protected string $issuer;
    protected int $window;

    public function __construct()
    {
        $this->issuer = config('app.name', 'AI-Native Finance ERP');
        $this->window = 1; // +/- 1 time step (30 seconds) tolerance
    }

    /**
     * Generate a new 160-bit Base32 secret for TOTP setup.
     */
    public function generateSecretKey(): string
    {
        $validChars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        for ($i = 0; $i < 32; $i++) {
            $secret .= $validChars[random_int(0, 31)];
        }
        return $secret;
    }

    /**
     * Generate the standard otpauth provisioning URI for authenticator apps.
     */
    public function getQrCodeUrl(User $user, string $secret): string
    {
        $encodedIssuer = rawurlencode($this->issuer);
        $encodedEmail = rawurlencode($user->email);

        return "otpauth://totp/{$encodedIssuer}:{$encodedEmail}?secret={$secret}&issuer={$encodedIssuer}&algorithm=SHA1&digits=6&period=30";
    }

    /**
     * Verify a 6-digit TOTP code against the secret key (RFC 6238).
     */
    public function verifyCode(string $secret, string $code): bool
    {
        $code = trim($code);
        if (strlen($code) !== 6 || ! ctype_digit($code)) {
            return false;
        }

        $currentTimeSlice = (int) floor(time() / 30);

        for ($sliceOffset = -$this->window; $sliceOffset <= $this->window; $sliceOffset++) {
            $slice = $currentTimeSlice + $sliceOffset;
            $calculatedCode = $this->calculateCode($secret, $slice);
            if (hash_equals($calculatedCode, $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate 8 unique formatted recovery codes.
     */
    public function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $part1 = strtoupper(Str::random(5));
            $part2 = strtoupper(Str::random(5));
            $codes[] = "{$part1}-{$part2}";
        }
        return $codes;
    }

    /**
     * Verify and consume a recovery code for a user.
     */
    public function verifyAndConsumeRecoveryCode(User $user, string $code): bool
    {
        $code = trim(strtoupper($code));
        $recoveryCodes = $user->two_factor_recovery_codes ?? [];

        if (! is_array($recoveryCodes) || empty($recoveryCodes)) {
            return false;
        }

        $index = array_search($code, $recoveryCodes, true);
        if ($index === false) {
            return false;
        }

        // Consume code (single-use)
        unset($recoveryCodes[$index]);
        $user->two_factor_recovery_codes = array_values($recoveryCodes);
        $user->save();

        return true;
    }

    /**
     * Calculate 6-digit code for a given timestamp slice using RFC 6238 HMAC-SHA1.
     */
    protected function calculateCode(string $secret, int $timeSlice): string
    {
        $secretBytes = $this->base32Decode($secret);

        // Pack time slice as 64-bit big-endian integer
        $timeBytes = pack('N*', 0) . pack('N*', $timeSlice);

        // Compute HMAC-SHA1
        $hash = hash_hmac('sha1', $timeBytes, $secretBytes, true);

        // Dynamic truncation (RFC 4226 Section 5.4)
        $offset = ord(substr($hash, -1)) & 0x0F;
        $truncatedHash = substr($hash, $offset, 4);

        $unpacked = unpack('N', $truncatedHash)[1];
        $binaryCode = $unpacked & 0x7FFFFFFF;

        $otp = $binaryCode % 1000000;

        return str_pad((string) $otp, 6, '0', STR_PAD_LEFT);
    }

    /**
     * RFC 4648 Base32 decoding helper.
     */
    protected function base32Decode(string $base32): string
    {
        $base32 = strtoupper(rtrim($base32, " ="));
        $base32chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $buffer = 0;
        $bitsLeft = 0;
        $output = '';

        for ($i = 0; $i < strlen($base32); $i++) {
            $char = $base32[$i];
            $val = strpos($base32chars, $char);
            if ($val === false) {
                continue;
            }

            $buffer = ($buffer << 5) | $val;
            $bitsLeft += 5;

            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $output .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }

        return $output;
    }

    /**
     * Check whether the user has satisfied step-up authentication.
     * P1-10: Require recent MFA verification or step-up token for high-risk operations.
     */
    public function hasRecentStepUp(User $user, ?string $code = null, ?string $stepUpToken = null): bool
    {
        if (! $user->hasEnabledTwoFactor()) {
            return true; // MFA not enabled for user, no step-up required
        }

        // 1. Direct TOTP code provided with high-risk request
        if ($code && $this->verifyCode($user->two_factor_secret, $code)) {
            return true;
        }

        // 2. Verified step-up token from recent explicit verification
        if ($stepUpToken) {
            $tokenData = \Illuminate\Support\Facades\Cache::get("mfa_step_up_{$stepUpToken}");
            if ($tokenData && ($tokenData['user_id'] ?? null) === $user->id) {
                return true;
            }
        }

        // 3. User recent MFA session verification within 15 minutes
        $recentMfa = \Illuminate\Support\Facades\Cache::get("user_recent_mfa_{$user->id}");
        if ($recentMfa && (now()->timestamp - $recentMfa) < 900) {
            return true;
        }

        return false;
    }

    /**
     * Issue and cache a step-up token for 15 minutes.
     */
    public function recordStepUp(User $user): string
    {
        $stepUpToken = Str::random(64);
        \Illuminate\Support\Facades\Cache::put("mfa_step_up_{$stepUpToken}", [
            'user_id' => $user->id,
            'verified_at' => now()->timestamp,
        ], now()->addMinutes(15));
        \Illuminate\Support\Facades\Cache::put("user_recent_mfa_{$user->id}", now()->timestamp, now()->addMinutes(15));

        return $stepUpToken;
    }
}
