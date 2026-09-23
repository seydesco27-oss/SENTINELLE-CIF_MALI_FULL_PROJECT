<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SanctionsFileParser;
use App\Services\SanctionsImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class AdminScreeningController extends Controller
{
    public function index(): JsonResponse
    {
        $lists = DB::table('screening_lists as l')->whereIn('l.id', [1, 2, 3])
            ->select('l.id', 'l.name', 'l.source_organization', 'l.last_update')
            ->selectSub(DB::table('screening_list_entries as e')->whereColumn('e.screening_list_id', 'l.id')->where('e.is_active', true)->selectRaw('COUNT(*)'), 'active_count')
            ->orderBy('l.id')->get();
        return response()->json(['success' => true, 'data' => $lists, 'options' => [
            'caisses' => DB::table('caisses')->orderBy('name')->get(['id', 'code', 'name']),
            'agencies' => DB::table('agencies')->orderBy('name')->get(['id', 'code', 'name', 'caisse_id']),
        ]]);
    }

    public function import(Request $request, int $id, SanctionsFileParser $parser, SanctionsImportService $importer): JsonResponse
    {
        abort_unless(isset(SanctionsImportService::SOURCES[$id]) && DB::table('screening_lists')->where('id', $id)->exists(), 404);
        $request->validate(['file' => ['required', 'file', 'max:51200', 'extensions:xml,csv,txt']]);
        $file = $request->file('file');
        $filename = mb_substr(basename($file->getClientOriginalName()), 0, 255);
        $batchId = DB::table('sanctions_import_batches')->insertGetId([
            'source' => SanctionsImportService::SOURCES[$id], 'source_file' => $filename,
            'source_file_hash_sha256' => hash_file('sha256', $file->getRealPath()),
            'retrieved_at' => now(), 'import_status' => 'RUNNING',
            'notes' => 'Import manuel par utilisateur #'.$request->user()->id,
        ]);
        try {
            $parsed = $parser->parse($file->getContent(), SanctionsImportService::SOURCES[$id]);
            DB::transaction(function () use ($id, $parsed, $filename, $batchId, $importer) {
                $importer->apply($id, $parsed['entries'], $filename);
                DB::table('sanctions_import_batches')->where('id', $batchId)->update([
                    'import_status' => 'SUCCESS', 'record_count_raw' => $parsed['raw_count'],
                    'record_count_imported' => count($parsed['entries']), 'imported_at' => now(),
                ]);
            });
            return response()->json(['success' => true,
                'message' => 'Liste mise à jour. Il est recommandé de lancer un screening batch.',
                'data' => ['batch_id' => $batchId, 'status' => 'SUCCESS', 'imported' => count($parsed['entries'])],
            ]);
        } catch (Throwable $e) {
            $safeMessage = $e instanceof \InvalidArgumentException ? $e->getMessage() : 'L’import a échoué. Les entrées précédentes sont conservées.';
            DB::table('sanctions_import_batches')->where('id', $batchId)->update(['import_status' => 'FAILED', 'notes' => $safeMessage, 'imported_at' => now()]);
            if (! $e instanceof \InvalidArgumentException) report($e);
            return response()->json(['success' => false, 'message' => $safeMessage, 'data' => ['batch_id' => $batchId, 'status' => 'FAILED']], 422);
        }
    }

    public function batches(int $id): JsonResponse
    {
        abort_unless(isset(SanctionsImportService::SOURCES[$id]), 404);
        return response()->json(['success' => true, 'data' => DB::table('sanctions_import_batches')
            ->where('source', SanctionsImportService::SOURCES[$id])->orderByDesc('id')->limit(30)->get()]);
    }

    public function runBatch(Request $request): JsonResponse
    {
        $values = $request->validate([
            'caisse_id' => ['nullable', 'integer', 'exists:caisses,id'],
            'agency_id' => ['nullable', 'integer', 'exists:agencies,id'],
            'batch_size' => ['required', 'integer', 'min:1', 'max:500'],
            'force' => ['required', 'boolean'],
            'after_id' => ['sometimes', 'integer', 'min:0'],
        ]);
        if (empty($values['caisse_id']) && empty($values['agency_id'])) {
            throw ValidationException::withMessages(['caisse_id' => 'Sélectionnez une caisse ou une agence pour ce screening.']);
        }
        if (! empty($values['agency_id']) && ! empty($values['caisse_id'])
            && ! DB::table('agencies')->where('id', $values['agency_id'])->where('caisse_id', $values['caisse_id'])->exists()) {
            throw ValidationException::withMessages(['agency_id' => 'Cette agence n’appartient pas à la caisse sélectionnée.']);
        }
        $query = DB::table('clients as c')->join('agencies as ag', 'ag.id', '=', 'c.agency_id');
        if (! empty($values['caisse_id'])) $query->where('ag.caisse_id', $values['caisse_id']);
        if (! empty($values['agency_id'])) $query->where('c.agency_id', $values['agency_id']);
        if (! $values['force']) $query->whereNotExists(fn ($q) => $q->selectRaw('1')->from('screenings as s')->whereColumn('s.client_id', 'c.id')->where('s.screening_date', '>=', today()));
        $total = (clone $query)->count();
        $query->where('c.id', '>', $values['after_id'] ?? 0);
        $ids = $query->orderBy('c.id')->limit($values['batch_size'] + 1)->pluck('c.id');
        $hasMore = $ids->count() > $values['batch_size'];
        $selected = $ids->take($values['batch_size']);
        $processed = 0;
        foreach ($selected as $clientId) {
            // La procédure globale n'a pas de paramètres de périmètre : boucle filtrée autorisée par le brief.
            DB::transaction(function () use ($clientId, $values) {
                $statement = DB::connection()->getPdo()->prepare('CALL sp_screen_client(?, ?)');
                try {
                    $statement->execute([(int) $clientId, (int) $values['force']]);
                    while ($statement->nextRowset()) { /* Libérer tous les résultats MySQL. */ }
                } finally { $statement->closeCursor(); }
            });
            $processed++;
        }
        return response()->json(['success' => true, 'data' => [
            'processed' => $processed, 'eligible' => $total, 'has_more' => $hasMore,
            'next_after_id' => $selected->last() ?? ($values['after_id'] ?? 0),
            'caisse_id' => $values['caisse_id'] ?? null, 'agency_id' => $values['agency_id'] ?? null,
        ], 'message' => $processed ? "{$processed} clients contrôlés." : 'Aucun client à contrôler dans ce périmètre.']);
    }
}
