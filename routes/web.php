<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\EventAttendeeController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\ReviewController;
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

    // Scanner (every account): pick an open event, scan. Unknown IDs go to that event's "To confirm" list.
    Route::get('/scan', [StationController::class, 'page'])->name('scan');
    Route::get('/scan/ping', [StationController::class, 'ping']);
    Route::post('/scan/record', [StationController::class, 'record']);
});

// Records PC: admin account, and (in laptop mode) only on the laptop itself.
Route::middleware(['laptop', 'auth', 'admin'])->group(function () {
    Route::post('/system/update-database', [SystemController::class, 'updateDatabase']);

    Route::middleware('db.current')->group(function () {
        Route::get('/', [EventController::class, 'index'])->name('events');
        Route::post('/events', [EventController::class, 'store']);
        Route::get('/events/{event}', [EventController::class, 'show'])->name('events.show');
        Route::patch('/events/{event}', [EventController::class, 'update']);
        Route::delete('/events/{event}', [EventController::class, 'destroy']);
        Route::get('/events/{event}/export', [EventController::class, 'export']);
        Route::post('/events/{event}/present/{person}', [EventController::class, 'markPresent']);
        Route::post('/events/{event}/scans/clear', [EventController::class, 'clearScans']);

        Route::post('/events/{event}/attendees/upload', [EventAttendeeController::class, 'upload']);
        Route::get('/events/{event}/attendees/preview/{token}', [EventAttendeeController::class, 'preview']);
        Route::post('/events/{event}/attendees/import', [EventAttendeeController::class, 'import']);
        Route::delete('/events/{event}/attendees/{person}', [EventAttendeeController::class, 'destroy']);

        Route::post('/confirm', [ReviewController::class, 'confirm']);
        Route::post('/confirm/auto', [ReviewController::class, 'autoMatchPending']);
        Route::post('/scans/{scan}/assign', [ReviewController::class, 'assign']);
        Route::post('/scans/{scan}/void', [ReviewController::class, 'void']);
        Route::post('/settings', [ReviewController::class, 'settings']);
        Route::get('/qr.svg', [ReviewController::class, 'qr']);
        Route::get('/search', [StationController::class, 'search']);

        Route::get('/roster', [RosterController::class, 'page'])->name('roster');
        Route::post('/roster/upload', [RosterController::class, 'upload']);
        Route::get('/roster/preview/{token}', [RosterController::class, 'preview']);
        Route::post('/roster/import', [RosterController::class, 'import']);
        Route::post('/roster/person', [RosterController::class, 'addPerson']);
        Route::delete('/roster/person/{person}', [RosterController::class, 'deletePerson']);

        Route::get('/accounts', [AccountController::class, 'index'])->name('accounts');
        Route::post('/accounts', [AccountController::class, 'store']);
        Route::post('/accounts/{user}/password', [AccountController::class, 'password']);
        Route::post('/accounts/{user}/active', [AccountController::class, 'toggle']);
        Route::post('/accounts/{user}/logout', [AccountController::class, 'logout']);
    });
});
