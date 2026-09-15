<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\DatabaseStatus;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * First-time setup on hosting without a terminal: checks the server, creates the database
 * tables and the first records (admin) account.
 *
 * Only reachable while INSTALL_TOKEN is set in .env, and only with that token in the URL.
 * It runs without sessions or cookies so it works before APP_KEY is set.
 */
class InstallController extends Controller
{
    private const EXTENSIONS = ['pdo_mysql', 'mbstring', 'openssl', 'fileinfo', 'zip', 'gd', 'xml', 'xmlreader', 'xmlwriter', 'dom', 'simplexml', 'zlib', 'iconv', 'ctype'];

    public function __construct(private DatabaseStatus $database) {}

    public function show(Request $request): View
    {
        $this->authorizeToken($request);

        return $this->page($request);
    }

    public function migrate(Request $request): View
    {
        $this->authorizeToken($request);
        $output = $this->database->migrate();

        return $this->page($request, ['message' => 'Database updated.', 'output' => $output]);
    }

    public function createAdmin(Request $request): View
    {
        $this->authorizeToken($request);
        if (User::where('role', User::ADMIN)->exists()) {
            return $this->page($request, ['error' => 'A records account already exists. Log in with it and use the Accounts page.']);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:60',
            'username' => 'required|string|max:40|alpha_dash|unique:users,username',
            'password' => 'required|string|min:8|max:100|confirmed',
        ]);
        if ($validator->fails()) {
            return $this->page($request, ['error' => $validator->errors()->first()]);
        }

        User::create($validator->validated() + ['role' => User::ADMIN, 'is_active' => true]);

        return $this->page($request, ['message' => 'Records account created. Remove INSTALL_TOKEN from .env, then log in.']);
    }

    private function authorizeToken(Request $request): void
    {
        $token = (string) config('attendance.install_token');
        abort_if($token === '' || ! hash_equals($token, (string) $request->input('token')), 404);
    }

    /**
     * @param  array{message?: string, error?: string, output?: string}  $result
     */
    private function page(Request $request, array $result = []): View
    {
        $dbError = $this->database->connectionError();
        $pending = $dbError === null ? $this->database->pendingMigrations() : [];
        $hasAdmin = $dbError === null && $pending === [] && User::where('role', User::ADMIN)->exists();
        $missingExtensions = array_values(array_filter(self::EXTENSIONS, fn ($e) => ! extension_loaded($e)));

        $checks = [
            ['PHP 8.3 or newer', version_compare(PHP_VERSION, '8.3.0', '>='), 'This server runs PHP '.PHP_VERSION.'. Choose 8.3 or 8.4 in cPanel → MultiPHP Manager.'],
            ['PHP extensions', $missingExtensions === [], 'Missing: '.implode(', ', $missingExtensions).'. Enable them in cPanel → Select PHP Version → Extensions.'],
            ['APP_KEY is set', config('app.key') !== '' && config('app.key') !== null, 'Add this line to .env: APP_KEY=base64:'.base64_encode(random_bytes(32))],
            ['storage/ is writable', is_writable(storage_path('framework')) && is_writable(storage_path('logs')), 'Set the storage folder (and its subfolders) to 755 in File Manager.'],
            ['bootstrap/cache/ is writable', is_writable(base_path('bootstrap/cache')), 'Set bootstrap/cache to 755 in File Manager.'],
            ['Database connection', $dbError === null, 'Check DB_DATABASE, DB_USERNAME and DB_PASSWORD in .env. '.$dbError],
            ['Database is up to date', $dbError === null && $pending === [], $pending ? count($pending).' update(s) to run: press “Set up / update database”.' : ''],
            ['Debug mode is off', ! config('app.debug'), 'Set APP_DEBUG=false in .env (shows errors to everyone otherwise).'],
            ['Records pages open online', ! config('attendance.admin_localhost_only'), 'Set ATTENDANCE_ADMIN_LOCALHOST_ONLY=false in .env.'],
            ['APP_URL has no trailing slash', ! str_ends_with((string) config('app.url'), '/'), 'Remove the “/” at the end of APP_URL in .env.'],
            ['Site opened over https', $request->isSecure(), 'cPanel → SSL/TLS Status → select the domain → Run AutoSSL, then open this page with https://.'],
            ['HTTPS enforced', ! $request->isSecure() || (config('attendance.force_https') && config('session.secure')),
                'The certificate works: set FORCE_HTTPS=true and SESSION_SECURE_COOKIE=true in .env.'],
        ];

        return view('install', [
            'token' => (string) $request->input('token'),
            'checks' => $checks,
            'canMigrate' => $dbError === null,
            'pending' => $pending,
            'needsAdmin' => $dbError === null && $pending === [] && ! $hasAdmin,
            'result' => $result,
        ]);
    }
}
