<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\RosterController;
use App\Http\Controllers\StationController;
use App\Http\Controllers\SystemController;
use Illuminate\Support\Facades\Route;

// Phones and the records PC both log in here.
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'show'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:30,1');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    // Scanner (every account). Unknown IDs go to the records PC's "To confirm" list.
    Route::get('/scan', [StationController::class, 'page'])->name('scan');
    Route::get('/scan/ping', [StationController::class, 'ping']);
    Route::post('/scan/record', [StationController::class, 'record']);
});

// Records PC: admin account, and (in laptop mode) only on the laptop itself.
Route::middleware(['laptop', 'auth', 'admin'])->group(function () {
    Route::get('/', [DashboardController::class, 'page'])->name('dashboard');
    Route::post('/confirm', [DashboardController::class, 'confirm']);
    Route::post('/confirm/auto', [DashboardController::class, 'autoMatchPending']);
    Route::post('/present/{person}', [DashboardController::class, 'markPresent']);
    Route::post('/scans/{scan}/assign', [DashboardController::class, 'assign']);
    Route::post('/scans/{scan}/void', [DashboardController::class, 'void']);
    Route::post('/scans/clear', [DashboardController::class, 'clearScans']);
    Route::post('/settings', [DashboardController::class, 'settings']);
    Route::get('/export', [DashboardController::class, 'export']);
    Route::get('/qr.svg', [DashboardController::class, 'qr']);
    Route::get('/search', [StationController::class, 'search']);

    Route::get('/roster', [RosterController::class, 'page'])->name('roster');
    Route::post('/roster/upload', [RosterController::class, 'upload']);
    Route::get('/roster/preview/{token}', [RosterController::class, 'preview']);
    Route::post('/roster/import', [RosterController::class, 'import']);
    Route::post('/roster/person', [RosterController::class, 'addPerson']);
    Route::delete('/roster/person/{person}', [RosterController::class, 'deletePerson']);

    Route::post('/system/update-database', [SystemController::class, 'updateDatabase']);

    Route::get('/accounts', [AccountController::class, 'index'])->name('accounts');
    Route::post('/accounts', [AccountController::class, 'store']);
    Route::post('/accounts/{user}/password', [AccountController::class, 'password']);
    Route::post('/accounts/{user}/active', [AccountController::class, 'toggle']);
    Route::post('/accounts/{user}/logout', [AccountController::class, 'logout']);
});
