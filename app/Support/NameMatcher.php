<?php

namespace App\Support;

/**
 * Turns ID QR text such as "CHR SANTOS, CEIS" into rank / family name / office
 * and produces the normalized keys used to compare it against the roster.
 */
final class NameMatcher
{
    private const MULTI_WORD_RANKS = ['LT COL', 'LT GEN', 'MAJ GEN', 'BRIG GEN', '1ST LT', '2ND LT'];

    private const SUFFIXES = ['JR', 'SR', 'II', 'III', 'IV', 'V'];

    /** Branch of service / designations printed after the name: "2LT RIVERA PAF", "COL REYES PAF(GSC)". */
    public const BRANCH_TOKENS = ['PAF', 'PA', 'PN', 'PMC', 'PCG', 'AFP', 'GSC', 'MNSA'];

    /** All words of the QR text, without the branch of service. "2LT RIVERA PAF, ODP" -> [2LT, RIVERA, ODP] */
    public static function tokens(string $text): array
    {
        $words = explode(' ', self::norm(str_replace(',', ' ', $text)));

        return array_values(array_filter($words, fn ($w) => $w !== '' && ! in_array($w, self::BRANCH_TOKENS, true)));
    }

    /**
     * Every run of 1-3 words that could be a family name, as surname keys. Family names can sit
     * anywhere: "2LT RIVERA PAF, ODP", "SGT MARIA LUISA E SORIANO HAS", "TSG DELA CRUZ, ODI".
     */
    public static function surnameKeysIn(string $text): array
    {
        $words = self::tokens($text);
        $keys = [];
        for ($i = 0; $i < count($words); $i++) {
            for ($n = 1; $n <= 3 && $i + $n <= count($words); $n++) {
                $key = implode('', array_slice($words, $i, $n));
                if (strlen($key) > 1) {
                    $keys[$key] = true;
                }
            }
        }

        return array_keys($keys);
    }

    /** Uppercase ASCII, punctuation removed, single spaces. "Peña-Cruz" -> "PENA CRUZ". */
    public static function norm(?string $s): string
    {
        $s = (string) $s;
        // The PSR workbook has already lost its Ñ characters ("OCA�A"); Ñ is by far the likeliest original.
        $s = strtr($s, ["\u{FFFD}" => 'N', 'Ñ' => 'N', 'ñ' => 'n']);
        if (class_exists(\Normalizer::class)) {
            $s = \Normalizer::normalize($s, \Normalizer::FORM_KD) ?: $s;
        }
        $s = preg_replace('/[\x80-\xFF]/', '', $s);           // drop accents / anything non-ASCII
        $s = strtoupper(preg_replace('/[^A-Za-z0-9 ]+/', ' ', $s));

        return trim(preg_replace('/\s+/', ' ', $s));
    }

    public static function squash(?string $s): string
    {
        return str_replace(' ', '', self::norm($s));
    }

    /** "DELA CRUZ JR" -> "DELACRUZ" */
    public static function surnameKey(?string $s): string
    {
        $tokens = explode(' ', self::norm($s));
        while (count($tokens) > 1 && in_array(end($tokens), self::SUFFIXES, true)) {
            array_pop($tokens);
        }

        return implode('', $tokens);
    }

    /** "SSg (T)" and "SSG" compare equal. */
    public static function rankKey(?string $s): string
    {
        return str_replace(' ', '', preg_replace('/ T$/', '', self::norm($s)));
    }

    /** "CHR SANTOS, CEIS" -> ['rank' => 'CHR', 'surname' => 'SANTOS', 'unit' => 'CEIS'] */
    public static function parseQr(string $text): array
    {
        $text = trim($text);
        $unit = '';
        if (str_contains($text, ',')) {
            $pos = strrpos($text, ',');
            $unit = substr($text, $pos + 1);
            $text = substr($text, 0, $pos);
        }

        $tokens = self::tokens($text);
        $rank = '';
        if (count($tokens) >= 3 && in_array($tokens[0].' '.$tokens[1], self::MULTI_WORD_RANKS, true)) {
            $rank = $tokens[0].' '.$tokens[1];
            $tokens = array_slice($tokens, 2);
        } elseif (count($tokens) >= 2) {
            $rank = array_shift($tokens);
        }

        return ['rank' => $rank, 'surname' => implode(' ', $tokens), 'unit' => self::norm($unit)];
    }

    /** Does the QR's unit ("CEIS") fit the person's office ("ODCEIS") or squadron ("HAS")? */
    public static function unitMatches(string $qrUnitKey, string ...$rosterKeys): bool
    {
        if ($qrUnitKey === '') {
            return false;
        }
        foreach ($rosterKeys as $key) {
            if ($key !== '' && (str_contains($key, $qrUnitKey) || str_contains($qrUnitKey, $key))) {
                return true;
            }
        }

        return false;
    }
}
