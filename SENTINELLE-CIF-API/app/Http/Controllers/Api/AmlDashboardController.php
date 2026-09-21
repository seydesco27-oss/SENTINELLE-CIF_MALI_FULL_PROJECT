<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class AmlDashboardController extends Controller
{
    /**
     * =========================================================
     * DASHBOARD AML — SUMMARY
     * =========================================================
     *
     * GET /api/v1/dashboard/summary
     *
     * Exploite :
     * - v_aml_dashboard
     * - v_management_dashboard
     */
    public function summary(): JsonResponse
    {
        try {
            $aml = DB::table('v_aml_dashboard')->first();

            $management = DB::table('v_management_dashboard')->first();

            if (!$aml) {
                return response()->json([
                    'success' => false,
                    'message' => 'Les données du dashboard AML sont indisponibles.',
                ], 500);
            }

            $totalClients = (int) ($aml->total_clients ?? 0);
            $riskyClients = (int) ($aml->risky_clients ?? 0);
            $totalAlerts = (int) ($aml->total_alerts ?? 0);
            $openAlerts = (int) ($aml->open_alerts ?? 0);

            $closedAlerts = max(
                0,
                $totalAlerts - $openAlerts
            );

            $riskRate = $totalClients > 0
                ? round(
                    ($riskyClients / $totalClients) * 100,
                    2
                )
                : 0;

            $openAlertRate = $totalAlerts > 0
                ? round(
                    ($openAlerts / $totalAlerts) * 100,
                    2
                )
                : 0;

            return response()->json([
                'success' => true,

                'data' => [
                    'clients' => [
                        'total' => $totalClients,
                        'risky' => $riskyClients,
                        'risk_rate' => $riskRate,
                    ],

                    'alerts' => [
                        'total' => $totalAlerts,
                        'open' => $openAlerts,
                        'closed_or_resolved' => $closedAlerts,
                        'open_rate' => $openAlertRate,
                    ],

                    'management' => $management,
                ],
            ]);

        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération du résumé AML.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * =========================================================
     * DISTRIBUTION DES RISQUES CLIENTS
     * =========================================================
     *
     * GET /api/v1/dashboard/risk-distribution
     */
    public function riskDistribution(): JsonResponse
    {
        try {
            $distribution = DB::table('risk_levels as rl')
                ->leftJoin(
                    'clients as c',
                    'c.risk_level_id',
                    '=',
                    'rl.id'
                )
                ->select([
                    'rl.id',
                    'rl.code',
                    'rl.label',
                    'rl.score_min',
                    'rl.score_max',
                    'rl.description',
                    DB::raw('COUNT(c.id) AS client_count'),
                ])
                ->groupBy(
                    'rl.id',
                    'rl.code',
                    'rl.label',
                    'rl.score_min',
                    'rl.score_max',
                    'rl.description'
                )
                ->orderBy('rl.score_min')
                ->get();

            $totalClients = (int) $distribution->sum(
                'client_count'
            );

            $distribution = $distribution
                ->map(function ($row) use ($totalClients) {

                    $count = (int) $row->client_count;

                    $percentage = $totalClients > 0
                        ? round(
                            ($count / $totalClients) * 100,
                            2
                        )
                        : 0;

                    return [
                        'id' => $row->id,
                        'code' => $row->code,
                        'label' => $row->label,
                        'score_min' => $row->score_min,
                        'score_max' => $row->score_max,
                        'client_count' => $count,
                        'percentage' => $percentage,
                    ];
                })
                ->values();

            $highCritical = $distribution
                ->filter(function ($row) {
                    return in_array(
                        strtoupper((string) $row['code']),
                        ['HIGH', 'CRITICAL'],
                        true
                    );
                })
                ->sum('client_count');

            return response()->json([
                'success' => true,

                'data' => [
                    'total_clients' => $totalClients,

                    'high_and_critical_clients' =>
                        (int) $highCritical,

                    'distribution' => $distribution,
                ],
            ]);

        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' =>
                    'Erreur lors de la récupération de la distribution des risques.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * =========================================================
     * ACTIVITÉ DES ALERTES
     * =========================================================
     *
     * GET /api/v1/dashboard/alerts
     *
     * ?limit=10
     */
    public function alerts(Request $request): JsonResponse
    {
        try {
            $limit = min(
                max(
                    (int) $request->query('limit', 10),
                    1
                ),
                50
            );

            /*
             * Répartition par statut.
             */
            $byStatus = DB::table('alerts')
                ->select([
                    'status',
                    DB::raw('COUNT(*) AS count'),
                ])
                ->groupBy('status')
                ->orderByDesc('count')
                ->get();

            /*
             * Répartition par priorité.
             */
            $byPriority = DB::table('alerts')
                ->select([
                    'priority',
                    DB::raw('COUNT(*) AS count'),
                ])
                ->groupBy('priority')
                ->orderByDesc('count')
                ->get();

            /*
             * Répartition par type AML.
             */
            $byType = DB::table('alerts')
                ->select([
                    'alert_type',
                    DB::raw('COUNT(*) AS count'),
                ])
                ->groupBy('alert_type')
                ->orderByDesc('count')
                ->get();

            /*
             * Dernières alertes.
             */
            $recent = DB::table('alerts as a')
                ->leftJoin(
                    'clients as c',
                    'c.id',
                    '=',
                    'a.client_id'
                )
                ->leftJoin(
                    'client_individuals as ci',
                    'ci.client_id',
                    '=',
                    'c.id'
                )
                ->leftJoin(
                    'client_entities as ce',
                    'ce.client_id',
                    '=',
                    'c.id'
                )
                ->select([
                    'a.id',
                    'a.reference',
                    'a.client_id',
                    'a.transaction_id',
                    'a.alert_type',
                    'a.priority',
                    'a.status',
                    'a.final_score',
                    'a.title',
                    'a.description',
                    'a.created_at',
                    'c.client_number',

                    DB::raw("
                        CASE
                            WHEN ci.client_id IS NOT NULL
                                THEN TRIM(
                                    CONCAT(
                                        COALESCE(ci.first_name, ''),
                                        ' ',
                                        COALESCE(ci.last_name, '')
                                    )
                                )
                            WHEN ce.client_id IS NOT NULL
                                THEN ce.legal_name
                            ELSE CONCAT('CLIENT #', c.id)
                        END AS client_name
                    "),
                ])
                ->orderByDesc('a.created_at')
                ->orderByDesc('a.id')
                ->limit($limit)
                ->get();

            /*
             * Indicateurs rapides.
             */
            $total = DB::table('alerts')->count();

            $open = DB::table('alerts')
                ->where('status', 'OPEN')
                ->count();

            $highPriority = DB::table('alerts')
                ->whereIn(
                    DB::raw('UPPER(priority)'),
                    ['HIGH', 'CRITICAL']
                )
                ->count();

            $amlRuleEngine = DB::table('alerts')
                ->where(
                    'alert_type',
                    'AML_RULE_ENGINE'
                )
                ->count();

            return response()->json([
                'success' => true,

                'data' => [
                    'summary' => [
                        'total' => $total,
                        'open' => $open,
                        'high_priority' => $highPriority,
                        'aml_rule_engine' => $amlRuleEngine,
                    ],

                    'by_status' => $byStatus,

                    'by_priority' => $byPriority,

                    'by_type' => $byType,

                    'recent' => $recent,
                ],
            ]);

        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' =>
                    'Erreur lors de la récupération des statistiques d’alertes.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * =========================================================
     * ACTIVITÉ TRANSACTIONNELLE
     * =========================================================
     *
     * GET /api/v1/dashboard/transactions
     *
     * ?limit=10
     */
    public function transactions(Request $request): JsonResponse
    {
        try {
            $limit = min(
                max(
                    (int) $request->query('limit', 10),
                    1
                ),
                50
            );

            /*
             * Statistiques générales.
             */
            $stats = DB::table('transactions')
                ->selectRaw(
                    'COUNT(*) AS total'
                )
                ->selectRaw(
                    "
                    SUM(
                        CASE
                            WHEN UPPER(
                                COALESCE(
                                    transaction_status,
                                    'VALID'
                                )
                            ) NOT IN (
                                'CANCELLED',
                                'CANCELED',
                                'REVERSED',
                                'VOID'
                            )
                            THEN 1
                            ELSE 0
                        END
                    ) AS valid_count
                    "
                )
                ->selectRaw(
                    "
                    COALESCE(
                        SUM(
                            CASE
                                WHEN UPPER(
                                    COALESCE(
                                        transaction_status,
                                        'VALID'
                                    )
                                ) NOT IN (
                                    'CANCELLED',
                                    'CANCELED',
                                    'REVERSED',
                                    'VOID'
                                )
                                THEN amount
                                ELSE 0
                            END
                        ),
                        0
                    ) AS total_volume
                    "
                )
                ->selectRaw(
                    "
                    COALESCE(
                        AVG(
                            CASE
                                WHEN UPPER(
                                    COALESCE(
                                        transaction_status,
                                        'VALID'
                                    )
                                ) NOT IN (
                                    'CANCELLED',
                                    'CANCELED',
                                    'REVERSED',
                                    'VOID'
                                )
                                THEN amount
                            END
                        ),
                        0
                    ) AS average_amount
                    "
                )
                ->first();

            /*
             * Répartition par statut.
             */
            $byStatus = DB::table('transactions')
                ->select([
                    'transaction_status',
                    DB::raw('COUNT(*) AS count'),
                ])
                ->groupBy('transaction_status')
                ->orderByDesc('count')
                ->get();

            /*
             * Répartition par canal.
             */
            $byChannel = DB::table('transactions')
                ->select([
                    'channel',
                    DB::raw('COUNT(*) AS count'),
                    DB::raw(
                        'COALESCE(SUM(amount), 0) AS volume'
                    ),
                ])
                ->groupBy('channel')
                ->orderByDesc('count')
                ->get();

            /*
             * Dernières transactions.
             */
            $recent = DB::table('transactions as t')
                ->leftJoin(
                    'accounts as a',
                    'a.id',
                    '=',
                    't.account_id'
                )
                ->leftJoin(
                    'clients as c',
                    'c.id',
                    '=',
                    'a.client_id'
                )
                ->leftJoin(
                    'client_individuals as ci',
                    'ci.client_id',
                    '=',
                    'c.id'
                )
                ->leftJoin(
                    'client_entities as ce',
                    'ce.client_id',
                    '=',
                    'c.id'
                )
                ->select([
                    't.id',
                    't.transaction_reference',
                    't.account_id',
                    'a.account_number',
                    'a.account_type',
                    'a.client_id',
                    'c.client_number',
                    'c.client_type',
                    't.transaction_type',
                    't.amount',
                    't.currency',
                    't.channel',
                    't.country',
                    't.country_from',
                    't.country_to',
                    't.transaction_date',
                    't.transaction_status',

                    DB::raw("
                        CASE
                            WHEN ci.client_id IS NOT NULL
                                THEN TRIM(
                                    CONCAT(
                                        COALESCE(ci.first_name, ''),
                                        ' ',
                                        COALESCE(ci.last_name, '')
                                    )
                                )
                            WHEN ce.client_id IS NOT NULL
                                THEN ce.legal_name
                            ELSE CONCAT('CLIENT #', c.id)
                        END AS client_name
                    "),
                ])
                ->orderByDesc('t.transaction_date')
                ->orderByDesc('t.id')
                ->limit($limit)
                ->get();

            /*
             * On exploite directement la vue AML existante
             * pour les transactions suspectes.
             */
            $suspiciousStats = DB::table(
                'v_suspicious_transactions'
            )
                ->selectRaw(
                    'COUNT(*) AS suspicious_count'
                )
                ->selectRaw(
                    "
                    COALESCE(
                        SUM(amount),
                        0
                    ) AS suspicious_volume
                    "
                )
                ->selectRaw(
                    "
                    COALESCE(
                        SUM(
                            CASE
                                WHEN UPPER(
                                    COALESCE(
                                        aml_risk_level,
                                        ''
                                    )
                                ) IN (
                                    'HIGH',
                                    'CRITICAL'
                                )
                                THEN 1
                                ELSE 0
                            END
                        ),
                        0
                    ) AS high_risk_suspicious_count
                    "
                )
                ->first();

            return response()->json([
                'success' => true,

                'data' => [
                    'summary' => [
                        'total' =>
                            (int) ($stats->total ?? 0),

                        'valid_count' =>
                            (int) ($stats->valid_count ?? 0),

                        'total_volume' =>
                            $stats->total_volume ?? 0,

                        'average_amount' =>
                            $stats->average_amount ?? 0,

                        'suspicious_count' =>
                            (int) (
                                $suspiciousStats->suspicious_count
                                ?? 0
                            ),

                        'suspicious_volume' =>
                            $suspiciousStats->suspicious_volume
                            ?? 0,

                        'high_risk_suspicious_count' =>
                            (int) (
                                $suspiciousStats
                                    ->high_risk_suspicious_count
                                ?? 0
                            ),
                    ],

                    'by_status' => $byStatus,

                    'by_channel' => $byChannel,

                    'recent' => $recent,
                ],
            ]);

        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' =>
                    'Erreur lors de la récupération des statistiques transactionnelles.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }



        /**
     * ============================================================
     * TENDANCE DES ALERTES SUR N JOURS
     * ============================================================
     *
     * GET /api/v1/dashboard/alerts-trend?days=7
     *
     * Une seule requête, sans aucun JOIN : agrégation directe sur
     * alerts.created_at. Le volume de la table alerts (quelques
     * milliers de lignes, pas des centaines de milliers comme
     * transactions) rend ce GROUP BY sûr sans sous-requêtes ni
     * vue dédiée.
     */
    public function alertsTrend(Request $request): JsonResponse
    {
        try {
            $days = min(max((int) $request->query('days', 7), 1), 90);

            $startDate = now()->subDays($days - 1)->startOfDay();

            $rows = DB::table('alerts')
                ->select([
                    DB::raw('DATE(created_at) as day'),
                    DB::raw('COUNT(*) as alerts'),
                    DB::raw("SUM(CASE WHEN priority = 'CRITICAL' THEN 1 ELSE 0 END) as critical"),
                    DB::raw("SUM(CASE WHEN priority = 'HIGH' THEN 1 ELSE 0 END) as high"),
                ])
                ->where('created_at', '>=', $startDate)
                ->groupBy(DB::raw('DATE(created_at)'))
                ->orderBy('day')
                ->get()
                ->keyBy('day');

            /*
             * Complète les jours sans aucune alerte avec des zéros
             * explicites, plutôt que de laisser un trou dans la
             * série — un graphique ne doit jamais avoir à deviner
             * un jour manquant.
             */
            $series = [];

            for ($i = 0; $i < $days; $i++) {
                $date = now()->subDays($days - 1 - $i)->format('Y-m-d');
                $row = $rows->get($date);

                $series[] = [
                    'date' => $date,
                    'alerts' => $row ? (int) $row->alerts : 0,
                    'critical' => $row ? (int) $row->critical : 0,
                    'high' => $row ? (int) $row->high : 0,
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $series,
            ]);

        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du calcul de la tendance des alertes.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

}