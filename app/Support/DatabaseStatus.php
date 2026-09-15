<?php

namespace App\Support;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The hosting has no terminal, so the app itself reports whether the database is reachable
 * and up to date, and runs the migrations when asked.
 */
class DatabaseStatus
{
    public function __construct(private Migrator $migrator) {}

    /** Null when the database is reachable, otherwise the connection error. */
    public function connectionError(): ?string
    {
        try {
            DB::connection()->getPdo();

            return null;
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }

    /**
     * Migration names that have not run yet (all of them on a fresh database).
     *
     * @return list<string>
     */
    public function pendingMigrations(): array
    {
        $files = array_keys($this->migrator->getMigrationFiles([database_path('migrations')]));
        $ran = $this->migrator->repositoryExists() ? $this->migrator->getRepository()->getRan() : [];

        return array_values(array_diff($files, $ran));
    }

    /** Runs the pending migrations and returns Artisan's output. */
    public function migrate(): string
    {
        Artisan::call('migrate', ['--force' => true]);

        return trim(Artisan::output());
    }
}
