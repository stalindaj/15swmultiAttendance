<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AuthController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'username' => 'required|string|max:120',
            'password' => 'required|string',
        ]);

        // Phones on the internet link all arrive through the same tunnel, so limit per account name.
        $throttleKey = 'login:'.strtolower($data['username']);
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages([
                'username' => 'Too many attempts. Try again in '.RateLimiter::availableIn($throttleKey).' seconds.',
            ]);
        }

        $field = str_contains($data['username'], '@') ? 'email' : 'username';
        $ok = Auth::attempt([$field => $data['username'], 'password' => $data['password'], 'is_active' => true], $request->boolean('remember'));
        if (! $ok) {
            RateLimiter::hit($throttleKey, 60);
            throw ValidationException::withMessages(['username' => 'Wrong username or password.']);
        }
        RateLimiter::clear($throttleKey);

        $request->session()->regenerate();
        $request->session()->put('session_version', $request->user()->session_version);

        return $request->user()->isAdmin() ? redirect()->intended('/') : redirect('/scan');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
