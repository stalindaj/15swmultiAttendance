<?php

namespace App\Http\Controllers;

use App\Models\Personnel;
use App\Models\QrLink;
use App\Services\RosterImporter;
use App\Services\WriteLock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use RuntimeException;

class RosterController extends Controller
{
    public function __construct(private RosterImporter $importer) {}

    public function page(): InertiaResponse
    {
        $people = Personnel::orderBy('is_walk_in')->orderBy('squadron')->orderBy('surname')->orderBy('first_name')->get();

        // Same family name + same office/squadron: the QR alone cannot tell these people apart.
        $collisions = $people->where('is_walk_in', false)->filter(fn ($p) => $p->surname_key !== '')
            ->groupBy(fn ($p) => $p->surname_key.'|'.($p->office_key ?: $p->squadron_key))
            ->filter(fn ($g) => $g->count() > 1)
            ->map(fn ($g) => $g->map->card()->values())->values();

        return Inertia::render('Roster', [
            'fields' => RosterImporter::FIELDS,
            'people' => $people->map->card(),
            'collisions' => $collisions,
            'linkedQr' => QrLink::count(),
        ]);
    }

    public function upload(Request $request): JsonResponse
    {
        $request->validate(['file' => 'required|file|max:25600']);
        try {
            $token = $this->importer->store($request->file('file'));

            return response()->json(['token' => $token] + $this->importer->sheets($this->importer->path($token)));
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Could not read that file: '.$e->getMessage()], 422);
        }
    }

    public function preview(Request $request, string $token): JsonResponse
    {
        try {
            $rows = $this->importer->rows($this->importer->path($token), (string) $request->query('sheet', 'CSV'));
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        [$headerIdx, $headers, $mapping] = $this->importer->guessLayout($rows);

        return response()->json([
            'header_row' => $headerIdx,
            'headers' => $headers,
            'mapping' => (object) $mapping,
            'sample' => array_slice($rows, $headerIdx + 1, 12),
            'total' => count($rows) - $headerIdx - 1,
        ]);
    }

    public function import(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => 'required|string',
            'sheet' => 'required|string',
            'header_row' => 'required|integer',
            'mapping' => 'array',
            'mode' => 'required|in:replace,upsert,update',
            'fixed_squadron' => 'nullable|string|max:40',
        ]);
        $mapping = collect($data['mapping'] ?? [])
            ->filter(fn ($v, $k) => array_key_exists($k, RosterImporter::FIELDS) && is_numeric($v) && $v >= 0)
            ->map(fn ($v) => (int) $v)->all();

        try {
            $rows = $this->importer->rows($this->importer->path($data['token']), $data['sheet']);
            $stats = $this->importer->import($rows, (int) $data['header_row'], $mapping, $data['mode'], (string) ($data['fixed_squadron'] ?? ''));
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true] + Arr::except($stats, 'ids'));
    }

    public function addPerson(Request $request): JsonResponse
    {
        $data = $request->validate([
            'rank' => 'nullable|string|max:40',
            'surname' => 'required|string|max:80',
            'first_name' => 'nullable|string|max:80',
            'middle' => 'nullable|string|max:40',
            'serial' => 'nullable|string|max:40',
            'squadron' => 'nullable|string|max:40',
            'office' => 'nullable|string|max:80',
            'walk_in' => 'nullable|boolean',
        ]);
        $p = WriteLock::run(fn () => Personnel::create(array_map(fn ($v) => (string) $v, collect($data)->except('walk_in')->all())
            + ['is_walk_in' => (bool) ($data['walk_in'] ?? false)]));

        return back()->with('success', "Added {$p->rank} {$p->full_name}");
    }

    public function deletePerson(Personnel $person): RedirectResponse
    {
        if ($person->scans()->where('status', '!=', 'void')->exists()) {
            return back()->with('error', 'This person already has scans. Cancel (void) those first.');
        }
        WriteLock::run(fn () => $person->delete());

        return back()->with('success', "Removed {$person->rank} {$person->full_name}");
    }
}
