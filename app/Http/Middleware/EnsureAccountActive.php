<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logs a phone out as soon as its account is disabled, or when the admin presses
 * "Log out this phone" (which bumps the account's session_version).
 */
class EnsureAccountActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user) {
            $version = $request->session()->get('session_version');
            if ($version === null) {
                $request->session()->put('session_version', $user->session_version);   // e.g. logged in by remember-me cookie
            } elseif (! $user->is_active || (int) $version !== (int) $user->session_version) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return $request->expectsJson() && ! $request->header('X-Inertia')
                    ? response()->json(['status' => 'error', 'message' => 'This phone was logged out. Log in again.'], 401)
                    : redirect('/login');
            }
        }

        return $next($request);
    }
}
