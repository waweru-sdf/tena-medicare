<?php

namespace App\Support;

/** Minimal JWT (HS256 to sign in people, RS256 for the 8x8 video service). No outside packages. */
class Jwt
{
    public static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function unb64(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/'), true);
    }

    public static function encode(array $payload, string $key, string $alg = 'HS256', array $headers = []): string
    {
        $head = self::b64(json_encode(['alg' => $alg, 'typ' => 'JWT'] + $headers, JSON_UNESCAPED_SLASHES));
        $body = self::b64(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $input = "{$head}.{$body}";
        if ($alg === 'HS256') {
            $sig = hash_hmac('sha256', $input, $key, true);
        } else {
            $pk = openssl_pkey_get_private($key);
            if (!$pk || !openssl_sign($input, $sig, $pk, OPENSSL_ALGO_SHA256)) {
                throw new \RuntimeException('Cannot sign the video token: check JITSI_PRIVATE_KEY.');
            }
        }
        return $input . '.' . self::b64($sig);
    }

    /** Verified payload (HS256 only), or null when the token is missing, altered, or expired. */
    public static function decode(?string $token, string $key): ?array
    {
        $parts = explode('.', (string) $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$h, $b, $s] = $parts;
        $head = json_decode(self::unb64($h), true);
        if (!is_array($head) || ($head['alg'] ?? '') !== 'HS256') {
            return null;
        }
        if (!hash_equals(hash_hmac('sha256', "{$h}.{$b}", $key, true), self::unb64($s))) {
            return null;
        }
        $payload = json_decode(self::unb64($b), true);
        if (!is_array($payload) || (isset($payload['exp']) && $payload['exp'] < time())) {
            return null;
        }
        return $payload;
    }
}
