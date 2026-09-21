<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class ReportsController extends Controller
{
    /**
     * ============================================================
     * SYNTHÈSE DE SUPERVISION (RAPPORT RECOMPOSÉ)
     * ============================================================
     *
     * GET /api/v1/reports/summary
     *
     * Ne construit aucun document (PDF/Excel) : recompose à la
     * volée un JSON de synthèse à partir de données déjà existantes
     * (dashboard, réseau, audit, screening, CENTIF), pour couvrir
     * l'écran Rapports sans moteur documentaire complet.
     */
    public function summary(): JsonResponse
    {
        try {
            $management = DB::table('v_management_dashboard')->first();
            $aml = DB::table('v_aml_dashboard')->first();

            $agenciesCount = DB::table('agencies')->count();
            $caissesCount = DB::table('caisses')->count();

            $screeningStats = DB::table('screenings')
                ->selectRaw("
                    COUNT(*) as total_screenings,
                    SUM(CASE WHEN match_found = 1 THEN 1 ELSE 0 END) as matches_found
                ")
                ->first();

            $pepCount = DB::table('pep_matches')->distinct('client_id')->count('client_id');
            $sanctionCount = DB::table('sanction_matches')->distinct('client_id')->count('client_id');

            $centifStats = DB::table('centif_declarations')
                ->selectRaw("
                    COUNT(*) as total,
                    SUM(CASE WHEN transmission_status = 'DRAFT' THEN 1 ELSE 0 END) as draft,
                    SUM(CASE WHEN transmission_status = 'TRANSMITTED' THEN 1 ELSE 0 END) as transmitted,
                    SUM(CASE WHEN transmission_status = 'ACKNOWLEDGED' THEN 1 ELSE 0 END) as acknowledged,
                    SUM(CASE WHEN transmission_status = 'OPPOSED' THEN 1 ELSE 0 END) as opposed
                ")
                ->first();

            $auditCount = DB::table('audit_logs')->count();

            return response()->json([
                'success' => true,
                'data' => [
                    'generated_at' => now()->toIso8601String(),
                    'compliance' => [
                        'total_clients' => (int) ($aml->total_clients ?? 0),
                        'risky_clients' => (int) ($aml->risky_clients ?? 0),
                        'total_alerts' => (int) ($aml->total_alerts ?? 0),
                        'open_alerts' => (int) ($aml->open_alerts ?? 0),
                    ],
                    'network' => [
                        'agencies_count' => $agenciesCount,
                        'caisses_count' => $caissesCount,
                    ],
                    'screening' => [
                        'total_screenings' => (int) ($screeningStats->total_screenings ?? 0),
                        'matches_found' => (int) ($screeningStats->matches_found ?? 0),
                        'distinct_pep_clients' => $pepCount,
                        'distinct_sanction_clients' => $sanctionCount,
                    ],
                    'centif_declarations' => [
                        'total' => (int) ($centifStats->total ?? 0),
                        'draft' => (int) ($centifStats->draft ?? 0),
                        'transmitted' => (int) ($centifStats->transmitted ?? 0),
                        'acknowledged' => (int) ($centifStats->acknowledged ?? 0),
                        'opposed' => (int) ($centifStats->opposed ?? 0),
                    ],
                    'audit' => [
                        'total_events' => $auditCount,
                    ],
                ],
            ]);

        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la génération de la synthèse.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
