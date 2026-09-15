<?php

namespace App\Services;

use App\Models\Personnel;
use App\Models\Scan;
use Illuminate\Support\Collection;

/** Who is present / absent on a given day, shared by the dashboard and the Excel export. */
class AttendanceReport
{
    /** @var Collection<int, Personnel> */
    public Collection $people;

    /** @var Collection<int, Scan> */
    public Collection $scans;

    /**
     * personnel_id => ['in' => first time in, 'out' => last time out, 'status' => 'IN'|'OUT' now,
     *                  'moves' => counted scans, 'station' => where they first timed in]
     */
    public array $present = [];

    public function __construct(public string $day)
    {
        $this->people = Personnel::orderBy('squadron')->orderBy('surname')->orderBy('first_name')->get()->keyBy('id');
        $this->scans = Scan::where('day', $day)->orderBy('scanned_at')->orderBy('id')->get();

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

    public function roster(): Collection
    {
        return $this->people->where('is_walk_in', false);
    }

    public function isPresent(Personnel $p): bool
    {
        return isset($this->present[$p->id]);
    }

    /** Not scanned and the PSR says they should be here. */
    public function unaccounted(): Collection
    {
        return $this->roster()->filter(fn ($p) => ! $this->isPresent($p) && $p->isExpectedPresent());
    }

    /** Not scanned but the PSR already accounts for them (deployed, leave, schooling...). */
    public function excused(): Collection
    {
        return $this->roster()->filter(fn ($p) => ! $this->isPresent($p) && ! $p->isExpectedPresent());
    }

    public function walkInsPresent(): Collection
    {
        return $this->people->where('is_walk_in', true)->filter(fn ($p) => $this->isPresent($p));
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
}
