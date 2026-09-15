<?php

namespace Tests\Feature;

use App\Models\Personnel;
use App\Models\QrLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class ExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_report_has_checklist_summary_present_absent_and_log(): void
    {
        Carbon::setTestNow('2026-09-17 07:30:00');
        $r = $this->seedRoster();
        $event = $this->makeEvent(['name' => 'Safety Meeting', 'event_date' => '2026-09-17']);
        $extra = Personnel::create(['rank' => 'SSg', 'surname' => 'Morales', 'first_name' => 'Ben', 'serial' => '910001', 'squadron' => '20AS']);
        QrLink::create(['qr_text' => 'MAJ VILLAFUERTE, CEIS', 'personnel_id' => $r['villafuerte']->id]);
        QrLink::create(['qr_text' => 'SSG MORALES PAF, 20AS', 'personnel_id' => $extra->id]);
        $scan = fn (string $qr) => ['event_id' => $event->id, 'qr_text' => $qr];
        $this->actingAs($this->scanner())->postJson('/scan/record', $scan('MAJ VILLAFUERTE, CEIS'));
        $this->actingAs($this->scanner('Gate 2', 'gate2'))->postJson('/scan/record', $scan('CHR SANTOS, CEIS'));
        $this->actingAs($this->scanner('Gate 3', 'gate3'))->postJson('/scan/record', $scan('SSG MORALES PAF, 20AS'));

        $res = $this->actingAs($this->admin())->get("/events/{$event->id}/export")->assertOk();
        $this->assertStringContainsString('Safety_Meeting_2026-09-17.xlsx', $res->headers->get('content-disposition'));

        $path = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
        file_put_contents($path, $res->streamedContent());
        $book = IOFactory::load($path);

        $this->assertSame(['Checklist', 'Summary', 'Present', 'Unaccounted', 'Excused per PSR', 'To confirm', 'Scan log'], $book->getSheetNames());

        // Checklist: the attendee list with ✓ under Absent or Present, extras at the end, totals at the bottom.
        $list = $book->getSheetByName('Checklist')->toArray();
        $this->assertSame('Safety Meeting — 17 Sep 2026', $list[0][0]);
        $this->assertSame(['#', 'Rank', 'Name', 'Squadron', 'Absent', 'Present', 'Remarks'], $list[2]);
        $rows = collect(array_slice($list, 3, 7))->keyBy(2);
        $this->assertSame([null, '✓', 'In 07:30'], array_slice($rows['RAMON O VILLAFUERTE'], 4, 3));
        $this->assertSame(['✓', null, null], array_slice($rows['Paolo C Dela Cruz'], 4, 3));
        $this->assertSame(['✓', null, 'DEPLOYED'], array_slice($rows['Jose Dela Cruz'], 4, 3));
        $this->assertSame([null, '✓', 'In 07:30 · Not on list'], array_slice($rows['Ben Morales'], 4, 3));
        $this->assertEquals(['TOTAL', null, 5, 2], array_slice($list[10], 2, 4));

        $present = $book->getSheetByName('Present')->toArray();
        $this->assertSame(['MAJ', 'RAMON O VILLAFUERTE', 'HAS', 'ODCEIS'], array_slice($present[1], 1, 4));
        $this->assertEquals(['07:30:00', null, 'IN', 1, 'Gate 1', 'yes'], array_slice($present[1], 6, 6));
        $this->assertSame('not on list', $present[2][11]);
        $this->assertCount(1 + 4, $book->getSheetByName('Unaccounted')->toArray());     // header + 4 on duty, not scanned
        $this->assertSame('CHR SANTOS, CEIS', $book->getSheetByName('To confirm')->toArray()[1][1]);
        $this->assertCount(1 + 3, $book->getSheetByName('Scan log')->toArray());
    }
}
