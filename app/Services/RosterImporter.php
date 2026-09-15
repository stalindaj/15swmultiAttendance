<?php

namespace App\Services;

use App\Models\Personnel;
use App\Models\QrLink;
use App\Models\Scan;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use RuntimeException;

/**
 * Reads a personnel list from Excel/CSV, guesses which column is which,
 * and loads it into the personnel table.
 */
class RosterImporter
{
    public const FIELDS = [
        'rank' => 'Rank',
        'surname' => 'Family name',
        'first_name' => 'First name',
        'middle' => 'Middle name / MI',
        'full_name' => 'Full name (if no separate columns)',
        'serial' => 'Serial number (SN)',
        'squadron' => 'Squadron',
        'office' => 'Office',
        'psr_status' => 'PSR status (ON DUTY, DEPLOYED…)',
    ];

    /** Header keywords per field, most specific first. */
    private const KEYWORDS = [
        'surname' => ['lastname', 'surname', 'familyname', 'lname', 'last', 'apelyido'],
        'first_name' => ['firstname', 'givenname', 'fname', 'first', 'given'],
        'middle' => ['middlename', 'middleinitial', 'mi', 'mname', 'middle'],
        'rank' => ['rank', 'grade'],
        'serial' => ['serialnumber', 'serialno', 'sn', 'afpsn', 'serial', 'idno', 'idnumber', 'employeeno', 'empno', 'badge'],
        'squadron' => ['squadron', 'sqdn', 'sqd', 'unit'],
        'office' => ['office', 'section', 'department', 'dept', 'division', 'assignment'],
        'psr_status' => ['status', 'psrstatus'],
        'full_name' => ['fullname', 'completename', 'nameofpersonnel', 'name', 'names', 'personnel'],
    ];

    public function store(UploadedFile $file): string
    {
        $ext = strtolower($file->getClientOriginalExtension());
        if ($ext === 'xls') {
            throw new RuntimeException('This is the old .xls format. Open it in Excel and use Save As → Excel Workbook (.xlsx).');
        }
        if (! in_array($ext, ['xlsx', 'xlsm', 'csv', 'txt'], true)) {
            throw new RuntimeException('Please upload an Excel (.xlsx) or CSV file.');
        }
        $token = Str::random(16);
        $file->move($this->dir(), "$token.$ext");

        return $token;
    }

    public function path(string $token): string
    {
        if (! preg_match('/^[A-Za-z0-9]{16}$/', $token)) {
            throw new RuntimeException('Bad upload token');
        }
        $matches = glob($this->dir()."/$token.*");
        if (! $matches) {
            throw new RuntimeException('Upload expired, please choose the file again.');
        }

        return $matches[0];
    }

    /** Sheet names with row counts, plus the sheet that looks most like a personnel list. */
    public function sheets(string $path): array
    {
        if ($this->isCsv($path)) {
            return ['sheets' => [['name' => 'CSV', 'rows' => count($this->csvRows($path))]], 'suggested' => 'CSV'];
        }
        $reader = IOFactory::createReader('Xlsx');
        $sheets = [];
        $best = null;
        $bestScore = -1;
        foreach ($reader->listWorksheetInfo($path) as $info) {
            $sheets[] = ['name' => $info['worksheetName'], 'rows' => $info['totalRows']];
            if ($info['totalRows'] < 3) {
                continue;
            }
            [$headerIdx, , $mapping] = $this->guessLayout($this->rows($path, $info['worksheetName'], 15));
            $score = $headerIdx >= 0 ? count($mapping) * 10000 + min($info['totalRows'], 9999) : 0;
            // Prefer a clean list: a sheet whose rows are mostly people beats a bigger messy one.
            if ($score > $bestScore) {
                $best = $info['worksheetName'];
                $bestScore = $score;
            }
        }

        return ['sheets' => $sheets, 'suggested' => $best];
    }

    /** @return list<list<string>> non-empty rows as trimmed strings */
    public function rows(string $path, string $sheet, ?int $maxRows = null): array
    {
        if ($this->isCsv($path)) {
            $rows = $this->csvRows($path);

            return $maxRows ? array_slice($rows, 0, $maxRows) : $rows;
        }

        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly([$sheet]);
        if ($maxRows) {
            $reader->setReadFilter(new class($maxRows) implements IReadFilter
            {
                public function __construct(private int $max) {}

                public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
                {
                    return $row <= $this->max;
                }
            });
        }
        $book = $reader->load($path);
        $ws = $book->getSheetByName($sheet) ?? $book->getActiveSheet();

        $rows = [];
        foreach ($ws->getRowIterator() as $row) {
            $cells = [];
            $it = $row->getCellIterator();
            $it->setIterateOnlyExistingCells(true);
            foreach ($it as $cell) {
                // Use the value Excel last calculated; never re-evaluate the workbook's formulas.
                $v = $cell->getDataType() === DataType::TYPE_FORMULA ? $cell->getOldCalculatedValue() : $cell->getValue();
                $idx = Coordinate::columnIndexFromString($cell->getColumn()) - 1;
                $cells[$idx] = $this->cellString($v);
            }
            if (! $cells || ! array_filter($cells, 'strlen')) {
                continue;
            }
            $width = max(array_keys($cells)) + 1;
            $line = array_fill(0, $width, '');
            foreach ($cells as $i => $v) {
                $line[$i] = $v;
            }
            $rows[] = $line;
        }
        $book->disconnectWorksheets();

        return $rows;
    }

    /** @return array{0:int,1:list<string>,2:array<string,int>} header row index, headers, field => column */
    public function guessLayout(array $rows): array
    {
        $bestIdx = -1;
        $bestHits = 0;
        foreach (array_slice($rows, 0, 15) as $i => $row) {
            $hits = 0;
            foreach ($row as $cell) {
                if ($cell !== '' && $this->matchesAnyField($cell)) {
                    $hits++;
                }
            }
            if ($hits > $bestHits) {
                [$bestIdx, $bestHits] = [$i, $hits];
            }
        }
        if ($bestHits < 2) {
            $bestIdx = -1;
        }

        $width = 0;
        foreach (array_slice($rows, 0, 50) as $r) {
            $width = max($width, count($r));
        }
        $headers = [];
        for ($i = 0; $i < $width; $i++) {
            $h = $bestIdx >= 0 ? ($rows[$bestIdx][$i] ?? '') : '';
            $headers[] = $h !== '' ? $h : 'Column '.Coordinate::stringFromColumnIndex($i + 1);
        }

        $mapping = [];
        if ($bestIdx >= 0) {
            $used = [];
            foreach (self::KEYWORDS as $field => $keywords) {
                if ($field === 'full_name' && isset($mapping['surname'])) {
                    continue;
                }
                foreach ($keywords as $kw) {
                    foreach ($headers as $i => $h) {
                        if (! isset($used[$i]) && $this->headerMatches($h, $kw)) {
                            $mapping[$field] = $i;
                            $used[$i] = true;

                            continue 3;
                        }
                    }
                }
            }
        }

        return [$bestIdx, $headers, $mapping];
    }

    /**
     * Modes:
     *   replace - wipe the roster, then add every row
     *   upsert  - update people with the same serial number, add the rest
     *   update  - only update people with the same serial number (e.g. fill in OFFICE from another sheet)
     */
    public function import(array $rows, int $headerIdx, array $mapping, string $mode, string $fixedSquadron = ''): array
    {
        if ($mode !== 'replace' && $mode !== 'upsert' && $mode !== 'update') {
            throw new RuntimeException('Unknown import mode');
        }
        if ($mode === 'update' && ! isset($mapping['serial'])) {
            throw new RuntimeException('“Update existing” matches people by serial number. Pick the SN column.');
        }
        if ($mode !== 'update' && ! isset($mapping['surname']) && ! isset($mapping['full_name'])) {
            throw new RuntimeException('Pick which column has the family name (or the full name).');
        }

        return WriteLock::run(fn () => DB::transaction(fn () => $this->importRows($rows, $headerIdx, $mapping, $mode, $fixedSquadron)));
    }

    private function importRows(array $rows, int $headerIdx, array $mapping, string $mode, string $fixedSquadron): array
    {
        $headerCells = $headerIdx >= 0 ? array_filter(array_map('strtoupper', $rows[$headerIdx])) : [];
        $stats = ['added' => 0, 'updated' => 0, 'skipped' => 0, 'not_found' => 0];

        if ($mode === 'replace') {
            if (Scan::exists()) {
                throw new RuntimeException('Scans are already recorded. Delete all scans on the dashboard first, or use “Add / update”.');
            }
            QrLink::query()->delete();
            Personnel::query()->delete();
        }

        $bySerial = Personnel::where('serial', '!=', '')->get()->keyBy(fn ($p) => Personnel::serialKey($p->serial));

        foreach (array_slice($rows, $headerIdx + 1) as $r) {
            $data = [];
            foreach ($mapping as $field => $col) {
                $data[$field] = trim($r[$col] ?? '');
            }
            // Some PSR sections put "A2C Juan D Cruz 976849 PAF" or a birth date in the OFFICE column.
            if (($data['office'] ?? '') !== '' && (preg_match('/\bPAF\b|^\d{4}-\d{2}-\d{2}|^\d+$/', $data['office'])
                    || (($data['serial'] ?? '') !== '' && str_contains($data['office'], $data['serial'])))) {
                $data['office'] = '';
            }
            $name = ($data['surname'] ?? '') ?: ($data['full_name'] ?? '');

            // Skip repeated header rows, legends and notes: a person row has a name plus a rank, first name or SN.
            $isHeaderRepeat = $name !== '' && in_array(strtoupper($name), $headerCells, true);
            $looksLikePerson = $mode === 'update'
                ? ($data['serial'] ?? '') !== ''
                : $name !== '' && (($data['rank'] ?? '') !== '' || ($data['first_name'] ?? '') !== '' || ($data['serial'] ?? '') !== '');
            if ($isHeaderRepeat || ! $looksLikePerson) {
                $stats['skipped']++;

                continue;
            }
            if (($data['squadron'] ?? '') === '' && $fixedSquadron !== '') {
                $data['squadron'] = $fixedSquadron;
            }

            $existing = ($data['serial'] ?? '') !== '' ? $bySerial->get(Personnel::serialKey($data['serial'])) : null;
            if ($existing) {   // same SN seen before (in the roster, or earlier in this file)
                $existing->fill(array_filter($data, 'strlen'))->save();
                $stats['updated']++;
            } elseif ($mode === 'update') {
                $stats['not_found']++;
            } else {
                $p = Personnel::create($data);
                if ($p->serial !== '') {
                    $bySerial->put(Personnel::serialKey($p->serial), $p);
                }
                $stats['added']++;
            }
        }

        return $stats;
    }

    private function dir(): string
    {
        $dir = storage_path('app/imports');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir;
    }

    private function isCsv(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['csv', 'txt'], true);
    }

    private function csvRows(string $path): array
    {
        $text = file_get_contents($path);
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }
        $firstLine = strtok($text, "\n");
        $delim = collect([',', ';', "\t"])->sortByDesc(fn ($d) => substr_count($firstLine, $d))->first();
        $rows = [];
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $text);
        rewind($fh);
        while (($r = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
            $r = array_map(fn ($c) => $this->cellString($c), $r);
            if (array_filter($r, 'strlen')) {
                $rows[] = $r;
            }
        }
        fclose($fh);

        return $rows;
    }

    private function cellString(mixed $v): string
    {
        if ($v === null) {
            return '';
        }
        if ($v instanceof RichText) {
            $v = $v->getPlainText();
        }
        if (is_float($v) && floor($v) == $v && abs($v) < 1e15) {
            return (string) (int) $v;
        }
        if (is_bool($v)) {
            return $v ? 'TRUE' : 'FALSE';
        }
        $s = trim(preg_replace('/\s+/u', ' ', (string) $v) ?? (string) $v);

        return str_starts_with($s, '#') && in_array($s, ['#REF!', '#N/A', '#VALUE!', '#DIV/0!', '#NAME?'], true) ? '' : $s;
    }

    private function matchesAnyField(string $cell): bool
    {
        foreach (self::KEYWORDS as $keywords) {
            foreach ($keywords as $kw) {
                if ($this->headerMatches($cell, $kw)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function headerMatches(string $header, string $kw): bool
    {
        $h = preg_replace('/[^a-z0-9]/', '', strtolower($header));
        if ($kw === 'office' && str_contains($h, 'officer')) {
            return false;   // "OFFICER / EP" is a category column, not the office
        }

        return $h === $kw || (strlen($kw) >= 4 && str_contains($h, $kw));
    }
}
