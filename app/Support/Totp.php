<?php

namespace App\Support;

/** Two-step sign-in codes (RFC 6238, the same 6 digits every authenticator app shows). */
class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function randomSecret(): string
    {
        $out = '';
        for ($i = 0; $i < 32; $i++) {
            $out .= self::ALPHABET[random_int(0, 31)];
        }
        return $out;
    }

    private static function decode(string $b32): string
    {
        $bits = '';
        foreach (str_split(strtoupper(rtrim($b32, '='))) as $c) {
            $v = strpos(self::ALPHABET, $c);
            if ($v === false) {
                continue;
            }
            $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }

    public static function at(string $secret, int $time): string
    {
        $counter = pack('N2', 0, intdiv($time, 30));
        $hash = hash_hmac('sha1', $counter, self::decode($secret), true);
        $o = ord($hash[19]) & 0xF;
        $n = ((ord($hash[$o]) & 0x7F) << 24) | (ord($hash[$o + 1]) << 16) | (ord($hash[$o + 2]) << 8) | ord($hash[$o + 3]);
        return str_pad((string) ($n % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /** True for the current code, or the one just before or after (clocks are never perfectly in step). */
    public static function verify(string $secret, string $code, int $window = 1): bool
    {
        $code = trim($code);
        if (!preg_match('/^\d{6}$/', $code) || $secret === '') {
            return false;
        }
        $now = time();
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::at($secret, $now + 30 * $i), $code)) {
                return true;
            }
        }
        return false;
    }

    public static function uri(string $secret, string $email, string $issuer): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($email) . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer);
    }
}
