<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/** Talks to Chapa (the Ethiopian payment provider). Payment is only ever proven by asking Chapa directly. */
class Chapa
{
    public const API = 'https://api.chapa.co/v1';

    public static function key(): string
    {
        return (string) config('tena.chapa_key');
    }

    public static function enabled(): bool
    {
        return self::key() !== '';
    }

    private static function http()
    {
        return Http::timeout(20)->withHeaders(['Authorization' => 'Bearer ' . self::key(), 'Content-Type' => 'application/json', 'Accept' => 'application/json']);
    }

    /** Chapa wants 09xxxxxxxx or 07xxxxxxxx. Accepts +2519..., 2519..., 09... */
    public static function phone(?string $raw): ?string
    {
        $d = preg_replace('/\D/', '', (string) $raw);
        if (str_starts_with($d, '251') && strlen($d) === 12) {
            $d = '0' . substr($d, 3);
        } elseif (strlen($d) === 9 && in_array($d[0], ['7', '9'], true)) {
            $d = '0' . $d;
        }
        return preg_match('/^0[79]\d{8}$/', $d) ? $d : null;
    }

    /** @return string checkout address; throws when Chapa does not give one */
    public static function initialize(array $payload): string
    {
        $res = self::http()->post(self::API . '/transaction/initialize', $payload);
        $res->throw();
        $body = $res->json() ?: [];
        $data = $body['data'] ?? [];
        $checkout = $data['checkout_url'] ?? $data['authorization_url'] ?? null;
        if (($body['status'] ?? '') !== 'success' || !$checkout) {
            throw new \RuntimeException('Chapa did not return a checkout URL');
        }
        return $checkout;
    }

    /** Chapa's own record of a payment: ['status' => ..., 'data' => [...]] */
    public static function verify(string $ref): array
    {
        $res = self::http()->get(self::API . '/transaction/verify/' . rawurlencode($ref));
        $res->throw();
        $body = $res->json() ?: [];
        return ['ok' => ($body['status'] ?? '') === 'success', 'data' => $body['data'] ?? []];
    }

    /** @return array{ok:bool, ref_id:?string, message:?string} */
    public static function refund(string $providerRef, string $refundRef, string $reason): array
    {
        $res = self::http()->post(self::API . '/refund/' . rawurlencode($providerRef), ['reason' => substr($reason, 0, 120), 'reference' => $refundRef]);
        $res->throw();
        $body = $res->json() ?: [];
        if (($body['status'] ?? '') !== 'success') {
            throw new \RuntimeException((string) ($body['message'] ?? 'refund refused'));
        }
        $d = $body['data'] ?? [];
        return ['ok' => true, 'ref_id' => $d['ref_id'] ?? $body['ref_id'] ?? null, 'message' => null];
    }

    public static function refundStatus(string $refundId): string
    {
        $res = self::http()->get(self::API . '/refund/' . rawurlencode($refundId) . '/verify');
        $res->throw();
        return strtolower((string) (($res->json()['data'] ?? [])['status'] ?? ''));
    }

    public static function banksStatus(): int
    {
        return self::http()->get(self::API . '/banks')->status();
    }

    /**
     * Chapa's notes are unclear about which secret and header sign a webhook, so the documented forms are accepted.
     * This is only a first filter: every payment is re-checked with Chapa's verify call before it counts.
     */
    public static function signatureOk(string $raw, array $headers): bool
    {
        $secrets = array_values(array_filter([(string) config('tena.chapa_webhook_secret'), self::key()]));
        $got = array_filter([(string) ($headers['x-chapa-signature'] ?? ''), (string) ($headers['chapa-signature'] ?? '')]);
        foreach ($secrets as $sec) {
            $good = [hash_hmac('sha256', $raw, $sec), hash_hmac('sha256', $sec, $sec)];
            foreach ($got as $g) {
                foreach ($good as $x) {
                    if (hash_equals($x, $g)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }
}
