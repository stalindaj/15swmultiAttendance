<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * In laptop mode, admin pages only open on the laptop itself. Phones reach the app through
 * the gateway / tunnel, which tags every request with X-Attendance-Remote.
 */
class LaptopOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('attendance.admin_localhost_only')) {
            return $next($request);
        }

        $fromPhone = $request->headers->has('X-Attendance-Remote')
            || ! in_array($request->server('REMOTE_ADDR'), ['127.0.0.1', '::1'], true);

        if ($fromPhone) {
            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                abort(403, 'Admin functions are only available on the laptop.');
            }

            return response('', 302, ['Location' => '/scan']);   // relative, so it works through the gateway
        }

        return $next($request);
    }
}
