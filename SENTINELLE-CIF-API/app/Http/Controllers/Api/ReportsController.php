<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AccessProfile;
use App\Support\AgencyAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class ReportsController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        try {
            $clients = DB::table('clients as c')->leftJoin('accounts as acc', 'acc.client_id', '=', 'c.id');
            AgencyAccess::constrain($clients, $request, 'c.agency_id', 'acc.account_manager_id');
            $totalClients = (int) (clone $clients)->distinct()->count('c.id');
            $riskyClients = (int) (clone $clients)->where('c.risk_score', '>=', 60)->distinct()->count('c.id');

            $alerts = DB::table('alerts as al')
                ->leftJoin('clients as c', 'c.id', '=', 'al.client_id')
                ->leftJoin('transactions as t', 't.id', '=', 'al.transaction_id')
                ->leftJoin('accounts as acc', 'acc.id', '=', 't.account_id');
            AgencyAccess::constrain($alerts, $request, DB::raw('COALESCE(t.agency_id, c.agency_id)'), 'acc.account_manager_id');

            $agencies = DB::table('agencies as ag');
            AgencyAccess::constrain($agencies, $request, 'ag.id');
            $agencyIds = $agencies->pluck('ag.id');
            $caisseCount = $agencyIds->isEmpty() ? 0 : DB::table('agencies')->whereIn('id', $agencyIds)
                ->distinct()->count('caisse_id');

            $screenings = DB::table('screenings as s')
                ->join('clients as c', 'c.id', '=', 's.client_id')
                ->leftJoin('accounts as acc', 'acc.client_id', '=', 'c.id');
            AgencyAccess::constrain($screenings, $request, 'c.agency_id', 'acc.account_manager_id');
            $screeningStats = $screenings
                ->selectRaw('COUNT(DISTINCT s.id) AS total_screenings')
                ->selectRaw('COUNT(DISTINCT CASE WHEN s.match_found = 1 THEN s.id END) AS matches_found')
                ->first();

            $pep = DB::table('pep_matches as pm')->join('clients as c', 'c.id', '=', 'pm.client_id')
                ->leftJoin('accounts as acc', 'acc.client_id', '=', 'c.id');
            AgencyAccess::constrain($pep, $request, 'c.agency_id', 'acc.account_manager_id');
            $sanctions = DB::table('sanction_matches as sm')->join('clients as c', 'c.id', '=', 'sm.client_id')
                ->leftJoin('accounts as acc', 'acc.client_id', '=', 'c.id');
            AgencyAccess::constrain($sanctions, $request, 'c.agency_id', 'acc.account_manager_id');

            $canViewCentif = AccessProfile::allows($request->user(), 'centif.view');
            $centifStats = null;
            if ($canViewCentif) {
                $centif = DB::table('centif_declarations as d')->join('clients as c', 'c.id', '=', 'd.client_id');
                AgencyAccess::constrain($centif, $request, 'c.agency_id');
                $centifStats = $centif
                    ->selectRaw('COUNT(DISTINCT d.id) AS total')
                    ->selectRaw("COUNT(DISTINCT CASE WHEN d.transmission_status = 'DRAFT' THEN d.id END) AS draft")
                    ->selectRaw("COUNT(DISTINCT CASE WHEN d.transmission_status = 'TRANSMITTED' THEN d.id END) AS transmitted")
                    ->selectRaw("COUNT(DISTINCT CASE WHEN d.transmission_status = 'ACKNOWLEDGED' THEN d.id END) AS acknowledged")
                    ->selectRaw("COUNT(DISTINCT CASE WHEN d.transmission_status = 'OPPOSED' THEN d.id END) AS opposed")
                    ->first();
            }

            $auditCount = null;
            if (AccessProfile::allows($request->user(), 'audit.view')) {
                $audit = DB::table('audit_logs as al')->leftJoin('users as u', 'u.id', '=', 'al.user_id');
                AgencyAccess::constrain($audit, $request, 'u.agency_id');
                $auditCount = (int) $audit->distinct()->count('al.id');
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'generated_at' => now()->toIso8601String(),
                    'scope' => AgencyAccess::scopeFor($request->user()),
                    'compliance' => [
                        'total_clients' => $totalClients,
                        'risky_clients' => $riskyClients,
                        'total_alerts' => (int) (clone $alerts)->distinct()->count('al.id'),
                        'open_alerts' => (int) (clone $alerts)->whereIn('al.status', ['OPEN', 'IN_REVIEW'])->distinct()->count('al.id'),
                    ],
                    'network' => ['agencies_count' => $agencyIds->count(), 'caisses_count' => $caisseCount],
                    'screening' => [
                        'total_screenings' => (int) ($screeningStats->total_screenings ?? 0),
                        'matches_found' => (int) ($screeningStats->matches_found ?? 0),
                        'distinct_pep_clients' => (int) $pep->distinct()->count('pm.client_id'),
                        'distinct_sanction_clients' => (int) $sanctions->distinct()->count('sm.client_id'),
                    ],
                    'centif_declarations' => [
                        'available' => $canViewCentif,
                        'total' => (int) ($centifStats->total ?? 0),
                        'draft' => (int) ($centifStats->draft ?? 0),
                        'transmitted' => (int) ($centifStats->transmitted ?? 0),
                        'acknowledged' => (int) ($centifStats->acknowledged ?? 0),
                        'opposed' => (int) ($centifStats->opposed ?? 0),
                    ],
                    'audit' => ['available' => $auditCount !== null, 'total_events' => $auditCount],
                ],
            ]);
        } catch (Throwable $error) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la génération de la synthèse.',
                'error' => config('app.debug') ? $error->getMessage() : null,
            ], 500);
        }
    }
}
