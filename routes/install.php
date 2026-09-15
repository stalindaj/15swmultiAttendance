<?php

use App\Http\Controllers\InstallController;
use Illuminate\Support\Facades\Route;

// No "web" middleware: no session or cookies, so this works before APP_KEY exists.
// Guarded by INSTALL_TOKEN (see InstallController).
Route::get('/install', [InstallController::class, 'show']);
Route::post('/install/migrate', [InstallController::class, 'migrate']);
Route::post('/install/admin', [InstallController::class, 'createAdmin']);
