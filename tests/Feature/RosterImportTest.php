<?php

namespace Tests\Feature;

use App\Models\Personnel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class RosterImportTest extends TestCase
{
    use RefreshDatabase;

    /** A workbook shaped like the 15SW Daily PSR: a clean list plus a messy sheet that has OFFICE. */
    private function psrWorkbook(): string
    {
        $book = new Spreadsheet;
        $recap = $book->getActiveSheet()->setTitle('RECAP');
        $recap->fromArray([['UNIT', 'OFF', 'EP'], ['HAS', 32, 104]]);

        $psr = $book->createSheet()->setTitle('Daily PSR');
        $psr->fromArray([
            ['NR', 'RANK', 'LASTNAME', 'FIRST NAME', 'MI', 'SN', 'BOS', 'UNIT', 'SQUADRON', 'STATUS', 'OFFICER / EP'],
            [1, 'MAJ', 'VILLAFUERTE', 'RAMON', 'O', 'O-10001', 'PAF', '15SW', 'HAS', 'DEPLOYED', 'OFFICER'],
            [2, 'TSg', 'Dela Cruz', 'Paolo', 'C', '800001', 'PAF', '15SW', 'HAS', 'ON DUTY', 'EP'],
            [3, 'AM', 'Gonzales', 'Kevin', 'B', '900004', 'PAF', '15SW', 'HAS', 'ON DUTY', 'EP'],
            [4, 'AM', 'Gonzales', 'Kevin', 'B', '900004', 'PAF', '15SW', 'HAS', 'ON DUTY', 'EP'],   // same SN twice
        ]);

        $combined = $book->createSheet()->setTitle('Combined Data');
        $combined->fromArray([
            ['Rank', 'LASTNAME', 'FIRSTNAME', 'MI', 'SN', 'SQUADRON', 'STATUS', 'OFFICE'],
            ['MAJ', 'VILLAFUERTE', 'RAMON', 'O', 'O-10001 ', 'HAS', 'DEPLOYED', 'ODCEIS'],
            ['TSg', 'Dela Cruz', 'Paolo', 'C', '800001', 'HAS', 'ON DUTY', 'TSg Paolo C Dela Cruz 800001 PAF'],   // junk office
            [null, 'LEGEND', 'COLOR', null, null, null, null, null],
            ['SSg', 'Stranger', 'Not', 'X', '111111', 'HAS', 'ON DUTY', 'ODL'],
        ]);

        $path = tempnam(sys_get_temp_dir(), 'psr').'.xlsx';
        (new Xlsx($book))->save($path);

        return $path;
    }

    public function test_imports_the_daily_psr_then_fills_offices_from_combined_data(): void
    {
        $this->actingAs($this->admin());
        $file = new UploadedFile($this->psrWorkbook(), '15SW Daily PSR.xlsx', null, null, true);

        $upload = $this->postJson('/roster/upload', ['file' => $file])->assertOk()->json();
        $this->assertSame(['RECAP', 'Daily PSR', 'Combined Data'], array_column($upload['sheets'], 'name'));

        $preview = $this->getJson("/roster/preview/{$upload['token']}?sheet=Daily%20PSR")->assertOk()->json();
        $mapped = array_map(fn ($i) => $preview['headers'][$i], $preview['mapping']);
        $this->assertSame(['surname' => 'LASTNAME', 'first_name' => 'FIRST NAME', 'middle' => 'MI', 'rank' => 'RANK',
            'serial' => 'SN', 'squadron' => 'SQUADRON', 'psr_status' => 'STATUS'], $mapped);   // not UNIT, not "OFFICER / EP"

        $this->postJson('/roster/import', [
            'token' => $upload['token'], 'sheet' => 'Daily PSR', 'header_row' => $preview['header_row'],
            'mapping' => $preview['mapping'], 'mode' => 'replace',
        ])->assertOk()->assertJson(['added' => 3, 'updated' => 1]);   // duplicate SN merged, not added twice

        $combined = $this->getJson("/roster/preview/{$upload['token']}?sheet=Combined%20Data")->json();
        $this->postJson('/roster/import', [
            'token' => $upload['token'], 'sheet' => 'Combined Data', 'header_row' => $combined['header_row'],
            'mapping' => ['serial' => $combined['mapping']['serial'], 'office' => $combined['mapping']['office']], 'mode' => 'update',
        ])->assertOk()->assertJson(['updated' => 2, 'not_found' => 1]);

        $this->assertSame(3, Personnel::count());
        $this->assertSame('ODCEIS', Personnel::where('serial', 'O-10001')->value('office'));
        $this->assertSame('', Personnel::where('serial', '800001')->value('office'));        // junk value skipped
        $this->assertSame('VILLAFUERTE', Personnel::where('serial', 'O-10001')->value('surname_key'));
        $this->assertSame('DEPLOYED', Personnel::where('serial', 'O-10001')->value('psr_status'));
    }

    public function test_old_xls_files_get_a_clear_message(): void
    {
        $this->actingAs($this->admin());
        $file = UploadedFile::fake()->create('roster.xls', 10);

        $this->postJson('/roster/upload', ['file' => $file])
            ->assertStatus(422)
            ->assertJson(['error' => 'This is the old .xls format. Open it in Excel and use Save As → Excel Workbook (.xlsx).']);
    }

    public function test_replace_is_refused_once_scans_exist(): void
    {
        $this->seedRoster();
        $gate = $this->scanner();
        $this->actingAs($gate)->postJson('/scan/record', ['qr_text' => 'CHR SANTOS, CEIS']);

        $this->actingAs($this->admin());
        $file = new UploadedFile($this->psrWorkbook(), 'psr.xlsx', null, null, true);
        $token = $this->postJson('/roster/upload', ['file' => $file])->json('token');

        $this->postJson('/roster/import', ['token' => $token, 'sheet' => 'Daily PSR', 'header_row' => 0, 'mapping' => ['surname' => 2], 'mode' => 'replace'])
            ->assertStatus(422);
        $this->assertSame(6, Personnel::count());
    }
}
