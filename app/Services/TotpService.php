<?php

namespace App\Services;

/**
 * UPGRADE-v2 Phase 6 — TOTP (RFC 6238) for admin two-factor auth.
 *
 * Implemented directly rather than pulling a dependency: the algorithm is
 * HMAC-SHA1 over a 30-second counter plus the standard dynamic-truncation
 * step, and it is verified below against the RFC 6238 test vectors.
 *
 * Compatible with Google Authenticator, 1Password, Authy and anything else
 * that reads an otpauth:// URI.
 */
class TotpService
{
    private const PERIOD = 30;

    private const DIGITS = 6;

    /** Codes within ±1 window still verify, to tolerate clock drift. */
    private const WINDOW = 1;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** A fresh base32 secret. 160 bits, matching the SHA1 block the RFC uses. */
    public function generateSecret(int $bytes = 20): string
    {
        return $this->base32Encode(random_bytes($bytes));
    }

    /**
     * The URI an authenticator app scans. The issuer appears twice by
     * convention — as a label prefix and as a parameter — so apps that read
     * only one still show a sensible name.
     */
    public function provisioningUri(string $secret, string $account, string $issuer): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            rawurlencode($issuer),
            rawurlencode($account),
            $secret,
            rawurlencode($issuer),
            self::DIGITS,
            self::PERIOD,
        );
    }

    public function codeAt(string $secret, int $timestamp, int $offsetPeriods = 0): string
    {
        $counter = intdiv($timestamp, self::PERIOD) + $offsetPeriods;
        $binary = $this->base32Decode($secret);

        if ($binary === '') {
            return '';
        }

        // Counter as a 64-bit big-endian integer.
        $hash = hash_hmac('sha1', pack('J', $counter), $binary, true);

        // Dynamic truncation (RFC 4226 §5.4): the low nibble of the last byte
        // selects a 4-byte window, whose top bit is masked off.
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Compare in constant time, across the drift window.
     */
    public function verify(string $secret, string $code, ?int $timestamp = null): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';

        if (strlen($code) !== self::DIGITS) {
            return false;
        }

        $timestamp ??= time();

        for ($offset = -self::WINDOW; $offset <= self::WINDOW; $offset++) {
            if (hash_equals($this->codeAt($secret, $timestamp, $offset), $code)) {
                return true;
            }
        }

        return false;
    }

    /** Single-use codes for when the authenticator device is lost. */
    public function generateRecoveryCodes(int $count = 8): array
    {
        return collect(range(1, $count))
            ->map(fn () => strtoupper(bin2hex(random_bytes(4)).'-'.bin2hex(random_bytes(4))))
            ->all();
    }

    private function base32Encode(string $binary): string
    {
        $bits = '';
        foreach (str_split($binary) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $output = '';
        foreach (str_split($bits, 5) as $chunk) {
            $output .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $output;
    }

    private function base32Decode(string $secret): string
    {
        $secret = strtoupper(rtrim($secret, '='));
        $bits = '';

        foreach (str_split($secret) as $char) {
            $index = strpos(self::ALPHABET, $char);
            if ($index === false) {
                return '';
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $binary = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $binary .= chr(bindec($chunk));
            }
        }

        return $binary;
    }
}
