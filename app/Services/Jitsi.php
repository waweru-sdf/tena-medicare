<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\User;
use App\Support\Jwt;

/**
 * Private, signed, expiring video room links. Three modes, picked from .env:
 *  1. JaaS (8x8, free up to 25 monthly users): JITSI_APP_ID + JITSI_KID + a private key (RS256), joined at https://8x8.vc/<app>/<room>
 *  2. Self-hosted Jitsi with login tokens: JITSI_DOMAIN + JITSI_APP_ID + JITSI_APP_SECRET (HS256)
 *  3. Test mode only (TENA_PRODUCTION=false): open meet.jit.si rooms. Rooms have long random names but are NOT access-controlled.
 */
class Jitsi
{
    /** The RS256 private key: JITSI_PRIVATE_KEY (with \n for line breaks) or the file named in JITSI_PRIVATE_KEY_FILE. */
    public static function privateKey(): string
    {
        $k = trim((string) config('tena.jitsi_private_key'));
        if ($k === '' && ($f = (string) config('tena.jitsi_private_key_file'))) {
            $path = str_starts_with($f, '/') ? $f : base_path($f);
            $k = is_file($path) ? trim((string) file_get_contents($path)) : '';
        }
        return $k;
    }

    /** JaaS key ids look like "vpaas-magic-cookie-<id>/<keyid>". Accept the short form too and add the prefix. */
    public static function kid(): string
    {
        $kid = trim((string) config('tena.jitsi_kid'));
        $app = trim((string) config('tena.jitsi_app_id'));
        return ($kid !== '' && !str_contains($kid, '/') && $app !== '') ? "{$app}/{$kid}" : $kid;
    }

    public static function mode(): string
    {
        $app = trim((string) config('tena.jitsi_app_id'));
        if ($app && self::privateKey() && self::kid()) {
            return 'jaas';
        }
        if (config('tena.jitsi_domain') && $app && config('tena.jitsi_app_secret')) {
            return 'self-hosted';
        }
        if (!config('tena.production') && config('tena.allow_public_rooms')) {
            return 'public-test';
        }
        return 'none';
    }

    /** Plain-language health check used by the self-test and the admin Integrations page. */
    public static function status(): array
    {
        $mode = self::mode();
        if ($mode === 'none') {
            return ['ok' => false, 'mode' => $mode, 'message' => 'Video rooms are not set up. Add the JITSI_ lines to .env (see INSTALL.md, "Video rooms").'];
        }
        if ($mode === 'public-test') {
            return ['ok' => true, 'mode' => $mode, 'message' => 'Test mode: open meet.jit.si rooms (not private). Add JaaS keys before going live.'];
        }
        if ($mode === 'self-hosted') {
            return ['ok' => true, 'mode' => $mode, 'message' => 'Self-hosted Jitsi with signed login tokens.'];
        }
        $app = trim((string) config('tena.jitsi_app_id'));
        $kid = self::kid();
        if (!str_starts_with($kid, $app . '/')) {
            return ['ok' => false, 'mode' => $mode, 'message' => "JITSI_KID must start with your App ID and a slash ({$app}/<key id>)."];
        }
        $pk = openssl_pkey_get_private(self::privateKey());
        if (!$pk) {
            return ['ok' => false, 'mode' => $mode, 'message' => 'The private key cannot be read. Paste the whole key including the BEGIN/END lines.'];
        }
        $d = openssl_pkey_get_details($pk);
        if (($d['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
            return ['ok' => false, 'mode' => $mode, 'message' => 'The key must be an RSA private key (the one downloaded from JaaS).'];
        }
        try {
            Jwt::encode(['test' => 1], self::privateKey(), 'RS256', ['kid' => $kid]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'mode' => $mode, 'message' => 'The key could not sign a token: ' . $e->getMessage()];
        }
        return ['ok' => true, 'mode' => $mode, 'message' => 'JaaS (8x8) is configured; the key can sign room tokens.'];
    }

    public static function url(Appointment $a, User $u): ?string
    {
        $end = $a->slot->starts_at->copy()->addMinutes($a->slot->duration_min + 30)->getTimestamp();
        $doc = $u->role === 'clinician';
        $user = ['id' => (string) $u->id, 'name' => $u->name, 'email' => $u->email, 'moderator' => $doc ? 'true' : 'false'];
        $feats = ['recording' => 'false', 'livestreaming' => 'false', 'transcription' => 'false', 'outbound-call' => 'false'];
        $app = trim((string) config('tena.jitsi_app_id'));
        $now = time();
        switch (self::mode()) {
            case 'jaas':
                $tok = Jwt::encode(['aud' => 'jitsi', 'iss' => 'chat', 'iat' => $now, 'sub' => $app, 'room' => $a->room, 'nbf' => $now - 10, 'exp' => $end,
                                    'context' => ['user' => $user, 'features' => $feats]], self::privateKey(), 'RS256', ['kid' => self::kid()]);
                return "https://8x8.vc/{$app}/{$a->room}?jwt={$tok}";
            case 'self-hosted':
                $tok = Jwt::encode(['aud' => $app, 'iss' => $app, 'iat' => $now, 'sub' => config('tena.jitsi_domain'), 'room' => $a->room, 'nbf' => $now - 10, 'exp' => $end,
                                    'context' => ['user' => $user, 'features' => $feats]], config('tena.jitsi_app_secret'), 'HS256');
                return 'https://' . config('tena.jitsi_domain') . "/{$a->room}?jwt={$tok}";
            case 'public-test':
                return "https://meet.jit.si/{$a->room}#userInfo.displayName=\"" . rawurlencode($u->name) . '"';
        }
        return null;
    }
}
