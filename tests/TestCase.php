<?php

namespace Tests;

use App\Models\Personnel;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    protected function admin(): User
    {
        return User::factory()->admin()->create(['name' => 'Records PC', 'username' => 'admin']);
    }

    protected function scanner(string $name = 'Gate 1', string $username = 'gate1'): User
    {
        return User::factory()->create(['name' => $name, 'username' => $username]);
    }

    /** A small roster shaped like the 15SW Daily PSR. */
    protected function seedRoster(): array
    {
        $p = fn (array $a) => Personnel::create($a + ['psr_status' => 'ON DUTY']);

        return [
            'villafuerte' => $p(['rank' => 'MAJ', 'surname' => 'VILLAFUERTE', 'first_name' => 'RAMON', 'middle' => 'O', 'serial' => 'O-10001', 'squadron' => 'HAS', 'office' => 'ODCEIS']),
            'dc_odi' => $p(['rank' => 'TSg', 'surname' => 'Dela Cruz', 'first_name' => 'Paolo', 'middle' => 'C', 'serial' => '800001', 'squadron' => 'HAS', 'office' => 'ODI']),
            'dc_other' => $p(['rank' => 'TSg', 'surname' => 'Dela Cruz', 'first_name' => 'Mark Anthony', 'middle' => 'M', 'serial' => '800002', 'squadron' => '462RWFMS']),
            'dc_deployed' => $p(['rank' => 'A2C', 'surname' => 'Dela Cruz', 'first_name' => 'Jose', 'serial' => '900003', 'squadron' => '463AAMS', 'psr_status' => 'DEPLOYED']),
            'gonzales_am' => $p(['rank' => 'AM', 'surname' => 'Gonzales', 'first_name' => 'Kevin', 'middle' => 'B', 'serial' => '900004', 'squadron' => 'HAS', 'office' => 'ODP']),
            'ocana' => $p(['rank' => 'MAJ', 'surname' => 'OCAÑA', 'first_name' => 'LUIS', 'middle' => 'T', 'serial' => 'O-10002', 'squadron' => 'HAS', 'office' => 'WOC']),
        ];
    }
}
