<?php

use App\Models\User;
use App\Services\RosterImporter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
 * php artisan roster:import "C:\path\15SW Daily PSR.xlsx" --sheet="Daily PSR" --mode=replace
 * php artisan roster:import "C:\path\15SW Daily PSR.xlsx" --sheet="Combined Data" --mode=update --only=serial,office
 */
Artisan::command('roster:import {file} {--sheet=} {--mode=upsert} {--only=}', function (RosterImporter $importer) {
    $path = $this->argument('file');
    if (! is_file($path)) {
        $this->error("File not found: $path");

        return 1;
    }
    $sheet = $this->option('sheet') ?: $importer->sheets($path)['suggested'];
    $rows = $importer->rows($path, $sheet);
    [$headerIdx, $headers, $mapping] = $importer->guessLayout($rows);
    if ($only = $this->option('only')) {
        $mapping = array_intersect_key($mapping, array_flip(explode(',', $only)));
    }
    $this->info("Sheet: $sheet  (header on row ".($headerIdx + 1).')');
    foreach ($mapping as $field => $col) {
        $this->line(sprintf('  %-11s <- %s', $field, $headers[$col]));
    }
    $stats = $importer->import($rows, $headerIdx, $mapping, $this->option('mode'));
    $this->info(json_encode($stats));
})->purpose('Import personnel from an Excel/CSV roster');

/*
 * php artisan attendance:user admin --name="Records PC"             (asks for the password)
 * php artisan attendance:user gate1 --name="Gate 1" --role=scanner
 * Scanner accounts can also be created on the Accounts page.
 */
Artisan::command('attendance:user {username} {--name=} {--role=admin}', function () {
    $role = $this->option('role');
    if (! in_array($role, [User::ADMIN, User::SCANNER], true)) {
        $this->error('--role must be admin or scanner');

        return 1;
    }
    $password = $this->secret('Password (min 8 characters)');
    if (strlen((string) $password) < 8 || $password !== $this->secret('Repeat password')) {
        $this->error('Passwords must match and be at least 8 characters.');

        return 1;
    }
    $user = User::updateOrCreate(
        ['username' => $this->argument('username')],
        ['name' => $this->option('name') ?: $this->argument('username'), 'role' => $role, 'is_active' => true, 'password' => $password],
    );
    $user->logOutEverywhere();
    $this->info("Login ready: {$user->username} ({$user->role})");
})->purpose('Create an account or reset its password');

/*
 * Consistent snapshot of the SQLite database (run every 5 minutes by tools/gateway.mjs).
 */
Artisan::command('attendance:backup {--keep=48}', function () {
    if (DB::getDriverName() !== 'sqlite') {
        $this->line('Not SQLite: back up MySQL with mysqldump or your hosting panel.');

        return 0;
    }
    $dir = storage_path('app/backups');
    @mkdir($dir, 0775, true);
    $file = $dir.'/attendance-'.now()->format('Ymd-His').'.sqlite';
    DB::statement('VACUUM INTO ?', [$file]);
    $all = glob("$dir/attendance-*.sqlite");
    sort($all);
    foreach (array_slice($all, 0, max(0, count($all) - (int) $this->option('keep'))) as $old) {
        @unlink($old);
    }
    $this->line("Backup: $file");
})->purpose('Snapshot the attendance database into storage/app/backups');

/*
 * Self-signed HTTPS certificate for the phone gateway. Phones need HTTPS to use the camera.
 */
Artisan::command('attendance:cert {--force}', function () {
    $dir = storage_path('app/cert');
    $certPath = "$dir/cert.pem";
    $keyPath = "$dir/key.pem";
    if (is_file($certPath) && is_file($keyPath) && ! $this->option('force')) {
        $this->line('Certificate already exists.');

        return 0;
    }
    @mkdir($dir, 0775, true);

    $ips = array_unique(array_merge(['127.0.0.1', '192.168.137.1'], gethostbynamel(gethostname()) ?: []));
    $alt = ['DNS:localhost'];
    foreach ($ips as $ip) {
        $alt[] = "IP:$ip";
    }
    $cnf = tempnam(sys_get_temp_dir(), 'ossl');
    file_put_contents($cnf, "[req]\ndistinguished_name=dn\nx509_extensions=v3\nprompt=no\n[dn]\nCN=Attendance Laptop\n"
        ."[v3]\nsubjectAltName=".implode(',', $alt)."\nbasicConstraints=CA:FALSE\nkeyUsage=digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\n");
    $opts = ['config' => $cnf, 'digest_alg' => 'sha256', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'x509_extensions' => 'v3'];

    $key = openssl_pkey_new($opts);
    $csr = openssl_csr_new(['commonName' => 'Attendance Laptop'], $key, $opts);
    $cert = openssl_csr_sign($csr, null, $key, 825, $opts, random_int(1, PHP_INT_MAX));
    openssl_x509_export($cert, $certOut);
    openssl_pkey_export($key, $keyOut, null, $opts);
    file_put_contents($certPath, $certOut);
    file_put_contents($keyPath, $keyOut);
    @unlink($cnf);
    $this->info("Certificate written to $dir");
})->purpose('Create the HTTPS certificate used by the phone gateway');
