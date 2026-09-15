<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Personnel;
use App\Models\Scan;
use App\Services\AttendanceReport;
use App\Services\ExcelExport;
use App\Services\RosterImporter;
use App\Services\ScanService;
use App\Services\WriteLock;
use App\Support\PhoneStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Events (Safety Meeting, formation, ...): each has its own attendee list, scans and report. */
class EventController extends Controller
{
    public function __construct(private ScanService $scans) {}

    public function index(): InertiaResponse
    {
        $events = Event::withCount('attendees')->orderByDesc('event_date')->orderByDesc('id')->get();
        $present = Scan::whereIn('event_id', $events->modelKeys())->where('status', 'ok')->whereNotNull('personnel_id')
            ->selectRaw('event_id, count(distinct personnel_id) as n')->groupBy('event_id')->pluck('n', 'event_id');
        $waiting = Scan::whereIn('event_id', $events->modelKeys())->where('status', 'pending')
            ->selectRaw('event_id, count(distinct qr_text) as n')->groupBy('event_id')->pluck('n', 'event_id');

        return Inertia::render('Events/Index', [
            'events' => $events->map(fn (Event $e) => $e->card() + [
                'attendees' => $e->attendees_count,
                'present' => (int) ($present[$e->id] ?? 0),
                'waiting' => (int) ($waiting[$e->id] ?? 0),
            ]),
            'today' => now()->toDateString(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'event_date' => 'required|date',
        ]);
        $event = Event::create($data + ['is_open' => true, 'created_by' => $request->user()->id]);

        return redirect("/events/{$event->id}")->with('success', 'Event created. Now upload its attendee list.');
    }

    public function update(Request $request, Event $event): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:120',
            'event_date' => 'sometimes|required|date',
            'is_open' => 'sometimes|boolean',
        ]);
        $event->update($data);

        $message = array_key_exists('is_open', $data)
            ? ($event->is_open ? 'Event opened: phones can scan into it.' : 'Event closed: phones can no longer scan into it.')
            : 'Event saved.';

        return back()->with('success', $message);
    }

    public function destroy(Event $event): RedirectResponse
    {
        $label = $event->label();
        WriteLock::run(fn () => $event->delete());

        return redirect('/')->with('success', "Deleted “{$label}” and its scans.");
    }

    public function show(Event $event): InertiaResponse
    {
        // Built at most once per request, and only if a requested prop needs it (partial reloads).
        $cached = null;
        $report = function () use (&$cached, $event) {
            return $cached ??= new AttendanceReport($event);
        };

        return Inertia::render('Events/Show', [
            'event' => $event->card(),
            'fields' => RosterImporter::FIELDS,
            'stats' => fn () => $report()->stats(),
            'bySquadron' => fn () => $report()->bySquadron(),
            'arrivals' => fn () => $report()->arrivals(),
            'pending' => fn () => $report()->pendingScans()->groupBy('qr_text')->map(fn ($group, $qr) => [
                'qr_text' => $qr,
                'time' => $group->first()->scanned_at->format('H:i'),
                'count' => $group->count(),
                'stations' => $group->pluck('station')->unique()->implode(', '),
            ] + $this->scans->candidates($qr, $event->id))->values(),
            'recent' => fn () => Scan::with('personnel')->where('event_id', $event->id)->orderByDesc('scanned_at')->orderByDesc('id')
                ->limit(60)->get()->map(fn ($s) => $this->scans->result($s)),
            'autoMatch' => fn () => $this->scans->autoMatchEnabled(),
            'connect' => fn () => app(PhoneStatus::class)->connectInfo(),
            'phones' => fn () => app(PhoneStatus::class)->phones($event),
            'serverTime' => fn () => now()->format('H:i:s'),
            // Only loaded when the page asks for them (router.reload({ only: [...] })).
            'absent' => Inertia::optional(fn () => [
                'unaccounted' => $report()->unaccounted()->values()->map->card(),
                'excused' => $report()->excused()->sortBy('psr_status')->values()->map->card(),
                'extras' => $report()->extras()->values()->map->card(),
            ]),
            'attendees' => Inertia::optional(fn () => $event->attendees()->orderBy('squadron')->orderBy('surname')->get()->map->card()),
        ]);
    }

    public function markPresent(Request $request, Event $event, Personnel $person): RedirectResponse
    {
        $result = $this->scans->record([
            'event_id' => $event->id, 'client_id' => null, 'qr_text' => null, 'personnel_id' => $person->id, 'new_person' => null,
            'kind' => $request->input('kind') === 'OUT' ? 'OUT' : 'IN',
            'station' => 'Records PC', 'user_id' => $request->user()->id, 'method' => 'manual', 'defer' => false,
        ], now());

        return back()->with($result['status'] === 'ok' ? 'success' : 'error',
            ($result['status'] === 'ok' ? 'Marked present: ' : $result['message'].': ')."{$person->rank} {$person->full_name}");
    }

    public function clearScans(Event $event): RedirectResponse
    {
        $deleted = WriteLock::run(fn () => Scan::where('event_id', $event->id)->delete());

        return back()->with('success', "$deleted scans deleted from this event.");
    }

    public function export(Event $event, ExcelExport $excel): BinaryFileResponse
    {
        $book = $excel->build(new AttendanceReport($event));
        $name = trim(preg_replace('/[^A-Za-z0-9]+/', '_', $event->name), '_').'_'.$event->event_date->format('Y-m-d').'.xlsx';
        $tmp = tempnam(sys_get_temp_dir(), 'att');
        $excel->save($book, $tmp);

        return response()->download($tmp, $name)->deleteFileAfterSend();
    }
}
