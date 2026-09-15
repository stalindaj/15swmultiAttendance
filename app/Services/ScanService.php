<?php

namespace App\Services;

use App\Models\Personnel;
use App\Models\QrLink;
use App\Models\Scan;
use App\Models\Setting;
use App\Support\NameMatcher;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class ScanService
{
    /**
     * Everyone whose family name appears in the QR text, with what else on the ID agrees:
     * rank, office/squadron, first name, and whether they are on the event's attendee list.
     * People on the list come first, then office matches.
     */
    public function candidates(string $qrText, ?int $eventId = null): array
    {
        $parsed = NameMatcher::parseQr($qrText);
        $unitKey = NameMatcher::squash($parsed['unit']);
        $rankKey = NameMatcher::rankKey($parsed['rank']);
        $words = NameMatcher::tokens($qrText);
        $rankWords = $parsed['rank'] === '' ? [] : explode(' ', $parsed['rank']);

        $people = Personnel::whereIn('surname_key', NameMatcher::surnameKeysIn($qrText))->get();
        if ($people->isEmpty() && $parsed['surname'] !== '') {
            // Roster rows with only a full-name column: look for the family name inside it.
            $people = Personnel::where('name_key', 'like', '%'.NameMatcher::norm($parsed['surname']).'%')->limit(30)->get();
        }
        $onList = $eventId === null ? [] : array_flip(
            DB::table('event_attendees')->where('event_id', $eventId)->whereIn('personnel_id', $people->modelKeys())->pluck('personnel_id')->all()
        );

        $cards = $people->map(function (Personnel $p) use ($unitKey, $rankKey, $words, $rankWords, $onList) {
            $firstWords = array_values(array_filter(explode(' ', NameMatcher::norm($p->first_name))));
            $officeMatch = NameMatcher::unitMatches($unitKey, $p->office_key, $p->squadron_key)
                || ($p->squadron_key !== '' && in_array($p->squadron_key, $words, true))
                || ($p->office_key !== '' && in_array($p->office_key, $words, true));
            $firstMatch = $firstWords && ! array_diff($firstWords, $words);

            // Words on the ID this person doesn't explain (e.g. another first name) argue against them.
            $explained = array_merge($rankWords, explode(' ', NameMatcher::norm($p->surname)), $firstWords,
                [$p->squadron_key, $p->office_key, $unitKey], explode(' ', NameMatcher::norm($p->middle)));
            $leftover = array_filter(array_diff($words, $explained), fn ($w) => strlen($w) > 1 && ! in_array($w, ['JR', 'SR', 'II', 'III'], true));

            return $p->card() + [
                'on_list' => isset($onList[$p->id]),
                'office_match' => $officeMatch,
                'rank_match' => $rankKey !== '' && NameMatcher::rankKey($p->rank) === $rankKey,
                'first_match' => $firstMatch,
                // The ID names an office this person isn't in (only when the roster knows their office).
                'office_conflict' => $unitKey !== '' && $p->office_key !== '' && ! $officeMatch,
                'name_conflict' => ! $firstMatch && $leftover !== [],
            ];
        })->sortBy([
            ['on_list', 'desc'],
            ['office_match', 'desc'],
            ['rank_match', 'desc'],
            ['first_match', 'desc'],
            ['name', 'asc'],
        ])->values();

        return [
            'parsed' => $parsed,
            'office_matches' => $cards->where('office_match', true)->values()->all(),
            'others' => $cards->where('office_match', false)->values()->all(),
        ];
    }

    /**
     * The one person the ID clearly belongs to, or null when a human should decide.
     * The event's attendee list is tried first; only if it has no clear answer is the whole roster used.
     */
    public function pickAutomatically(array $candidates): ?array
    {
        $all = array_merge($candidates['office_matches'], $candidates['others']);

        return $this->pickFrom(array_filter($all, fn ($p) => $p['on_list'] ?? false)) ?? $this->pickFrom($all);
    }

    /**
     *   rank + family name unique            -> that person       ("2LT RIVERA PAF, ODP")
     *   several with that rank + family name -> the office decides, then the first name
     *   no rank on the ID                    -> the full name must match exactly one person
     * Never picks someone whose office or first name contradicts the ID.
     */
    private function pickFrom(array $people): ?array
    {
        $fits = fn ($p) => ! $p['office_conflict'] && ! $p['name_conflict'];
        $only = fn (array $list) => count($list) === 1 && $fits(reset($list)) ? reset($list) : null;

        $sameRank = array_filter($people, fn ($p) => $p['rank_match']);
        if (count($sameRank) === 1) {
            return $only($sameRank);
        }
        if (count($sameRank) > 1) {
            return $only(array_filter($sameRank, fn ($p) => $p['office_match']))
                ?? $only(array_filter($sameRank, fn ($p) => $p['first_match']));
        }

        return $only(array_filter($people, fn ($p) => $p['first_match']));
    }

    public function autoMatchEnabled(): bool
    {
        return Setting::get('auto_match', '1') === '1';
    }

    /** Try the automatic match on every ID still waiting to be confirmed. Returns how many were matched. */
    public function autoMatchPending(?int $eventId = null): int
    {
        $waiting = Scan::where('status', 'pending')->whereNull('personnel_id')
            ->when($eventId, fn ($q) => $q->where('event_id', $eventId))
            ->select('qr_text', 'event_id')->distinct()->get();

        $matched = [];
        foreach ($waiting as $w) {
            if (isset($matched[$w->qr_text])) {
                continue;
            }
            if ($person = $this->pickAutomatically($this->candidates($w->qr_text, $w->event_id))) {
                WriteLock::run(fn () => DB::transaction(fn () => $this->link($w->qr_text, $person['id'], 'auto')));
                $matched[$w->qr_text] = true;
            }
        }

        return count($matched);
    }

    /**
     * Record one scan for an event. Returns status:
     *   ok / duplicate  - recorded
     *   confirm         - QR not linked yet; nothing recorded, the station must confirm who it is
     *   pending         - recorded without a person ('defer': phones only scan); the records PC confirms it
     */
    public function record(array $in, CarbonInterface $at): array
    {
        return WriteLock::run(fn () => DB::transaction(function () use ($in, $at) {
            if (! empty($in['client_id']) && ($existing = Scan::where('client_id', $in['client_id'])->first())) {
                return $this->result($existing);   // a phone re-sent a scan it had queued
            }

            $eventId = (int) $in['event_id'];
            $qrText = $in['qr_text'] ?? null;
            $pid = $in['personnel_id'] ?? null;

            if (! empty($in['new_person'])) {
                $pid = $this->createWalkIn($in['new_person'], $qrText)->id;
            }

            if ($qrText !== null) {
                if ($pid !== null) {
                    $this->link($qrText, $pid);           // station confirmed who this QR belongs to
                } elseif ($link = QrLink::find($qrText)) {
                    $pid = $link->personnel_id;
                } elseif ($this->autoMatchEnabled() && ($person = $this->pickAutomatically($this->candidates($qrText, $eventId)))) {
                    QrLink::create(['qr_text' => $qrText, 'personnel_id' => $person['id'], 'source' => 'auto']);
                    $pid = $person['id'];
                } elseif (empty($in['defer'])) {
                    return ['status' => 'confirm', 'qr_text' => $qrText, 'kind' => $in['kind']];
                }
            }

            $scan = Scan::create([
                'event_id' => $eventId,
                'client_id' => $in['client_id'] ?: null,
                'qr_text' => $qrText,
                'personnel_id' => $pid,
                'user_id' => $in['user_id'] ?? null,
                'kind' => $in['kind'],
                'station' => $in['station'],
                'method' => $in['method'],
                'day' => $at->toDateString(),
                'scanned_at' => $at,
                'status' => $pid !== null ? 'ok' : 'pending',
            ]);
            $this->recompute($scan);

            return $this->result($scan->fresh());
        }));
    }

    public function createWalkIn(array $data, ?string $qrText): Personnel
    {
        $parsed = $qrText ? NameMatcher::parseQr($qrText) : ['rank' => '', 'surname' => '', 'unit' => ''];

        return Personnel::create([
            'rank' => $data['rank'] ?? $parsed['rank'],
            'surname' => ($data['surname'] ?? '') ?: $parsed['surname'],
            'first_name' => $data['first_name'] ?? '',
            'office' => ($data['office'] ?? '') ?: $parsed['unit'],
            'squadron' => $data['squadron'] ?? '',
            'is_walk_in' => true,
        ]);
    }

    /** Point a QR text at a person and move every scan of that QR (in every event) to them. */
    public function link(string $qrText, int $pid, string $source = 'confirmed'): void
    {
        QrLink::updateOrCreate(['qr_text' => $qrText], ['personnel_id' => $pid, 'source' => $source]);

        $affected = Scan::where('qr_text', $qrText)->get(['personnel_id', 'event_id']);
        Scan::where('qr_text', $qrText)->update(['personnel_id' => $pid]);
        foreach ($affected as $a) {
            if ($a->personnel_id !== null && $a->personnel_id !== $pid) {
                $this->recomputePerson($a->personnel_id, $a->event_id);
            }
        }
        foreach ($affected->pluck('event_id')->unique() as $eventId) {
            $this->recomputePerson($pid, $eventId);
        }
    }

    public function assign(Scan $scan, int $pid): void
    {
        WriteLock::run(fn () => DB::transaction(function () use ($scan, $pid) {
            if ($scan->qr_text) {
                $this->link($scan->qr_text, $pid);

                return;
            }
            $old = $scan->personnel_id;
            $scan->update(['personnel_id' => $pid]);
            if ($old !== null) {
                $this->recomputePerson($old, $scan->event_id);
            }
            $this->recomputePerson($pid, $scan->event_id);
        }));
    }

    public function void(Scan $scan): void
    {
        WriteLock::run(fn () => DB::transaction(function () use ($scan) {
            $scan->update(['status' => 'void', 'note' => 'Cancelled by admin']);
            $this->recompute($scan);
        }));
    }

    public function recompute(Scan $scan): void
    {
        if ($scan->personnel_id !== null) {
            $this->recomputePerson($scan->personnel_id, $scan->event_id);
        } elseif ($scan->qr_text !== null) {
            $this->recomputePending($scan->qr_text, $scan->event_id);
        }
    }

    /**
     * Within one event each person is IN or OUT. A scan that changes the status counts
     * (IN → OUT → IN is fine); a scan that repeats the current status is a duplicate.
     */
    public function recomputePerson(int $pid, ?int $eventId): void
    {
        $rows = Scan::where('personnel_id', $pid)->where('event_id', $eventId)->where('status', '!=', 'void')
            ->orderBy('scanned_at')->orderBy('id')->get();

        $current = null;   // kind of the last scan that counted
        $since = null;     // when it happened
        foreach ($rows as $s) {
            if ($s->kind === $current) {
                [$status, $note] = ['duplicate', ($s->kind === 'IN' ? 'Already timed in at ' : 'Already timed out at ').$since->format('H:i')];
            } else {
                [$status, $note] = ['ok', ''];
                [$current, $since] = [$s->kind, $s->scanned_at];
            }
            if ($s->status !== $status || $s->note !== $note) {
                $s->update(['status' => $status, 'note' => $note]);
            }
        }
    }

    public function recomputePending(string $qrText, ?int $eventId): void
    {
        $current = null;   // same IN/OUT rule as recomputePerson
        $rows = Scan::where('qr_text', $qrText)->whereNull('personnel_id')->where('event_id', $eventId)
            ->where('status', '!=', 'void')->orderBy('scanned_at')->orderBy('id')->get();
        foreach ($rows as $s) {
            $status = $s->kind === $current ? 'duplicate' : 'pending';
            if ($status === 'pending') {
                $current = $s->kind;
            }
            if ($s->status !== $status) {
                $s->update(['status' => $status, 'note' => '']);
            }
        }
    }

    public function result(Scan $s): array
    {
        $s->loadMissing('personnel');
        $messages = [
            'ok' => 'Recorded',
            'duplicate' => $s->note ?: 'Already recorded',
            'pending' => 'Sent to the records PC for checking.',
            'void' => 'This scan was cancelled',
        ];

        return [
            'id' => $s->id,
            'event_id' => $s->event_id,
            'status' => $s->status,
            'kind' => $s->kind,
            'time' => $s->scanned_at->format('H:i'),
            'scanned_at' => $s->scanned_at->format('Y-m-d H:i:s'),
            'qr_text' => $s->qr_text,
            'station' => $s->station,
            'method' => $s->method,
            'person' => $s->personnel?->card(),
            // Present but not on the event's attendee list: recorded as an extra.
            'on_list' => $s->personnel_id === null || DB::table('event_attendees')
                ->where('event_id', $s->event_id)->where('personnel_id', $s->personnel_id)->exists(),
            // Matched by rank + family name without anyone confirming: the records PC may want to glance at it.
            'auto' => $s->qr_text !== null && $s->personnel_id !== null
                && QrLink::where('qr_text', $s->qr_text)->where('personnel_id', $s->personnel_id)->where('source', 'auto')->exists(),
            'message' => $messages[$s->status] ?? '',
        ];
    }
}
