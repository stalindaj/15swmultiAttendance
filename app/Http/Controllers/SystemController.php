<?php

namespace App\Http\Controllers;

use App\Support\DatabaseStatus;
use Illuminate\Http\RedirectResponse;

/** Maintenance the records account can do from the browser (the hosting has no terminal). */
class SystemController extends Controller
{
    public function updateDatabase(DatabaseStatus $database): RedirectResponse
    {
        $count = count($database->pendingMigrations());
        $database->migrate();

        return back()->with('success', $count ? "Database updated ($count change".($count === 1 ? '' : 's').').' : 'The database was already up to date.');
    }
}
