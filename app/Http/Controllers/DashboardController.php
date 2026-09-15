<?php

namespace App\Http\Controllers;

use App\Models\Personnel;
use App\Models\Scan;
use App\Models\Setting;
use App\Models\User;
use App\Services\AttendanceReport;
use App\Services\ExcelExport;
use App\Services\ScanService;
use App\Services\WriteLock;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class DashboardController extends Controller
{
    public function __construct(private ScanService $scans) {}

    public function page(Request $request): InertiaResponse
    {
        $day = $this->day($request);
        // Built at most once per request, and only if a requested prop needs it (partial reloads).
        $cached = null;
        $report = function () use (&$cached, $day) {
            return $cached ??= new AttendanceReport($day);
        };

        return Inertia::render('Dashboard', [
            'date' => $day,
            'stats' => function () use ($report) {
                $r = $report();
                $roster = $r->roster();

                return [
                    'roster_total' => $roster->count(),
                    'present' => $roster->filter(fn ($p) => $r->isPresent($p))->count(),
                    'excused' => $r->excused()->count(),
                    'unaccounted' => $r->unaccounted()->count(),
                    'walk_ins' => $r->walkInsPresent()->count(),
                    'inside' => $r->insideNow(),
                ];
            },
            'bySquadron' => fn () => $report()->bySquadron(),
            'arrivals' => fn () => $report()->arrivals(),
            'pending' => fn () => $report()->pendingScans()->groupBy('qr_text')->map(fn ($group, $qr) => [
                'qr_text' => $qr,
                'time' => $group->first()->scanned_at->format('H:i'),
                'count' => $group->count(),
                'stations' => $group->pluck('station')->unique()->implode(', '),
            ] + $this->scans->candidates($qr))->values(),
            'recent' => fn () => Scan::with('personnel')->where('day', $day)->orderByDesc('scanned_at')->orderByDesc('id')
                ->limit(60)->get()->map(fn ($s) => $this->scans->result($s)),
            'autoMatch' => fn () => $this->scans->autoMatchEnabled(),
            'connect' => fn () => $this->connectInfo(),
            'phones' => fn () => $this->phones($day),
            'serverTime' => fn () => now()->format('H:i:s'),
            // Only loaded when the page asks for it (router.reload({ only: ['absent'] })).
            'absent' => Inertia::optional(fn () => [
                'unaccounted' => $report()->unaccounted()->values()->map->card(),
                'excused' => $report()->excused()->sortBy('psr_status')->values()->map->card(),
            ]),
        ]);
    }

    public function confirm(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'qr_text' => 'required|string|max:191',
            'personnel_id' => 'nullable|integer|exists:personnel,id',
            'new_person' => 'nullable|array',
            'new_person.first_name' => 'nullable|string|max:80',
        ]);
        abort_if(empty($data['personnel_id']) && empty($data['new_person']), 422, 'Choose a person');

        $pid = WriteLock::run(fn () => DB::transaction(function () use ($data) {
            $pid = ! empty($data['new_person'])
                ? $this->scans->createWalkIn($data['new_person'], $data['qr_text'])->id
                : (int) $data['personnel_id'];
            $this->scans->link($data['qr_text'], $pid);

            return $pid;
        }));
        $p = Personnel::find($pid);

        return back()->with('success', "Confirmed: {$data['qr_text']} → {$p->rank} {$p->full_name}");
    }

    public function markPresent(Request $request, Personnel $person): RedirectResponse
    {
        $result = $this->scans->record([
            'client_id' => null, 'qr_text' => null, 'personnel_id' => $person->id, 'new_person' => null,
            'kind' => $request->input('kind') === 'OUT' ? 'OUT' : 'IN',
            'station' => 'Records PC', 'user_id' => $request->user()->id, 'method' => 'manual', 'defer' => false,
        ], now());

        return back()->with($result['status'] === 'ok' ? 'success' : 'error',
            ($result['status'] === 'ok' ? 'Marked present: ' : $result['message'].': ')."{$person->rank} {$person->full_name}");
    }

    public function assign(Request $request, Scan $scan): RedirectResponse
    {
        $data = $request->validate(['personnel_id' => 'required|integer|exists:personnel,id']);
        $this->scans->assign($scan, (int) $data['personnel_id']);

        return back()->with('success', 'Scan moved to the chosen person.');
    }

    public function void(Scan $scan): RedirectResponse
    {
        $this->scans->void($scan);

        return back()->with('success', 'Scan cancelled.');
    }

    public function clearScans(Request $request): RedirectResponse
    {
        $day = $request->input('date');
        $deleted = WriteLock::run(function () use ($day) {
            if ($day === 'ALL') {
                return Scan::query()->delete();
            }
            abort_unless(is_string($day) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day), 422, 'date required');

            return Scan::where('day', $day)->delete();
        });

        return back()->with('success', "$deleted scans deleted.");
    }

    public function settings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'event_name' => 'sometimes|required|string|max:120',
            'auto_match' => 'sometimes|boolean',
        ]);
        if (isset($data['event_name'])) {
            Setting::put('event_name', trim($data['event_name']));

            return back()->with('success', 'Event name saved.');
        }
        Setting::put('auto_match', $data['auto_match'] ? '1' : '0');
        $matched = $data['auto_match'] ? $this->scans->autoMatchPending() : 0;

        return back()->with('success', $data['auto_match']
            ? "Automatic matching on. $matched waiting ".($matched === 1 ? 'ID was' : 'IDs were').' matched.'
            : 'Automatic matching off: every new ID waits for you to confirm.');
    }

    public function autoMatchPending(): RedirectResponse
    {
        $matched = $this->scans->autoMatchPending();

        return back()->with('success', $matched ? "$matched ".($matched === 1 ? 'ID' : 'IDs').' matched automatically.' : 'No waiting ID could be matched automatically.');
    }

    public function export(Request $request, ExcelExport $excel)
    {
        $day = $this->day($request);
        $event = Setting::eventName();
        $book = $excel->build(new AttendanceReport($day), $event);
        $name = preg_replace('/[^A-Za-z0-9]+/', '_', $event)."_$day.xlsx";
        $tmp = tempnam(sys_get_temp_dir(), 'att');
        $excel->save($book, $tmp);

        return response()->download($tmp, $name)->deleteFileAfterSend();
    }

    public function qr(Request $request)
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(220, 1), new SvgImageBackEnd));

        return response($writer->writeString(substr((string) $request->query('data', ''), 0, 500)))
            ->header('Content-Type', 'image/svg+xml');
    }

    private function connectInfo(): array
    {
        // Hosted online (cPanel): phones simply open this site's own address.
        if (! config('attendance.admin_localhost_only')) {
            return ['online' => ['status' => 'up', 'hosted' => true], 'online_url' => url('/scan'), 'lan_urls' => []];
        }

        $port = config('attendance.phone_port');

        // Written by tools/gateway.mjs once the Cloudflare tunnel is up.
        $tunnel = @file_get_contents(storage_path('app/tunnel.json'));
        $tunnel = $tunnel ? json_decode($tunnel, true) : null;

        return [
            'online' => $tunnel,
            'online_url' => ! empty($tunnel['url']) ? $tunnel['url'].'/scan' : null,
            'lan_urls' => array_map(fn ($ip) => "https://$ip:$port/scan", $this->lanIps()),
        ];
    }

    /** Each scanner account: scans today and when its phone last checked in. */
    private function phones(string $day): array
    {
        $counts = Scan::where('day', $day)->where('status', '!=', 'void')->whereNotNull('user_id')
            ->selectRaw('user_id, count(*) as n, max(scanned_at) as last_at')->groupBy('user_id')->get()->keyBy('user_id');

        return User::where('role', User::SCANNER)->where('is_active', true)->orderBy('name')->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'scans' => (int) ($counts[$u->id]->n ?? 0),
                'last_scan' => isset($counts[$u->id]) ? substr($counts[$u->id]->last_at, 11, 5) : null,
                'last_seen' => Cache::get("last_seen:{$u->id}"),
            ])->all();
    }

    private function day(Request $request): string
    {
        $day = $request->query('date') ?: now()->toDateString();
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $day), 422, 'bad date');

        return $day;
    }

    private function lanIps(): array
    {
        $ips = gethostbynamel(gethostname()) ?: [];
        if (function_exists('socket_create')) {
            $sock = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
            if ($sock && @socket_connect($sock, '8.8.8.8', 53) && @socket_getsockname($sock, $addr)) {
                $ips[] = $addr;   // no packet is sent; this just asks which interface has the default route
            }
            if ($sock) {
                socket_close($sock);
            }
        }
        $ips = array_values(array_unique(array_filter($ips, fn ($ip) => ! str_starts_with($ip, '127.') && ! str_starts_with($ip, '169.254.'))));
        usort($ips, fn ($a, $b) => [! str_starts_with($a, '192.168.'), $a] <=> [! str_starts_with($b, '192.168.'), $b]);

        return $ips;
    }
}
