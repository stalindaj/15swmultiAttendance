<?php

namespace App\Http\Controllers;

use App\Models\Personnel;
use App\Models\Scan;
use App\Models\Setting;
use App\Services\ScanService;
use App\Services\WriteLock;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/** The records PC's corrections: confirming who an ID belongs to, fixing or cancelling scans. */
class ReviewController extends Controller
{
    public function __construct(private ScanService $scans) {}

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

    public function settings(Request $request): RedirectResponse
    {
        $data = $request->validate(['auto_match' => 'required|boolean']);
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

    public function qr(Request $request): Response
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(220, 1), new SvgImageBackEnd));

        return response($writer->writeString(substr((string) $request->query('data', ''), 0, 500)))
            ->header('Content-Type', 'image/svg+xml');
    }
}
