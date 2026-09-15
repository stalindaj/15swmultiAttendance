<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Personnel;
use App\Models\QrLink;
use App\Models\Scan;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Phones only scan; the records PC confirms new IDs (family name -> office -> confirm). */
class ScanFlowTest extends TestCase
{
    use RefreshDatabase;

    /** Created on the first scan, listing everyone on the roster at that moment. */
    private ?Event $event = null;

    private function scan(string $qr, array $extra = [])
    {
        $this->event ??= $this->makeEvent();

        return $this->postJson('/scan/record', [
            'event_id' => $this->event->id,
            'qr_text' => $qr,
            'client_id' => $extra['client_id'] ?? uniqid('c', true),
        ] + $extra);
    }

    private function pendingOnDashboard($admin): array
    {
        return $this->actingAs($admin)->get("/events/{$this->event->id}")->inertiaProps('pending');
    }

    public function test_unknown_id_is_recorded_as_pending_and_waits_for_the_records_pc(): void
    {
        $this->seedRoster();
        Setting::put('auto_match', '0');
        $gate = $this->scanner();

        $this->actingAs($gate)->scan('MAJ VILLAFUERTE, CEIS')
            ->assertOk()
            ->assertJson(['status' => 'pending', 'kind' => 'IN', 'person' => null, 'station' => 'Gate 1']);

        $this->assertDatabaseHas('scans', ['qr_text' => 'MAJ VILLAFUERTE, CEIS', 'status' => 'pending', 'user_id' => $gate->id]);
    }

    public function test_dashboard_lists_family_name_candidates_with_office_matches_first(): void
    {
        $r = $this->seedRoster();
        Setting::put('auto_match', '0');
        $this->actingAs($this->scanner())->scan('TSG DELA CRUZ, ODI');

        $pending = $this->pendingOnDashboard($this->admin());

        $this->assertCount(1, $pending);
        $this->assertSame([$r['dc_odi']->id], array_column($pending[0]['office_matches'], 'id'));
        $this->assertEqualsCanonicalizing([$r['dc_other']->id, $r['dc_deployed']->id], array_column($pending[0]['others'], 'id'));
        $this->assertTrue($pending[0]['office_matches'][0]['rank_match']);
    }

    public function test_rank_mismatch_is_reported_for_the_office_match(): void
    {
        $this->seedRoster();
        $this->actingAs($this->scanner())->scan('SSG GONZALES, ODP');

        $match = $this->pendingOnDashboard($this->admin())[0]['office_matches'][0];

        $this->assertSame('AM', $match['rank']);
        $this->assertFalse($match['rank_match']);
    }

    public function test_confirming_links_the_qr_and_keeps_the_original_scan_time(): void
    {
        $r = $this->seedRoster();
        Setting::put('auto_match', '0');
        $gate = $this->scanner();
        Carbon::setTestNow('2026-09-17 07:30:00');
        $this->actingAs($gate)->scan('MAJ VILLAFUERTE, CEIS');

        Carbon::setTestNow('2026-09-17 07:45:00');
        $this->actingAs($this->admin())->post('/confirm', ['qr_text' => 'MAJ VILLAFUERTE, CEIS', 'personnel_id' => $r['villafuerte']->id])
            ->assertRedirect();

        $scan = Scan::sole();
        $this->assertSame('ok', $scan->status);
        $this->assertSame($r['villafuerte']->id, $scan->personnel_id);
        $this->assertSame('07:30:00', $scan->scanned_at->format('H:i:s'));
        $this->assertSame($r['villafuerte']->id, QrLink::find('MAJ VILLAFUERTE, CEIS')->personnel_id);
    }

    public function test_status_flips_in_and_out_and_only_repeats_are_duplicates(): void
    {
        $r = $this->seedRoster();
        QrLink::create(['qr_text' => 'MAJ VILLAFUERTE, CEIS', 'personnel_id' => $r['villafuerte']->id]);
        $gate = $this->actingAs($this->scanner());
        $at = function (string $time, string $kind = 'IN') use ($gate) {
            Carbon::setTestNow("2026-09-17 $time");

            return $gate->scan('MAJ VILLAFUERTE, CEIS', ['kind' => $kind]);
        };

        $at('07:30:00')->assertJson(['status' => 'ok', 'kind' => 'IN', 'person' => ['name' => 'RAMON O VILLAFUERTE']]);
        $at('07:31:00')->assertJson(['status' => 'duplicate', 'message' => 'Already timed in at 07:30']);
        $at('12:00:00', 'OUT')->assertJson(['status' => 'ok', 'kind' => 'OUT']);
        $at('12:05:00', 'OUT')->assertJson(['status' => 'duplicate', 'message' => 'Already timed out at 12:00']);
        $at('13:00:00')->assertJson(['status' => 'ok', 'kind' => 'IN']);        // back in after going out: not a duplicate
        $at('17:00:00', 'OUT')->assertJson(['status' => 'ok']);

        $this->actingAs($this->admin())->get("/events/{$this->event->id}")->assertInertia(fn (Assert $page) => $page
            ->where('stats.present', 1)
            ->where('stats.inside', 0));                                        // last scan was TIME OUT
    }

    public function test_rank_and_family_name_match_automatically_and_branch_is_ignored(): void
    {
        $ana = Personnel::create(['rank' => '2LT', 'surname' => 'RIVERA', 'first_name' => 'ANA', 'middle' => 'C', 'squadron' => '18AS', 'psr_status' => 'DS']);
        Personnel::create(['rank' => 'MSg', 'surname' => 'Rivera Jr', 'first_name' => 'Pedro', 'middle' => 'G', 'squadron' => 'HAS', 'office' => 'HAS']);

        $this->actingAs($this->scanner())->scan('2LT RIVERA PAF, ODP')
            ->assertJson(['status' => 'ok', 'auto' => true, 'person' => ['id' => $ana->id, 'name' => 'ANA C RIVERA']]);
        $this->assertSame('auto', QrLink::find('2LT RIVERA PAF, ODP')->source);
    }

    public function test_full_name_ids_match_on_first_name(): void
    {
        $r = $this->seedRoster();
        $mariaLuisa = Personnel::create(['rank' => 'Sgt', 'surname' => 'Soriano', 'first_name' => 'Maria Luisa', 'middle' => 'E', 'squadron' => 'HAS', 'office' => 'ODP']);
        Personnel::create(['rank' => 'Sgt', 'surname' => 'Soriano', 'first_name' => 'Michael', 'middle' => 'H', 'squadron' => 'HAS', 'office' => 'PAO']);

        $this->actingAs($this->scanner())->scan('SGT MARIA LUISA E SORIANO HAS')
            ->assertJson(['status' => 'ok', 'auto' => true, 'person' => ['id' => $mariaLuisa->id]]);
    }

    public function test_same_rank_and_family_name_is_settled_by_office_or_waits(): void
    {
        $r = $this->seedRoster();   // TSg Paolo Dela Cruz (ODI) and TSg Mark Anthony Dela Cruz (no office)
        $gate = $this->actingAs($this->scanner());

        $gate->scan('TSG DELA CRUZ PAF, ODI')->assertJson(['status' => 'ok', 'auto' => true, 'person' => ['id' => $r['dc_odi']->id]]);
        $gate->scan('TSG DELA CRUZ PAF, OWA')->assertJson(['status' => 'pending']);   // neither is in OWA: a human decides
    }

    public function test_no_automatic_match_when_the_rank_does_not_fit_or_matching_is_off(): void
    {
        $this->seedRoster();
        $gate = $this->actingAs($this->scanner());

        $gate->scan('SSG GONZALES PAF, ODP')->assertJson(['status' => 'pending']);   // only an AM Gonzales in ODP

        Setting::put('auto_match', '0');
        $gate->scan('MAJ VILLAFUERTE PAF, CEIS')->assertJson(['status' => 'pending']);
    }

    public function test_turning_matching_on_matches_ids_already_waiting(): void
    {
        $r = $this->seedRoster();
        Setting::put('auto_match', '0');
        $this->actingAs($this->scanner())->scan('MAJ VILLAFUERTE PAF, CEIS');

        $this->actingAs($this->admin())->post('/settings', ['auto_match' => true])
            ->assertSessionHas('success', 'Automatic matching on. 1 waiting ID was matched.');

        $this->assertSame($r['villafuerte']->id, Scan::sole()->personnel_id);
        $this->assertSame('ok', Scan::sole()->status);
    }

    public function test_phone_never_receives_serial_number_or_psr_status(): void
    {
        $r = $this->seedRoster();
        QrLink::create(['qr_text' => 'MAJ VILLAFUERTE, CEIS', 'personnel_id' => $r['villafuerte']->id]);

        $person = $this->actingAs($this->scanner())->scan('MAJ VILLAFUERTE, CEIS')->json('person');

        $this->assertSame('MAJ', $person['rank']);
        $this->assertArrayNotHasKey('serial', $person);
        $this->assertArrayNotHasKey('psr_status', $person);
    }

    public function test_resent_offline_scan_is_not_recorded_twice(): void
    {
        $this->seedRoster();
        $gate = $this->actingAs($this->scanner());

        $first = $gate->scan('TSG DELA CRUZ, ODI', ['client_id' => 'phone-1-abc'])->json('id');
        $again = $gate->scan('TSG DELA CRUZ, ODI', ['client_id' => 'phone-1-abc'])->json('id');

        $this->assertSame($first, $again);
        $this->assertSame(1, Scan::count());
    }

    public function test_offline_scan_keeps_the_time_it_was_made_even_if_the_phone_clock_is_wrong(): void
    {
        $this->seedRoster();
        Carbon::setTestNow('2026-09-17 08:00:00');
        $phoneNow = Carbon::parse('2026-09-17 09:00:00')->getTimestampMs();   // phone clock is an hour fast

        $res = $this->actingAs($this->scanner())->scan('CHR SANTOS, CEIS', [
            'client_ts' => $phoneNow - 10 * 60 * 1000,   // scanned 10 minutes ago, sent now
            'client_now' => $phoneNow,
        ]);

        $this->assertSame('07:50', $res->json('time'));
    }

    public function test_person_not_on_the_roster_can_be_added_as_a_walk_in(): void
    {
        $this->seedRoster();
        $this->actingAs($this->scanner())->scan('CHR SANTOS, CEIS');

        $this->actingAs($this->admin())
            ->post('/confirm', ['qr_text' => 'CHR SANTOS, CEIS', 'new_person' => ['first_name' => 'Juan Miguel']]);

        $person = Scan::sole()->personnel;
        $this->assertTrue($person->is_walk_in);
        $this->assertSame(['CHR', 'SANTOS', 'CEIS', 'Juan Miguel SANTOS'], [$person->rank, $person->surname, $person->office, $person->full_name]);
    }

    public function test_records_pc_can_void_a_scan_and_move_it_to_another_person(): void
    {
        $r = $this->seedRoster();
        QrLink::create(['qr_text' => 'TSG DELA CRUZ, ODI', 'personnel_id' => $r['dc_other']->id]);   // wrong person
        $this->actingAs($this->scanner())->scan('TSG DELA CRUZ, ODI');
        $scan = Scan::sole();
        $admin = $this->admin();

        $this->actingAs($admin)->post("/scans/{$scan->id}/assign", ['personnel_id' => $r['dc_odi']->id]);
        $this->assertSame($r['dc_odi']->id, $scan->fresh()->personnel_id);
        $this->assertSame($r['dc_odi']->id, QrLink::find('TSG DELA CRUZ, ODI')->personnel_id);

        $this->actingAs($admin)->post("/scans/{$scan->id}/void");
        $this->assertSame('void', $scan->fresh()->status);
    }

    public function test_mark_present_and_absent_lists_use_the_psr_status(): void
    {
        $r = $this->seedRoster();
        $event = $this->makeEvent();
        $admin = $this->admin();

        $this->actingAs($admin)->post("/events/{$event->id}/present/{$r['ocana']->id}")->assertSessionHas('success');

        $this->actingAs($admin)->get("/events/{$event->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->component('Events/Show')
                ->where('stats.expected', 6)
                ->where('stats.present', 1)
                ->where('stats.excused', 1)          // A2C Dela Cruz is DEPLOYED per PSR
                ->where('stats.unaccounted', 4)
                ->missing('absent')                  // optional prop: only sent when asked for
                ->reloadOnly('absent', fn (Assert $reload) => $reload
                    ->has('absent.unaccounted', 4)
                    ->has('absent.excused', 1)
                    ->has('absent.extras', 0)));
    }
}
