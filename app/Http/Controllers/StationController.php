<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Personnel;
use App\Services\ScanService;
use App\Support\NameMatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * The phone scanner. Phones only scan and send the QR text; the records PC does the checking
 * (family name -> office -> confirm) on the dashboard.
 */
class StationController extends Controller
{
    public function __construct(private ScanService $scans) {}

    public function page(): InertiaResponse
    {
        return Inertia::render('Scan', ['events' => $this->openEvents()]);
    }

    /** Keep-alive: tells the phone it is online, which events are open, and refreshes its cookies. */
    public function ping(Request $request): JsonResponse
    {
        Cache::put("last_seen:{$request->user()->id}", now()->format('H:i:s'), now()->addHours(12));

        return response()->json(['ok' => true, 'server_time' => now()->format('Y-m-d H:i:s'), 'events' => $this->openEvents()]);
    }

    public function record(Request $request): JsonResponse
    {
        $data = $request->validate([
            'event_id' => 'required|integer|exists:events,id',
            'client_id' => 'nullable|string|max:64',
            'qr_text' => 'required|string|max:191',
            'kind' => 'nullable|string',
            'method' => 'nullable|string|max:20',
            'client_ts' => 'nullable|numeric',
            'client_now' => 'nullable|numeric',
        ]);

        // Phones send their own clock. Use it only as an offset, so a scan that sat in an
        // offline queue keeps the time it was actually made even if the phone clock is wrong.
        $at = now();
        $age = 0;
        if (isset($data['client_ts'], $data['client_now'])) {
            $age = ($data['client_now'] - $data['client_ts']) / 1000;
            if ($age >= 0 && $age < 3 * 86400) {
                $at = $at->subSeconds((int) round($age));
            }
        }

        // A closed event takes no new scans, but scans a phone saved offline while it was open still count.
        $event = Event::find($data['event_id']);
        if (! $event->is_open && $age < 60) {
            return response()->json(['status' => 'error', 'message' => "“{$event->name}” is closed. Choose another event."], 422);
        }

        $user = $request->user();
        $result = $this->scans->record([
            'event_id' => $event->id,
            'client_id' => $data['client_id'] ?? null,
            'qr_text' => trim($data['qr_text']),
            'personnel_id' => null,
            'new_person' => null,
            'kind' => strtoupper($data['kind'] ?? 'IN') === 'OUT' ? 'OUT' : 'IN',
            'station' => $user->name,
            'user_id' => $user->id,
            'method' => $data['method'] ?? 'camera',
            'defer' => true,   // unknown IDs wait for the records PC to confirm them
        ], $at);

        return response()->json($this->forPhone($result));
    }

    /**
     * Events phones can scan into, most recent first.
     *
     * @return list<array{id: int, name: string, date: string, label: string, is_open: bool}>
     */
    private function openEvents(): array
    {
        return Event::where('is_open', true)->orderByDesc('event_date')->orderByDesc('id')->get()->map->card()->all();
    }

    /** A phone only needs the name to show. SN and PSR status stay on the records PC. */
    private function forPhone(array $result): array
    {
        if (isset($result['person'])) {
            $result['person'] = array_diff_key($result['person'], array_flip(['serial', 'psr_status']));
        }

        return $result;
    }

    /** Roster search for the records PC (person picker). */
    public function search(Request $request): JsonResponse
    {
        $q = NameMatcher::norm($request->query('q', ''));
        if (strlen($q) < 2) {
            return response()->json([]);
        }
        $squashed = str_replace(' ', '', $q);

        $people = Personnel::query()
            ->where(fn ($w) => $w->where('name_key', 'like', "%$q%")
                ->orWhere('surname_key', 'like', "$squashed%")
                ->orWhere('office_key', $squashed)
                ->orWhere('squadron_key', $squashed)
                ->orWhere('serial', 'like', "%$q%"))
            ->orderByRaw('surname_key = ? desc', [$squashed])
            ->orderBy('surname')->orderBy('first_name')
            ->limit(40)->get();

        return response()->json($people->map->card());
    }
}
