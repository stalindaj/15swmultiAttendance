<?php

namespace App\Services;

use App\Models\Personnel;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ExcelExport
{
    public function build(AttendanceReport $r): Spreadsheet
    {
        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);
        $title = $r->event->label();

        $this->checklist($book, $r, $title);

        $rows = [];
        $totals = ['total' => 0, 'present' => 0, 'excused' => 0, 'unaccounted' => 0];
        foreach ($r->bySquadron() as $s) {
            $rows[] = [$s['squadron'], $s['total'], $s['present'], $s['excused'], $s['unaccounted'], $this->pct($s['present'], $s['total'])];
            foreach ($totals as $k => $_) {
                $totals[$k] += $s[$k];
            }
        }
        $rows[] = ['TOTAL', $totals['total'], $totals['present'], $totals['excused'], $totals['unaccounted'], $this->pct($totals['present'], $totals['total'])];
        $ws = $this->sheet($book, 'Summary', ['Squadron', 'On list', 'Present', 'Excused per PSR', 'Unaccounted', '% present'], $rows, [22, 10, 10, 16, 13, 11], 3);
        $ws->setCellValue('A1', $title);
        $ws->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $ws->setCellValue('A2', 'Extras present (not on the list): '.$r->extras()->count()
            .'   ·   Scans not yet confirmed: '.$r->pendingScans()->count().' (see "To confirm")');
        $ws->getStyle('A'.$ws->getHighestRow().':F'.$ws->getHighestRow())->getFont()->setBold(true);

        $person = fn (Personnel $p) => [$p->rank, $p->full_name, $p->squadron, $p->office, $p->serial];

        $present = collect($r->present)->keys()->map(fn ($id) => $r->people[$id])
            ->sortBy([fn ($a, $b) => $r->isOnList($b) <=> $r->isOnList($a), ['squadron', 'asc'], ['surname', 'asc']]);
        $this->sheet($book, 'Present', ['#', 'Rank', 'Name', 'Squadron', 'Office', 'SN', 'First in', 'Last out', 'Status now', 'Scans', 'Station', 'On list'],
            $present->values()->map(fn ($p, $i) => [$i + 1, ...$person($p), $r->present[$p->id]['in'], $r->present[$p->id]['out'],
                $r->present[$p->id]['status'], $r->present[$p->id]['moves'], $r->present[$p->id]['station'],
                $r->isOnList($p) ? 'yes' : ($p->is_walk_in ? 'walk-in' : 'not on list')])->all(),
            [6, 10, 32, 12, 14, 12, 10, 10, 11, 7, 14, 11]);

        $this->sheet($book, 'Unaccounted', ['#', 'Rank', 'Name', 'Squadron', 'Office', 'SN', 'PSR status'],
            $r->unaccounted()->values()->map(fn ($p, $i) => [$i + 1, ...$person($p), $p->psr_status])->all(),
            [6, 10, 32, 12, 14, 12, 16]);

        $this->sheet($book, 'Excused per PSR', ['#', 'Rank', 'Name', 'Squadron', 'Office', 'SN', 'PSR status'],
            $r->excused()->sortBy('psr_status')->values()->map(fn ($p, $i) => [$i + 1, ...$person($p), $p->psr_status])->all(),
            [6, 10, 32, 12, 14, 12, 22]);

        $this->sheet($book, 'To confirm', ['Time', 'Scanned QR text', 'In/Out', 'Station'],
            $r->pendingScans()->map(fn ($s) => [$s->scanned_at->format('H:i:s'), $s->qr_text, $s->kind, $s->station])->values()->all(),
            [10, 36, 8, 14]);

        $this->sheet($book, 'Scan log', ['Time', 'In/Out', 'Status', 'Rank', 'Name', 'Squadron', 'QR text', 'Station', 'Method', 'Note'],
            $r->scans->map(function ($s) use ($r) {
                $p = $s->personnel_id ? $r->people->get($s->personnel_id) : null;

                return [$s->scanned_at->format('H:i:s'), $s->kind, $s->status, $p?->rank, $p?->full_name, $p?->squadron,
                    $s->qr_text, $s->station, $s->method, $s->note];
            })->values()->all(),
            [10, 8, 10, 10, 32, 12, 26, 14, 10, 26]);

        $book->setActiveSheetIndex(0);

        return $book;
    }

    /**
     * One row per person: a ✓ under Absent or under Present, ready to print.
     *   # | Rank | Name | Squadron | Absent | Present | Remarks
     */
    private function checklist(Spreadsheet $book, AttendanceReport $r, string $title): void
    {
        $check = '✓';
        $people = $r->roster()->values()->concat($r->extras()->values());

        $rows = $people->map(function (Personnel $p, $i) use ($r, $check) {
            $row = $r->present[$p->id] ?? null;
            if ($row) {
                $remarks = trim(($row['in'] ? 'In '.substr($row['in'], 0, 5) : '')
                    .($row['status'] === 'OUT' && $row['out'] ? ' · out '.substr($row['out'], 0, 5) : ''), ' ·');
                if (! $r->isOnList($p)) {
                    $remarks .= ($remarks ? ' · ' : '').($p->is_walk_in ? 'Walk-in' : 'Not on list');
                }
            } else {
                $remarks = $p->isExpectedPresent() ? '' : $p->psr_status;   // e.g. DEPLOYED, ORD LEAVE
            }

            return [$i + 1, $p->rank, $p->full_name, $p->squadron, $row ? '' : $check, $row ? $check : '', $remarks];
        })->all();

        $ws = $this->sheet($book, 'Checklist', ['#', 'Rank', 'Name', 'Squadron', 'Absent', 'Present', 'Remarks'], $rows, [6, 10, 34, 12, 10, 10, 26], 3);
        $ws->setCellValue('A1', $title);
        $ws->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $ws->setCellValue('A2', 'Remarks: time in for those present (extras at the end: not on the list); PSR status (deployed, leave…) for those absent.');
        $ws->getStyle('A2')->getFont()->setItalic(true)->getColor()->setARGB('FF5D6B7E');

        $first = 4;
        $last = $first + count($rows) - 1;
        if ($rows) {
            $ws->getStyle("A3:G$last")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FFC9D1DB');
            $ws->getStyle("E$first:F$last")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $ws->getStyle("E$first:F$last")->getFont()->setBold(true)->setSize(13);
            $ws->getStyle("E$first:E$last")->getFont()->getColor()->setARGB('FFB3261E');
            $ws->getStyle("F$first:F$last")->getFont()->getColor()->setARGB('FF117A3D');
            foreach ($rows as $i => $row) {
                $n = $first + $i;
                $cell = $row[5] === $check ? "F$n" : "E$n";
                $ws->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($row[5] === $check ? 'FFE3F6EA' : 'FFFDE7E5');
            }
        }

        // Totals that stay right if someone edits a ✓ by hand.
        $total = $last + 1;
        $ws->setCellValue("C$total", 'TOTAL');
        $ws->setCellValue("E$total", "=COUNTIF(E$first:E$last,\"$check\")");
        $ws->setCellValue("F$total", "=COUNTIF(F$first:F$last,\"$check\")");
        $ws->getStyle("A$total:G$total")->getFont()->setBold(true);
        $ws->getStyle("E$total:F$total")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $ws->getStyle("A$total:G$total")->getBorders()->getTop()->setBorderStyle(Border::BORDER_MEDIUM);

        $ws->getPageSetup()->setOrientation(PageSetup::ORIENTATION_PORTRAIT)->setFitToWidth(1)->setFitToHeight(0)
            ->setRowsToRepeatAtTopByStartAndEnd(3, 3);
        $ws->getPageMargins()->setTop(0.5)->setBottom(0.5)->setLeft(0.4)->setRight(0.4);
        $ws->getHeaderFooter()->setOddFooter('&L'.$title.'&RPage &P of &N');
    }

    public function save(Spreadsheet $book, string $path): void
    {
        (new Xlsx($book))->save($path);
    }

    private function sheet(Spreadsheet $book, string $title, array $headers, array $rows, array $widths, int $headerRow = 1): Worksheet
    {
        $ws = $book->createSheet();
        $ws->setTitle($title);
        $ws->fromArray($headers, null, 'A'.$headerRow);
        if ($rows) {
            $ws->fromArray($rows, null, 'A'.($headerRow + 1), true);
        }
        $last = Coordinate::stringFromColumnIndex(count($headers));
        $style = $ws->getStyle("A{$headerRow}:{$last}{$headerRow}");
        $style->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF1F3864');
        foreach ($widths as $i => $w) {
            $ws->getColumnDimension(Coordinate::stringFromColumnIndex($i + 1))->setWidth($w);
        }
        $ws->freezePane('A'.($headerRow + 1));
        if ($rows) {
            $ws->setAutoFilter("A{$headerRow}:{$last}".($headerRow + count($rows)));
        }

        return $ws;
    }

    private function pct(int $n, int $d): string
    {
        return $d ? round($n * 100 / $d).'%' : '';
    }
}
