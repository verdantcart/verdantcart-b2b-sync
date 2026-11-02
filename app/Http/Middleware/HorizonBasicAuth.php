<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTP Basic auth in front of the Horizon dashboard.
 *
 * This app has no user logins, so Horizon's usual "logged-in user's e-mail" gate does not fit.
 * - local without a password configured: open (development convenience)
 * - anywhere else: HORIZON_BASIC_AUTH_PASSWORD must be set and match; no password = closed
 */
class HorizonBasicAuth
{
    public const VERIFIED = 'horizon_basic_auth_verified';

    public function handle(Request $request, Closure $next): Response
    {
        $user = (string) config('horizon.basic_auth.user');
        $password = (string) config('horizon.basic_auth.password');

        if ($password === '') {
            if (app()->environment('local')) {
                return $next($request);
            }

            abort(403, 'Horizon dashboard is disabled: set HORIZON_BASIC_AUTH_PASSWORD.');
        }

        $ok = hash_equals($user, (string) $request->getUser())
            && hash_equals($password, (string) $request->getPassword());

        if (! $ok) {
            return response('Authentication required.', 401, [
                'WWW-Authenticate' => 'Basic realm="VerdantCart B2B Sync – Horizon", charset="UTF-8"',
            ]);
        }

        $request->attributes->set(self::VERIFIED, true);

        return $next($request);
    }
}
