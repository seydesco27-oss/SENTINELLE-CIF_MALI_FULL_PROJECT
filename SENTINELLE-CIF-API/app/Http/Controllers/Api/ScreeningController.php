<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class ScreeningController extends Controller
{
    /**
     * ============================================================
     * REGISTRE PORTEFEUILLE DES CONTRÔLES SCREENING
     * ============================================================
     *
     * GET /api/v1/screening
     *
     * Un client peut avoir plusieurs screenings dans le temps ;
     * cette liste ne retient que le dernier par client.
     *
     * Filtres :
     * ?status=A_VERIFIER|ABSENCE_DE_CORRESPONDANCE|CORRESPONDANCE_POTENTIELLE
     * ?search= (nom/numéro client)
     * ?limit=
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $limit = min(max((int) $request->query('limit', 50), 1), 200);

            $latestScreeningIds = DB::table('screenings')
                ->selectRaw('MAX(id) as id')
                ->groupBy('client_id');

            $query = DB::table('screenings as s')
                ->joinSub($latestScreeningIds, 'latest', function ($join) {
                    $join->on('s.id', '=', 'latest.id');
                })
                ->join('clients as c', 'c.id', '=', 's.client_id')
                ->leftJoin('client_individuals as ci', 'ci.client_id', '=', 'c.id')
                ->leftJoin('client_entities as ce', 'ce.client_id', '=', 'c.id')
                ->leftJoin('screening_lists as sl', 'sl.id', '=', 's.screening_list_id')
                ->select([
                    's.id as screening_id',
                    'c.id as client_id',
                    'c.client_number',
                    'c.client_type',
                    'c.is_pep',
                    'c.risk_score',
                    DB::raw("COALESCE(CONCAT(ci.first_name, ' ', ci.last_name), ce.legal_name) as customer_name"),
                    's.screening_date',
                    's.status',
                    's.match_found',
                    's.confidence_score',
                    'sl.name as screening_list_name',
                    DB::raw('(SELECT COUNT(*) FROM pep_matches pm WHERE pm.client_id = c.id) as pep_match_count'),
                    DB::raw('(SELECT COUNT(*) FROM sanction_matches sm WHERE sm.client_id = c.id) as sanction_match_count'),
                ]);

            if ($request->filled('status')) {
                $query->where('s.status', strtoupper($request->query('status')));
            }

            if ($request->filled('search')) {
                $search = trim($request->query('search'));
                $query->where(function ($q) use ($search) {
                    $q->where('c.client_number', 'like', '%' . $search . '%')
                        ->orWhere('ci.first_name', 'like', '%' . $search . '%')
                        ->orWhere('ci.last_name', 'like', '%' . $search . '%')
                        ->orWhere('ce.legal_name', 'like', '%' . $search . '%');
                });
            }

            $results = $query
                ->orderByDesc('s.screening_date')
                ->limit($limit)
                ->get();

            return response()->json([
                'success' => true,
                'count' => $results->count(),
                'data' => $results,
            ]);

        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération du registre de screening.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
