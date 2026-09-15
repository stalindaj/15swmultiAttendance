<?php

namespace App\Services;

/**
 * Several PHP worker processes share one SQLite file. Serializing writes with a
 * file lock avoids "database is locked" races when phones scan at the same moment.
 */
final class WriteLock
{
    public static function run(callable $fn): mixed
    {
        $path = storage_path('app/write.lock');
        $fp = fopen($path, 'c');
        flock($fp, LOCK_EX);
        try {
            return $fn();
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
}
