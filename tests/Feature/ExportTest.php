<?php

namespace Tests\Feature;

use App\Models\QrLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class ExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_excel_report_has_summary_present_absent_and_log(): void
    {
        Carbon::setTestNow('2026-09-17 07:30:00');
        $r = $this->seedRoster();
        QrLink::create(['qr_text' => 'MAJ VILLAFUERTE, CEIS', 'personnel_id' => $r['villafuerte']->id]);
        $this->actingAs($this->scanner())->postJson('/scan/record', ['qr_text' => 'MAJ VILLAFUERTE, CEIS']);
        $this->actingAs($this->scanner('Gate 2', 'gate2'))->postJson('/scan/record', ['qr_text' => 'CHR SANTOS, CEIS']);

        $res = $this->actingAs($this->admin())->get('/export?date=2026-09-17')->assertOk();
        $this->assertStringContainsString('Attendance_2026-09-17.xlsx', $res->headers->get('content-disposition'));

        $path = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
        file_put_contents($path, $res->streamedContent());
        $book = IOFactory::load($path);

        $this->assertSame(['Checklist', 'Summary', 'Present', 'Unaccounted', 'Excused per PSR', 'To confirm', 'Scan log'], $book->getSheetNames());

        // Checklist: one row per person, ✓ under Absent or Present, totals at the bottom.
        $list = $book->getSheetByName('Checklist')->toArray();
        $this->assertSame(['#', 'Rank', 'Name', 'Squadron', 'Absent', 'Present', 'Remarks'], $list[2]);
        $rows = collect(array_slice($list, 3, 6))->keyBy(2);
        $this->assertSame([null, '✓', 'In 07:30'], array_slice($rows['RAMON O VILLAFUERTE'], 4, 3));
        $this->assertSame(['✓', null, null], array_slice($rows['Paolo C Dela Cruz'], 4, 3));
        $this->assertSame(['✓', null, 'DEPLOYED'], array_slice($rows['Jose Dela Cruz'], 4, 3));
        $this->assertEquals(['TOTAL', null, 5, 1], array_slice($list[9], 2, 4));
        $present = $book->getSheetByName('Present')->toArray();
        $this->assertSame(['MAJ', 'RAMON O VILLAFUERTE', 'HAS', 'ODCEIS'], array_slice($present[1], 1, 4));
        $this->assertEquals(['07:30:00', null, 'IN', 1, 'Gate 1'], array_slice($present[1], 6, 5));
        $this->assertCount(1 + 4, $book->getSheetByName('Unaccounted')->toArray());     // header + 4 on duty, not scanned
        $this->assertSame('CHR SANTOS, CEIS', $book->getSheetByName('To confirm')->toArray()[1][1]);
        $this->assertCount(1 + 2, $book->getSheetByName('Scan log')->toArray());
    }
}
