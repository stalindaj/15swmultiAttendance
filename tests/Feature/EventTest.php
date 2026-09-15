<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Personnel;
use App\Models\QrLink;
use App\Models\Scan;
use App\Support\DatabaseStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/** Multi-event attendance: each event has its own attendee list (from a PSR upload), scans and report. */
class EventTest extends TestCase
{
    use RefreshDatabase;

    /** A PSR-format attendee list. */
    private function listFile(array $people): UploadedFile
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->setTitle('Daily PSR')->fromArray([
            ['NR', 'RANK', 'LASTNAME', 'FIRST NAME', 'MI', 'SN', 'UNIT', 'SQUADRON', 'STATUS'],
            ...array_map(fn ($p, $i) => [$i + 1, ...$p, '15SW', $p[5] ?? 'HAS', 'ON DUTY'], $people, array_keys($people)),
        ]);
        $path = tempnam(sys_get_temp_dir(), 'list').'.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, 'safety-meeting.xlsx', null, null, true);
    }

    private function uploadList(Event $event, array $people, string $mode = 'replace'): array
    {
        $upload = $this->postJson("/events/{$event->id}/attendees/upload", ['file' => $this->listFile($people)])->assertOk()->json();
        $preview = $this->getJson("/events/{$event->id}/attendees/preview/{$upload['token']}?sheet=Daily%20PSR")->assertOk()->json();

        return $this->postJson("/events/{$event->id}/attendees/import", [
            'token' => $upload['token'], 'sheet' => 'Daily PSR', 'header_row' => $preview['header_row'],
            'mapping' => $preview['mapping'], 'mode' => $mode,
        ])->assertOk()->json();
    }

    private function scanInto(Event $event, string $qr, array $extra = [])
    {
        return $this->postJson('/scan/record', ['event_id' => $event->id, 'qr_text' => $qr, 'client_id' => uniqid('c', true)] + $extra);
    }

    public function test_records_account_creates_an_event_and_uploads_its_list_from_a_psr_file(): void
    {
        $r = $this->seedRoster();
        $this->actingAs($this->admin());

        $this->post('/events', ['name' => 'SAFETY MEETING', 'event_date' => '2026-09-17'])->assertRedirect('/events/1');
        $event = Event::sole();

        $result = $this->uploadList($event, [
            ['MAJ', 'VILLAFUERTE', 'RAMON', 'O', 'O-10001'],          // already in the roster (same SN)
            ['SSg', 'Morales', 'Ben', 'T', '910001', '20AS'],         // new to the roster
        ]);

        $this->assertSame(['ok' => true, 'on_list' => 2, 'new_people' => 1, 'skipped' => 0, 'total' => 2], $result);
        $this->assertSame(7, Personnel::count());                   // no duplicate Villafuerte
        $this->assertEqualsCanonicalizing([$r['villafuerte']->id, Personnel::where('serial', '910001')->value('id')],
            $event->attendees()->pluck('personnel.id')->all());

        $this->get('/')->assertInertia(fn (Assert $page) => $page->component('Events/Index')
            ->where('events.0.name', 'SAFETY MEETING')->where('events.0.attendees', 2));
    }

    public function test_list_upload_can_add_to_or_replace_the_list(): void
    {
        $this->seedRoster();
        $event = Event::factory()->create();
        $this->actingAs($this->admin());

        $this->uploadList($event, [['MAJ', 'VILLAFUERTE', 'RAMON', 'O', 'O-10001']]);
        $this->assertSame(2, $this->uploadList($event, [['AM', 'Gonzales', 'Kevin', 'B', '900004']], 'add')['total']);
        $this->assertSame(1, $this->uploadList($event, [['TSg', 'Dela Cruz', 'Paolo', 'C', '800001']], 'replace')['total']);
    }

    public function test_someone_not_on_the_list_is_recorded_as_an_extra(): void
    {
        $r = $this->seedRoster();
        $event = Event::factory()->create();
        $event->attendees()->attach($r['villafuerte']);
        QrLink::create(['qr_text' => 'AM GONZALES PAF, ODP', 'personnel_id' => $r['gonzales_am']->id]);
        $this->actingAs($this->scanner());

        $this->scanInto($event, 'MAJ VILLAFUERTE PAF, CEIS')->assertJson(['status' => 'ok', 'on_list' => true]);
        $this->scanInto($event, 'AM GONZALES PAF, ODP')->assertJson(['status' => 'ok', 'on_list' => false]);

        $this->actingAs($this->admin())->get("/events/{$event->id}")->assertInertia(fn (Assert $page) => $page
            ->where('stats.expected', 1)
            ->where('stats.present', 1)
            ->where('stats.extras', 1)
            ->reloadOnly('absent', fn (Assert $reload) => $reload->where('absent.extras.0.name', 'Kevin B Gonzales')));
    }

    public function test_in_and_out_status_is_kept_per_event(): void
    {
        $r = $this->seedRoster();
        $meeting = $this->makeEvent(['name' => 'Safety Meeting']);
        $formation = $this->makeEvent(['name' => 'Formation']);
        QrLink::create(['qr_text' => 'MAJ VILLAFUERTE, CEIS', 'personnel_id' => $r['villafuerte']->id]);
        $this->actingAs($this->scanner());

        $this->scanInto($meeting, 'MAJ VILLAFUERTE, CEIS')->assertJson(['status' => 'ok']);
        $this->scanInto($formation, 'MAJ VILLAFUERTE, CEIS')->assertJson(['status' => 'ok']);     // other event: not a duplicate
        $this->scanInto($meeting, 'MAJ VILLAFUERTE, CEIS')->assertJson(['status' => 'duplicate']);
    }

    public function test_matching_prefers_the_people_on_the_event_list(): void
    {
        $r = $this->seedRoster();   // two TSg Dela Cruz: Paolo (ODI) and Mark Anthony (no office)
        $event = Event::factory()->create();
        $event->attendees()->attach($r['dc_other']);
        $this->actingAs($this->scanner());

        // Ambiguous in the whole roster, but only Mark Anthony is expected at this event.
        $this->scanInto($event, 'TSG DELA CRUZ PAF')->assertJson(['status' => 'ok', 'auto' => true, 'on_list' => true, 'person' => ['id' => $r['dc_other']->id]]);
    }

    public function test_closed_events_take_no_new_scans_but_keep_scans_saved_offline(): void
    {
        $this->seedRoster();
        $event = $this->makeEvent(['is_open' => false]);
        $this->actingAs($this->scanner());
        Carbon::setTestNow('2026-09-17 09:00:00');
        $now = now()->getTimestampMs();

        $this->scanInto($event, 'MAJ VILLAFUERTE, CEIS', ['client_ts' => $now, 'client_now' => $now])
            ->assertStatus(422)->assertJson(['message' => '“'.$event->name.'” is closed. Choose another event.']);
        $this->scanInto($event, 'MAJ VILLAFUERTE, CEIS', ['client_ts' => $now - 15 * 60 * 1000, 'client_now' => $now])
            ->assertOk()->assertJson(['time' => '08:45']);
    }

    public function test_phones_see_only_open_events(): void
    {
        $open = Event::factory()->create(['name' => 'Safety Meeting']);
        Event::factory()->closed()->create(['name' => 'Old Formation']);
        $this->actingAs($this->scanner());

        $this->get('/scan')->assertInertia(fn (Assert $page) => $page->component('Scan')
            ->has('events', 1)->where('events.0.id', $open->id));
        $this->getJson('/scan/ping')->assertJsonCount(1, 'events')->assertJsonPath('events.0.name', 'Safety Meeting');
    }

    public function test_closing_reopening_renaming_and_deleting_an_event(): void
    {
        $event = $this->makeEvent(['name' => 'Safety Meeting']);
        $this->actingAs($this->admin());

        $this->patch("/events/{$event->id}", ['is_open' => false])->assertSessionHas('success', 'Event closed: phones can no longer scan into it.');
        $this->patch("/events/{$event->id}", ['name' => 'SAFETY MEETING (Q3)'])->assertSessionHas('success');
        $this->assertSame(['SAFETY MEETING (Q3)', false], [$event->fresh()->name, $event->fresh()->is_open]);

        Scan::create(['event_id' => $event->id, 'kind' => 'IN', 'day' => '2026-09-17', 'scanned_at' => now(), 'status' => 'pending', 'qr_text' => 'X Y, Z']);
        $this->delete("/events/{$event->id}")->assertRedirect('/');
        $this->assertSame([0, 0], [Event::count(), Scan::count()]);
    }

    public function test_removing_someone_from_the_list(): void
    {
        $r = $this->seedRoster();
        $event = $this->makeEvent();
        $this->actingAs($this->admin());

        $this->delete("/events/{$event->id}/attendees/{$r['ocana']->id}")->assertSessionHas('success');

        $this->assertSame(5, $event->attendees()->count());
        $this->assertNotNull(Personnel::find($r['ocana']->id));          // still in the roster
    }

    public function test_replacing_the_whole_roster_is_refused_once_events_have_lists(): void
    {
        $this->seedRoster();
        $this->makeEvent();
        $this->actingAs($this->admin());
        $token = $this->postJson('/roster/upload', ['file' => $this->listFile([['MAJ', 'NEW', 'ONE', 'A', 'O-1']])])->json('token');

        $this->postJson('/roster/import', ['token' => $token, 'sheet' => 'Daily PSR', 'header_row' => 0, 'mapping' => ['surname' => 2, 'serial' => 5], 'mode' => 'replace'])
            ->assertStatus(422)->assertJson(['error' => 'Events already use these people. Use “Add / update” instead of replacing everyone.']);
    }

    public function test_records_pages_ask_for_a_database_update_instead_of_failing(): void
    {
        $this->partialMock(DatabaseStatus::class, fn ($mock) => $mock->shouldReceive('pendingMigrations')->andReturn(['2026_12_01_000000_add_something']));

        $this->actingAs($this->admin())->get('/')->assertInertia(fn (Assert $page) => $page
            ->component('System/UpdateDatabase')
            ->where('pending', ['2026_12_01_000000_add_something']));
    }
}
