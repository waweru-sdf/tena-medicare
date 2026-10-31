<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Jwt;
use Closure;
use Illuminate\Http\Request;

/** Signs people in from the "Authorization: Bearer <token>" header. Use as `tena:patient,clinician` (no roles = any signed-in person). */
class TenaAuth
{
    public static function key(): string
    {
        return hash('sha256', 'tena-login|' . config('app.key'));
    }

    public static function tokenFor(User $u): string
    {
        return Jwt::encode(['sub' => (string) $u->id, 'tv' => (int) $u->token_version, 'exp' => time() + 86400 * (int) config('tena.token_days')], self::key());
    }

    /** The signed-in person from the request header, or null (never fails). */
    public static function resolve(Request $request): ?User
    {
        $header = (string) $request->header('Authorization', '');
        $claims = Jwt::decode(trim(preg_replace('/^Bearer\s+/i', '', $header)), self::key());
        if (!$claims) {
            return null;
        }
        $u = User::find((int) ($claims['sub'] ?? 0));
        return ($u && (int) ($claims['tv'] ?? 0) === (int) $u->token_version) ? $u : null;
    }

    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $u = self::resolve($request);
        if (!$u) {
            return response()->json(['error' => 'Please sign in again.'], 401);
        }
        if ($roles && !in_array($u->role, $roles, true)) {
            return response()->json(['error' => 'You do not have access to this.'], 403);
        }
        $request->attributes->set('user', $u);
        $request->setUserResolver(fn () => $u);
        return $next($request);
    }
}
