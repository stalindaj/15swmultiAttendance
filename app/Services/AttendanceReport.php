<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Personnel;
use App\Models\Scan;
use Illuminate\Support\Collection;

/** Who is present / absent at one event, shared by the event dashboard and the Excel export. */
class AttendanceReport
{
    /** Everyone on the attendee list plus everyone who scanned in. @var Collection<int, Personnel> */
    public Collection $people;

    /** personnel_id => true for everyone on the event's attendee list. */
    public array $onList = [];

    /** @var Collection<int, Scan> */
    public Collection $scans;

    /**
     * personnel_id => ['in' => first time in, 'out' => last time out, 'status' => 'IN'|'OUT' now,
     *                  'moves' => counted scans, 'station' => where they first timed in]
     */
    public array $present = [];

    public function __construct(public Event $event)
    {
        $this->scans = Scan::where('event_id', $event->id)->orderBy('scanned_at')->orderBy('id')->get();
        $attendeeIds = $event->attendees()->pluck('personnel.id')->all();
        $this->onList = array_fill_keys($attendeeIds, true);

        $ids = array_unique(array_merge($attendeeIds, $this->scans->pluck('personnel_id')->filter()->all()));
        $this->people = Personnel::whereIn('id', $ids)->orderBy('squadron')->orderBy('surname')->orderBy('first_name')->get()->keyBy('id');

        foreach ($this->scans as $s) {
            if ($s->status !== 'ok' || $s->personnel_id === null || ! $this->people->has($s->personnel_id)) {
                continue;
            }
            $row = &$this->present[$s->personnel_id];
            $row ??= ['in' => '', 'out' => '', 'status' => '', 'moves' => 0, 'station' => $s->station];
            if ($s->kind === 'IN' && $row['in'] === '') {
                $row['in'] = $s->scanned_at->format('H:i:s');
                $row['station'] = $s->station;
            } elseif ($s->kind === 'OUT') {
                $row['out'] = $s->scanned_at->format('H:i:s');
            }
            $row['status'] = $s->kind;
            $row['moves']++;
            unset($row);
        }
    }

    /** How many people are inside right now (their last counted scan was TIME IN). */
    public function insideNow(): int
    {
        return count(array_filter($this->present, fn ($r) => $r['status'] === 'IN'));
    }

    /** The event's attendee list. */
    public function roster(): Collection
    {
        return $this->people->filter(fn (Personnel $p) => isset($this->onList[$p->id]));
    }

    public function isPresent(Personnel $p): bool
    {
        return isset($this->present[$p->id]);
    }

    public function isOnList(Personnel $p): bool
    {
        return isset($this->onList[$p->id]);
    }

    /** On the list, not scanned, and the PSR says they should be here. */
    public function unaccounted(): Collection
    {
        return $this->roster()->filter(fn ($p) => ! $this->isPresent($p) && $p->isExpectedPresent());
    }

    /** On the list, not scanned, but the PSR already accounts for them (deployed, leave, schooling...). */
    public function excused(): Collection
    {
        return $this->roster()->filter(fn ($p) => ! $this->isPresent($p) && ! $p->isExpectedPresent());
    }

    /** Came, but not on the attendee list (including walk-ins not in the roster at all). */
    public function extras(): Collection
    {
        return $this->people->filter(fn ($p) => $this->isPresent($p) && ! $this->isOnList($p));
    }

    public function bySquadron(): array
    {
        $out = [];
        foreach ($this->roster() as $p) {
            $key = $p->squadron ?: '(no squadron)';
            $out[$key] ??= ['squadron' => $key, 'total' => 0, 'present' => 0, 'excused' => 0, 'unaccounted' => 0];
            $out[$key]['total']++;
            if ($this->isPresent($p)) {
                $out[$key]['present']++;
            } elseif ($p->isExpectedPresent()) {
                $out[$key]['unaccounted']++;
            } else {
                $out[$key]['excused']++;
            }
        }
        ksort($out, SORT_NATURAL);

        return array_values($out);
    }

    /** How many people timed in during each 10-minute slot: [['time' => '07:30', 'count' => 12], ...]. */
    public function arrivals(int $slotMinutes = 10): array
    {
        $counts = [];
        foreach ($this->present as $row) {
            if ($row['in'] === '') {
                continue;
            }
            [$h, $m] = array_map('intval', explode(':', $row['in']));
            $slot = intdiv($h * 60 + $m, $slotMinutes) * $slotMinutes;
            $counts[$slot] = ($counts[$slot] ?? 0) + 1;
        }
        if (! $counts) {
            return [];
        }
        $out = [];
        for ($s = min(array_keys($counts)); $s <= max(array_keys($counts)); $s += $slotMinutes) {
            $out[] = ['time' => sprintf('%02d:%02d', intdiv($s, 60), $s % 60), 'count' => $counts[$s] ?? 0];
        }

        return $out;
    }

    public function pendingScans(): Collection
    {
        return $this->scans->where('status', 'pending');
    }

    /**
     * @return array{expected: int, present: int, excused: int, unaccounted: int, extras: int, inside: int}
     */
    public function stats(): array
    {
        $roster = $this->roster();

        return [
            'expected' => $roster->count(),
            'present' => $roster->filter(fn ($p) => $this->isPresent($p))->count(),
            'excused' => $this->excused()->count(),
            'unaccounted' => $this->unaccounted()->count(),
            'extras' => $this->extras()->count(),
            'inside' => $this->insideNow(),
        ];
    }
}
