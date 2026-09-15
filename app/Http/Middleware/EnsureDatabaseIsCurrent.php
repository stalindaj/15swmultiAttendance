<?php

namespace App\Http\Middleware;

use App\Support\DatabaseStatus;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * After a new version is pulled on cPanel, its pages may need tables the database doesn't have yet.
 * Instead of a 500 ("Unknown column"), the records account gets a page with an "Update database" button.
 */
class EnsureDatabaseIsCurrent
{
    public function __construct(private DatabaseStatus $database) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET') && ($pending = $this->database->pendingMigrations()) !== []) {
            return Inertia::render('System/UpdateDatabase', ['pending' => $pending])->toResponse($request);
        }

        return $next($request);
    }
}
