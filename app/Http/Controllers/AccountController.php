<?php

namespace App\Http\Controllers;

use App\Models\Scan;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Phone (scanner) accounts and records (admin) accounts. */
class AccountController extends Controller
{
    public function index(): Response
    {
        $today = now()->toDateString();
        $counts = Scan::where('day', $today)->where('status', '!=', 'void')->whereNotNull('user_id')
            ->selectRaw('user_id, count(*) as n, max(scanned_at) as last_at')->groupBy('user_id')->get()->keyBy('user_id');

        return Inertia::render('Accounts', [
            'accounts' => User::orderByRaw("role = 'admin' desc")->orderBy('name')->get()->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'username' => $u->username,
                'role' => $u->role,
                'is_active' => $u->is_active,
                'scans_today' => (int) ($counts[$u->id]->n ?? 0),
                'last_scan' => isset($counts[$u->id]) ? substr($counts[$u->id]->last_at, 11, 5) : null,
                'last_seen' => Cache::get("last_seen:{$u->id}"),
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:60',
            'username' => ['required', 'string', 'max:40', 'alpha_dash', Rule::unique('users', 'username')],
            'password' => 'required|string|min:6|max:100',
            'role' => ['required', Rule::in([User::SCANNER, User::ADMIN])],
        ]);
        User::create($data + ['is_active' => true]);

        return back()->with('success', "Account “{$data['name']}” created. Log in on the phone as {$data['username']}.");
    }

    public function password(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(['password' => 'required|string|min:6|max:100']);
        $user->forceFill(['password' => $data['password']])->save();
        if ($user->id !== $request->user()->id) {
            $user->logOutEverywhere();
        }

        return back()->with('success', "Password changed for {$user->name}.");
    }

    public function toggle(Request $request, User $user): RedirectResponse
    {
        if ($user->id === $request->user()->id) {
            return back()->with('error', 'You cannot disable the account you are using.');
        }
        $user->forceFill(['is_active' => ! $user->is_active])->save();

        return back()->with('success', $user->is_active ? "{$user->name} enabled." : "{$user->name} disabled and logged out.");
    }

    public function logout(Request $request, User $user): RedirectResponse
    {
        if ($user->id === $request->user()->id) {
            return back()->with('error', 'Use “Log out” at the top to log yourself out.');
        }
        $user->logOutEverywhere();

        return back()->with('success', "{$user->name} logged out on every phone.");
    }
}
