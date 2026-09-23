<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AgencyAccess;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class AmlDashboardController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        try {
            $clients = $this->clientQuery($request);
            $totalClients = (int) (clone $clients)->distinct()->count('c.id');
            $riskyClients = (int) (clone $clients)
                ->leftJoin('risk_levels as rl', 'rl.id', '=', 'c.risk_level_id')
                ->where(function (Builder $query): void {
                    $query->whereIn(DB::raw("UPPER(COALESCE(rl.code, ''))"), ['HIGH', 'CRITICAL'])
                        ->orWhere('c.risk_score', '>=', 60);
                })
                ->distinct()->count('c.id');

            $alerts = $this->alertQuery($request);
            $totalAlerts = (int) (clone $alerts)->distinct()->count('al.id');
            $openAlerts = (int) (clone $alerts)->whereIn('al.status', ['OPEN', 'IN_REVIEW'])
                ->distinct()->count('al.id');
            $criticalAlerts = (int) (clone $alerts)->where('al.priority', 'CRITICAL')
                ->distinct()->count('al.id');
            $transactionStats = $this->transactionQuery($request)
                ->selectRaw('COUNT(DISTINCT t.id) AS total')
                ->selectRaw('COALESCE(SUM(t.amount), 0) AS volume')->first();

            return response()->json([
                'success' => true,
                'data' => [
                    'clients' => [
                        'total' => $totalClients,
                        'risky' => $riskyClients,
                        'risk_rate' => $totalClients > 0 ? round(($riskyClients / $totalClients) * 100, 2) : 0,
                    ],
                    'alerts' => ['total' => $totalAlerts, 'open' => $openAlerts, 'critical' => $criticalAlerts],
                    'transactions' => [
                        'total' => (int) ($transactionStats->total ?? 0),
                        'volume' => (float) ($transactionStats->volume ?? 0),
                    ],
                    'scope' => AgencyAccess::scopeFor($request->user()),
                ],
            ]);
        } catch (Throwable $error) {
            return $this->failure('Erreur lors du chargement du tableau de bord.', $error);
        }
    }

    public function riskDistribution(Request $request): JsonResponse
    {
        try {
            $rows = $this->clientQuery($request)
                ->leftJoin('risk_levels as rl', 'rl.id', '=', 'c.risk_level_id')
                ->select([
                    'rl.id', 'rl.code', 'rl.label', 'rl.score_min', 'rl.score_max', 'rl.description',
                    DB::raw('COUNT(DISTINCT c.id) AS client_count'),
                ])
                ->groupBy('rl.id', 'rl.code', 'rl.label', 'rl.score_min', 'rl.score_max', 'rl.description')
                ->orderBy('rl.score_min')->get();
            $total = (int) $rows->sum('client_count');
            $distribution = $rows->map(fn (object $row): array => [
                'id' => $row->id,
                'code' => $row->code,
                'label' => $row->label,
                'score_min' => $row->score_min,
                'score_max' => $row->score_max,
                'client_count' => (int) $row->client_count,
                'percentage' => $total > 0 ? round(((int) $row->client_count / $total) * 100, 2) : 0,
            ])->values();

            return response()->json([
                'success' => true,
                'data' => [
                    'total_clients' => $total,
                    'high_and_critical_clients' => (int) $distribution
                        ->whereIn('code', ['HIGH', 'CRITICAL'])->sum('client_count'),
                    'distribution' => $distribution,
                ],
            ]);
        } catch (Throwable $error) {
            return $this->failure('Erreur lors de la récupération de la distribution des risques.', $error);
        }
    }

    public function alerts(Request $request): JsonResponse
    {
        try {
            $limit = min(max((int) $request->query('limit', 10), 1), 50);
            $byStatus = (clone $this->alertQuery($request))
                ->select('al.status', DB::raw('COUNT(DISTINCT al.id) AS count'))
                ->groupBy('al.status')->orderByDesc('count')->get();
            $byPriority = (clone $this->alertQuery($request))
                ->select('al.priority', DB::raw('COUNT(DISTINCT al.id) AS count'))
                ->groupBy('al.priority')->orderByDesc('count')->get();
            $byType = (clone $this->alertQuery($request))
                ->select('al.alert_type', DB::raw('COUNT(DISTINCT al.id) AS count'))
                ->groupBy('al.alert_type')->orderByDesc('count')->get();
            $recent = $this->alertQuery($request)
                ->leftJoin('client_individuals as ci', 'ci.client_id', '=', 'c.id')
                ->leftJoin('client_entities as ce', 'ce.client_id', '=', 'c.id')
                ->select([
                    'al.id', 'al.reference', 'al.client_id', 'al.transaction_id', 'al.alert_type',
                    'al.priority', 'al.status', 'al.final_score', 'al.title', 'al.description',
                    'al.created_at', 'c.client_number',
                    DB::raw("COALESCE(NULLIF(TRIM(CONCAT(COALESCE(ci.first_name, ''), ' ', COALESCE(ci.last_name, ''))), ''), ce.legal_name, c.client_number) AS client_name"),
                ])->orderByDesc('al.created_at')->limit($limit)->get();
            $base = $this->alertQuery($request);

            return response()->json([
                'success' => true,
                'data' => [
                    'summary' => [
                        'total' => (int) (clone $base)->distinct()->count('al.id'),
                        'open' => (int) (clone $base)->where('al.status', 'OPEN')->distinct()->count('al.id'),
                        'high_priority' => (int) (clone $base)->whereIn('al.priority', ['HIGH', 'CRITICAL'])->distinct()->count('al.id'),
                        'aml_rule_engine' => (int) (clone $base)->where('al.alert_type', 'AML_RULE_ENGINE')->distinct()->count('al.id'),
                    ],
                    'by_status' => $byStatus,
                    'by_priority' => $byPriority,
                    'by_type' => $byType,
                    'recent' => $recent,
                ],
            ]);
        } catch (Throwable $error) {
            return $this->failure('Erreur lors de la récupération des statistiques d’alertes.', $error);
        }
    }

    public function transactions(Request $request): JsonResponse
    {
        try {
            $limit = min(max((int) $request->query('limit', 10), 1), 50);
            $base = $this->transactionQuery($request);
            $stats = (clone $base)
                ->selectRaw('COUNT(DISTINCT t.id) AS total')
                ->selectRaw("SUM(CASE WHEN UPPER(COALESCE(t.transaction_status, 'VALID')) NOT IN ('CANCELLED','CANCELED','REVERSED','VOID') THEN 1 ELSE 0 END) AS valid_count")
                ->selectRaw("COALESCE(SUM(CASE WHEN UPPER(COALESCE(t.transaction_status, 'VALID')) NOT IN ('CANCELLED','CANCELED','REVERSED','VOID') THEN t.amount ELSE 0 END), 0) AS total_volume")
                ->selectRaw("COALESCE(AVG(CASE WHEN UPPER(COALESCE(t.transaction_status, 'VALID')) NOT IN ('CANCELLED','CANCELED','REVERSED','VOID') THEN t.amount END), 0) AS average_amount")
                ->first();
            $byStatus = (clone $base)->select('t.transaction_status', DB::raw('COUNT(*) AS count'))
                ->groupBy('t.transaction_status')->orderByDesc('count')->get();
            $byChannel = (clone $base)->select('t.channel', DB::raw('COUNT(*) AS count'), DB::raw('COALESCE(SUM(t.amount), 0) AS volume'))
                ->groupBy('t.channel')->orderByDesc('count')->get();
            $recent = (clone $base)
                ->leftJoin('clients as c', 'c.id', '=', 'a.client_id')
                ->leftJoin('client_individuals as ci', 'ci.client_id', '=', 'c.id')
                ->leftJoin('client_entities as ce', 'ce.client_id', '=', 'c.id')
                ->select([
                    't.id', 't.transaction_reference', 't.account_id', 'a.account_number', 'a.account_type',
                    'a.client_id', 'c.client_number', 'c.client_type', 't.transaction_type', 't.amount',
                    't.currency', 't.channel', 't.country', 't.country_from', 't.country_to',
                    't.transaction_date', 't.transaction_status',
                    DB::raw("COALESCE(NULLIF(TRIM(CONCAT(COALESCE(ci.first_name, ''), ' ', COALESCE(ci.last_name, ''))), ''), ce.legal_name, c.client_number) AS client_name"),
                ])->orderByDesc('t.transaction_date')->limit($limit)->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'summary' => [
                        'total' => (int) ($stats->total ?? 0),
                        'valid_count' => (int) ($stats->valid_count ?? 0),
                        'total_volume' => (float) ($stats->total_volume ?? 0),
                        'average_amount' => (float) ($stats->average_amount ?? 0),
                    ],
                    'by_status' => $byStatus,
                    'by_channel' => $byChannel,
                    'recent' => $recent,
                ],
            ]);
        } catch (Throwable $error) {
            return $this->failure('Erreur lors de la récupération des statistiques transactionnelles.', $error);
        }
    }

    public function alertsTrend(Request $request): JsonResponse
    {
        try {
            $days = min(max((int) $request->query('days', 7), 1), 90);
            $rows = $this->alertQuery($request)
                ->select([
                    DB::raw('DATE(al.created_at) AS day'),
                    DB::raw('COUNT(DISTINCT al.id) AS alerts'),
                    DB::raw("COUNT(DISTINCT CASE WHEN al.priority = 'CRITICAL' THEN al.id END) AS critical"),
                    DB::raw("COUNT(DISTINCT CASE WHEN al.priority = 'HIGH' THEN al.id END) AS high"),
                ])
                ->where('al.created_at', '>=', now()->subDays($days - 1)->startOfDay())
                ->groupBy(DB::raw('DATE(al.created_at)'))->orderBy('day')->get()->keyBy('day');
            $series = [];
            for ($index = 0; $index < $days; $index++) {
                $date = now()->subDays($days - 1 - $index)->format('Y-m-d');
                $row = $rows->get($date);
                $series[] = [
                    'date' => $date,
                    'alerts' => $row ? (int) $row->alerts : 0,
                    'critical' => $row ? (int) $row->critical : 0,
                    'high' => $row ? (int) $row->high : 0,
                ];
            }

            return response()->json(['success' => true, 'data' => $series]);
        } catch (Throwable $error) {
            return $this->failure('Erreur lors du calcul de la tendance des alertes.', $error);
        }
    }

    private function clientQuery(Request $request): Builder
    {
        $query = DB::table('clients as c')->leftJoin('accounts as a', 'a.client_id', '=', 'c.id');
        AgencyAccess::constrain($query, $request, 'c.agency_id', 'a.account_manager_id');

        return $query;
    }

    private function alertQuery(Request $request): Builder
    {
        $query = DB::table('alerts as al')
            ->leftJoin('clients as c', 'c.id', '=', 'al.client_id')
            ->leftJoin('transactions as t', 't.id', '=', 'al.transaction_id')
            ->leftJoin('accounts as a', 'a.id', '=', 't.account_id');
        AgencyAccess::constrain($query, $request, DB::raw('COALESCE(t.agency_id, c.agency_id)'), 'a.account_manager_id');

        return $query;
    }

    private function transactionQuery(Request $request): Builder
    {
        $query = DB::table('transactions as t')->leftJoin('accounts as a', 'a.id', '=', 't.account_id');
        AgencyAccess::constrain($query, $request, 't.agency_id', 'a.account_manager_id');

        return $query;
    }

    private function failure(string $message, Throwable $error): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error' => config('app.debug') ? $error->getMessage() : null,
        ], 500);
    }
}
