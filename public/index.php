<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// vendor/ only exists on the "deploy" branch (built by GitHub Actions). Without it PHP would die
// here before Laravel starts: a blank 500 and no laravel.log. Say what to do instead.
if (! file_exists(__DIR__.'/../vendor/autoload.php')) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "This server has the source code but not the built app (the vendor/ folder is missing).\n\n"
        ."cPanel → Git Version Control → Manage → Basic Information → Checked-Out Branch: choose \"deploy\" → Update.\n"
        ."Then Pull or Deploy → Update from Remote.\n";
    exit;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
