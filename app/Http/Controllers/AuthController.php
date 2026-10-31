<?php

namespace App\Http\Controllers;

use App\Http\Middleware\TenaAuth;
use App\Models\Audit;
use App\Models\Clinician;
use App\Models\User;
use App\Services\Present;
use App\Support\Throttle;
use App\Support\Totp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public const PRIVACY_VERSION = 'v1.0';

    public function register(Request $r)
    {
        if ($x = $this->limit($r, 'register', 10)) {
            return $x;
        }
        $d = $this->body($r);
        $role = $d['role'] ?? 'patient';
        $email = strtolower(trim((string) ($d['email'] ?? '')));
        $password = (string) ($d['password'] ?? '');
        if (!in_array($role, ['patient', 'clinician'], true)) {
            return $this->err('Invalid role.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 120 || mb_strlen($password) < 8) {
            return $this->err('Email and a password of at least 8 characters are required.');
        }
        if (User::where('email', $email)->exists()) {
            return $this->err('That email is already registered.', 409);
        }
        $u = User::create([
            'email' => $email, 'name' => mb_substr(trim((string) ($d['name'] ?? '')), 0, 120), 'phone' => mb_substr((string) ($d['phone'] ?? ''), 0, 30) ?: null,
            'password_hash' => Hash::make($password), 'role' => $role, 'token_version' => 1, 'mfa_on' => false,
        ]);
        if ($role === 'clinician') {
            Clinician::create(['user_id' => $u->id, 'specialty' => mb_substr((string) (($d['specialty'] ?? '') ?: 'Dermatology'), 0, 80), 'reg_number' => (string) ($d['reg_number'] ?? ''), 'status' => 'draft']);
        }
        Audit::record('register', $role, $u->id);
        return response()->json(['token' => TenaAuth::tokenFor($u), 'user' => Present::user($u)]);
    }

    public function login(Request $r)
    {
        if ($x = $this->limit($r, 'login', 10)) {
            return $x;
        }
        $d = $this->body($r);
        $email = strtolower(trim((string) ($d['email'] ?? '')));
        // Extra guard: after 8 wrong passwords for one address, that address waits 15 minutes (whoever is typing).
        $lock = 'tena:lock:' . sha1($email);
        if ($email !== '' && Cache::get($lock, 0) >= 8) {
            return $this->err('Too many wrong attempts for this account. Try again in 15 minutes.', 429);
        }
        $u = User::where('email', $email)->first();
        // Always run one hash check, so the reply time does not reveal which emails exist.
        $ok = Hash::check((string) ($d['password'] ?? ''), $u ? $u->password_hash : self::dummyHash());
        if (!$u || !$ok) {
            Cache::put($lock, Cache::get($lock, 0) + 1, 900);
            return $this->err('Email or password is incorrect.', 401);
        }
        if ($u->mfa_on && !Totp::verify((string) $u->mfa_secret, (string) ($d['code'] ?? ''))) {
            return $this->err('Enter your 6-digit security code.', 401, ['mfa_required' => true]);
        }
        Cache::forget($lock);
        Audit::record('login', '', $u->id);
        return response()->json(['token' => TenaAuth::tokenFor($u), 'user' => Present::user($u)]);
    }

    private static function dummyHash(): string
    {
        return Cache::rememberForever('tena:dummy-hash', fn () => Hash::make(bin2hex(random_bytes(8))));
    }

    public function consent(Request $r)
    {
        $u = $this->who($r);
        $u->update(['consent_version' => self::PRIVACY_VERSION, 'consent_at' => now()]);
        Audit::record('consent', self::PRIVACY_VERSION . ':care');
        return response()->json(['user' => Present::user($u->fresh())]);
    }

    /** Called whenever the site opens. Returns a fresh token, so people who keep using Tena stay signed in. */
    public function me(Request $r)
    {
        $u = $this->who($r);
        return response()->json(['user' => Present::user($u), 'token' => TenaAuth::tokenFor($u)]);
    }

    public function mfaSetup(Request $r)
    {
        $u = $this->who($r);
        if ($u->mfa_on) {
            return $this->err('Two-step sign-in is already on.');
        }
        $secret = Totp::randomSecret();
        $u->update(['mfa_secret' => $secret]);
        return response()->json(['secret' => $secret, 'uri' => Totp::uri($secret, $u->email, 'Dr. Feysel Tena Medicine')]);
    }

    public function mfaEnable(Request $r)
    {
        $u = $this->who($r);
        if ($u->mfa_secret && Totp::verify((string) $u->mfa_secret, (string) ($this->body($r)['code'] ?? ''))) {
            $u->update(['mfa_on' => true]);
            Audit::record('mfa_enabled');
            return response()->json(['user' => Present::user($u->fresh())]);
        }
        return $this->err('That code is not correct.');
    }
}
