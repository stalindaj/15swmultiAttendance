<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Personnel;
use App\Services\RosterImporter;
use App\Services\WriteLock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * An event's attendee list comes from an uploaded PSR / Excel file, parsed like the roster.
 * People in the file are added to (or updated in) the roster by serial number, then put on the list.
 */
class EventAttendeeController extends Controller
{
    public function __construct(private RosterImporter $importer) {}

    public function upload(Request $request, Event $event): JsonResponse
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

    public function preview(Request $request, Event $event, string $token): JsonResponse
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

    /**
     * Modes: "replace" makes the file the whole attendee list; "add" adds its people to the list.
     */
    public function import(Request $request, Event $event): JsonResponse
    {
        $data = $request->validate([
            'token' => 'required|string',
            'sheet' => 'required|string',
            'header_row' => 'required|integer',
            'mapping' => 'array',
            'mode' => 'required|in:replace,add',
            'fixed_squadron' => 'nullable|string|max:40',
        ]);
        $mapping = collect($data['mapping'] ?? [])
            ->filter(fn ($v, $k) => array_key_exists($k, RosterImporter::FIELDS) && is_numeric($v) && $v >= 0)
            ->map(fn ($v) => (int) $v)->all();

        try {
            $rows = $this->importer->rows($this->importer->path($data['token']), $data['sheet']);
            $stats = $this->importer->import($rows, (int) $data['header_row'], $mapping, 'upsert', (string) ($data['fixed_squadron'] ?? ''));
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        WriteLock::run(fn () => DB::transaction(fn () => $data['mode'] === 'replace'
            ? $event->attendees()->sync($stats['ids'])
            : $event->attendees()->syncWithoutDetaching($stats['ids'])));

        return response()->json([
            'ok' => true,
            'on_list' => count($stats['ids']),
            'new_people' => $stats['added'],
            'skipped' => $stats['skipped'],
            'total' => $event->attendees()->count(),
        ]);
    }

    public function destroy(Event $event, Personnel $person): RedirectResponse
    {
        WriteLock::run(fn () => $event->attendees()->detach($person->id));

        return back()->with('success', "Removed {$person->rank} {$person->full_name} from the list.");
    }
}
