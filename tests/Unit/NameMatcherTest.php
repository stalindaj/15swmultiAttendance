<?php

namespace Tests\Unit;

use App\Support\NameMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NameMatcherTest extends TestCase
{
    public static function qrTexts(): array
    {
        return [
            'civilian' => ['CHR SANTOS, CEIS', ['rank' => 'CHR', 'surname' => 'SANTOS', 'unit' => 'CEIS']],
            'two-word family name' => ['TSG DELA CRUZ, ODI', ['rank' => 'TSG', 'surname' => 'DELA CRUZ', 'unit' => 'ODI']],
            'two-word rank' => ['LT COL SANTOS, HAS', ['rank' => 'LT COL', 'surname' => 'SANTOS', 'unit' => 'HAS']],
            'no unit' => ['MAJ REYES', ['rank' => 'MAJ', 'surname' => 'REYES', 'unit' => '']],
            'extra spaces and case' => ['  sgt   luna ,  has ', ['rank' => 'SGT', 'surname' => 'LUNA', 'unit' => 'HAS']],
        ];
    }

    #[DataProvider('qrTexts')]
    public function test_parses_id_qr_text(string $qr, array $expected): void
    {
        $this->assertSame($expected, NameMatcher::parseQr($qr));
    }

    public function test_normalizes_accents_and_the_psr_lost_n_tilde(): void
    {
        $this->assertSame('OCANA', NameMatcher::norm('Ocaña'));
        $this->assertSame('OCANA', NameMatcher::norm("OCA\u{FFFD}A"));   // how the PSR workbook stores it
        $this->assertSame('PENA CRUZ', NameMatcher::norm('Peña-Cruz'));
    }

    public function test_surname_key_ignores_spaces_and_suffixes(): void
    {
        $this->assertSame('DELACRUZ', NameMatcher::surnameKey('Dela Cruz'));
        $this->assertSame('AQUINO', NameMatcher::surnameKey('AQUINO JR'));
        $this->assertSame('GONZALES', NameMatcher::surnameKey('GONZALES III'));
    }

    public function test_rank_key_treats_trainee_marker_as_same_rank(): void
    {
        $this->assertSame(NameMatcher::rankKey('SSG'), NameMatcher::rankKey('SSg (T)'));
        $this->assertNotSame(NameMatcher::rankKey('SSG'), NameMatcher::rankKey('TSg'));
    }

    public function test_qr_unit_matches_office_or_squadron(): void
    {
        $this->assertTrue(NameMatcher::unitMatches('CEIS', 'ODCEIS', 'HAS'));
        $this->assertTrue(NameMatcher::unitMatches('HAS', '', 'HAS'));
        $this->assertFalse(NameMatcher::unitMatches('ODP', 'ODI', 'HAS'));
        $this->assertFalse(NameMatcher::unitMatches('', 'ODI', 'HAS'));
    }
}
