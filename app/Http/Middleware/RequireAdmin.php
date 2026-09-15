<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Records pages are for the admin account; scanner accounts are sent to the scanner. */
class RequireAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isAdmin()) {
            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                abort(403, 'Only the records account can do this.');
            }

            return redirect('/scan');
        }

        return $next($request);
    }
}
