<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Setup · Attendance</title>
    <style>
        body { margin: 0; background: #f3f5f8; color: #16202e; font: 15px/1.45 system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; }
        main { max-width: 760px; margin: 0 auto; padding: 24px 16px 48px; }
        h1 { font-size: 22px; color: #1f3864; margin: 0 0 4px; }
        h2 { font-size: 16px; margin: 0 0 10px; }
        .muted { color: #5d6b7e; }
        .card { background: #fff; border: 1px solid #dde3ea; border-radius: 12px; padding: 16px; margin-top: 16px; }
        .check { display: flex; gap: 10px; padding: 8px 0; border-bottom: 1px solid #eef1f5; }
        .check:last-child { border-bottom: 0; }
        .ok { color: #117a3d; font-weight: 700; }
        .bad { color: #b3261e; font-weight: 700; }
        .fix { font-size: 13px; color: #9a5b00; margin-top: 2px; word-break: break-all; }
        .notice { padding: 10px 14px; border-radius: 10px; margin-top: 16px; }
        .notice.good { background: #e3f6ea; color: #117a3d; }
        .notice.error { background: #fde7e5; color: #b3261e; }
        pre { background: #0f1722; color: #dfe7f1; padding: 12px; border-radius: 8px; overflow-x: auto; font-size: 12px; }
        label { display: grid; gap: 4px; font-size: 13px; font-weight: 600; margin-bottom: 10px; }
        input { font: inherit; padding: 9px 12px; border: 1px solid #dde3ea; border-radius: 8px; }
        button { font: inherit; font-weight: 600; padding: 9px 16px; border-radius: 8px; border: 1px solid #1f3864; background: #1f3864; color: #fff; cursor: pointer; }
    </style>
</head>
<body>
<main>
    <h1>Attendance — setup</h1>
    <p class="muted">Server checks and first-time setup. When everything is green and the records account exists, remove <code>INSTALL_TOKEN</code> from <code>.env</code>.</p>

    @isset($result['message'])<div class="notice good">{{ $result['message'] }}</div>@endisset
    @isset($result['error'])<div class="notice error">{{ $result['error'] }}</div>@endisset
    @isset($result['output'])<pre>{{ $result['output'] }}</pre>@endisset

    <section class="card">
        <h2>1. Server checks</h2>
        @foreach ($checks as [$label, $passed, $fix])
            <div class="check">
                <span class="{{ $passed ? 'ok' : 'bad' }}">{{ $passed ? '✓' : '✗' }}</span>
                <div>
                    {{ $label }}
                    @if (! $passed && $fix)<div class="fix">{{ $fix }}</div>@endif
                </div>
            </div>
        @endforeach
    </section>

    @if ($canMigrate)
        <section class="card">
            <h2>2. Database</h2>
            <p class="muted">{{ $pending ? count($pending).' update(s) waiting.' : 'All tables are up to date.' }}</p>
            <form method="post" action="/install/migrate">
                <input type="hidden" name="token" value="{{ $token }}">
                <button>Set up / update database</button>
            </form>
        </section>
    @endif

    @if ($needsAdmin)
        <section class="card">
            <h2>3. Records account (for the dashboard)</h2>
            <form method="post" action="/install/admin">
                <input type="hidden" name="token" value="{{ $token }}">
                <label>Display name <input name="name" value="Records PC" required></label>
                <label>Username <input name="username" value="admin" autocapitalize="none" required></label>
                <label>Password (at least 8 characters) <input name="password" type="password" autocomplete="new-password" required></label>
                <label>Repeat password <input name="password_confirmation" type="password" autocomplete="new-password" required></label>
                <button>Create records account</button>
            </form>
        </section>
    @endif
</main>
</body>
</html>
