<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class AlertController extends Controller
{
    /**
     * Liste globale des alertes AML.
     *
     * GET /api/v1/alerts
     *
     * Filtres disponibles :
     * ?status=OPEN
     * ?priority=HIGH
     * ?alert_type=AML_RULE_ENGINE
     * ?client_id=1
     * ?transaction_id=10
     * ?search=AML
     * ?limit=50
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $limit = min(
                max((int) $request->query('limit', 50), 1),
                100
            );

            $query = DB::table('alerts as a')
                ->leftJoin('clients as c', 'c.id', '=', 'a.client_id')
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
                ->leftJoin(
                    'transactions as t',
                    't.id',
                    '=',
                    'a.transaction_id'
                )
                ->leftJoin(
                    'accounts as acc',
                    'acc.id',
                    '=',
                    't.account_id'
                )
                ->leftJoin('agencies as ag', 'ag.id', '=', 't.agency_id')
                ->leftJoin('caisses as ca', 'ca.id', '=', 'ag.caisse_id')
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
                    'c.client_type',
                    'c.is_pep',
                    'c.risk_score',

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

                    't.transaction_reference',
                    't.transaction_type',
                    't.amount',
                    't.currency',
                    't.channel',
                    't.country',
                    't.country_from',
                    't.country_to',
                    't.transaction_date',
                    't.transaction_status',

                    'acc.account_number',
                    'acc.account_type',
                ]);

            if ($request->filled('status')) {
                $query->where(
                    'a.status',
                    strtoupper($request->query('status'))
                );
            }

            if ($request->filled('priority')) {
                $query->where(
                    'a.priority',
                    strtoupper($request->query('priority'))
                );
            }

            if ($request->filled('alert_type')) {
                $query->where(
                    'a.alert_type',
                    $request->query('alert_type')
                );
            }

            if ($request->filled('client_id')) {
                $query->where(
                    'a.client_id',
                    (int) $request->query('client_id')
                );
            }

            if ($request->filled('transaction_id')) {
                $query->where(
                    'a.transaction_id',
                    (int) $request->query('transaction_id')
                );
            }

            if ($request->filled('search')) {
                $search = trim($request->query('search'));

                $query->where(function ($q) use ($search) {
                    $q->where('a.reference', 'LIKE', "%{$search}%")
                        ->orWhere('a.title', 'LIKE', "%{$search}%")
                        ->orWhere('a.alert_type', 'LIKE', "%{$search}%")
                        ->orWhere('c.client_number', 'LIKE', "%{$search}%")
                        ->orWhere('t.transaction_reference', 'LIKE', "%{$search}%")
                        ->orWhere('ci.first_name', 'LIKE', "%{$search}%")
                        ->orWhere('ci.last_name', 'LIKE', "%{$search}%")
                        ->orWhere('ce.legal_name', 'LIKE', "%{$search}%");
                });
            }

            $alerts = $query
                ->orderByDesc('a.created_at')
                ->orderByDesc('a.id')
                ->limit($limit)
                ->get();

            return response()->json([
                'success' => true,
                'count' => $alerts->count(),
                'data' => $alerts,
            ]);

        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des alertes.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Détail complet d'une alerte.
     *
     * GET /api/v1/alerts/{id}
     */
    public function show(int $id): JsonResponse
    {
        try {
            $alert = DB::table('alerts as a')
                ->leftJoin('clients as c', 'c.id', '=', 'a.client_id')
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
                ->leftJoin(
                    'transactions as t',
                    't.id',
                    '=',
                    'a.transaction_id'
                )
                ->leftJoin(
                    'accounts as acc',
                    'acc.id',
                    '=',
                    't.account_id'
                )
                ->leftJoin(
                    'agencies as ag',
                    'ag.id',
                    '=',
                    't.agency_id'
                )
                ->leftJoin(
                    'caisses as ca',
                    'ca.id',
                    '=',
                    'ag.caisse_id'
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
                    'c.client_type',
                    'c.status as client_status',
                    'c.phone',
                    'c.email',
                    'c.is_pep',
                    'c.risk_score',

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

                    'ci.first_name',
                    'ci.last_name',
                    'ci.gender',
                    'ci.birth_date',
                    'ci.nationality',
                    'ci.profession',

                    'ce.legal_name',
                    'ce.entity_type',
                    'ce.nationality as entity_nationality',
                    'ce.activity_sector',

                    't.transaction_reference',
                    't.transaction_type',
                    't.amount',
                    't.currency',
                    't.channel',
                    't.country',
                    't.country_from',
                    't.country_to',
                    't.transaction_date',
                    't.transaction_status',
                    't.status_changed_at',
                    't.cancellation_reason',

                    'acc.id as account_id',
                    'acc.account_number',
                    'acc.account_type',
                    'acc.current_balance as account_current_balance',
                    'acc.currency as account_currency',
                    'acc.status as account_status',
                    'acc.opened_at',

                    't.agency_id',
                    'ag.code as agency_code',
                    'ag.name as agency_name',
                    'ag.city as agency_city',
                    'ag.caisse_id',
                    'ca.code as caisse_code',
                    'ca.name as caisse_name',
                    'ca.city as caisse_city',
                ])
                ->where('a.id', $id)
                ->first();

            if (!$alert) {
                return response()->json([
                    'success' => false,
                    'message' => 'Alerte introuvable.',
                ], 404);
            }

            /*
             * Actions réalisées sur l'alerte.
             */
            $actions = DB::table('alert_actions as aa')
                ->leftJoin('users as u', 'u.id', '=', 'aa.user_id')
                ->select([
                    'aa.id',
                    'aa.alert_id',
                    'aa.user_id',
                    'u.username',
                    'aa.action_type',
                    'aa.comment',
                    'aa.created_at',
                ])
                ->where('aa.alert_id', $id)
                ->orderByDesc('aa.created_at')
                ->get();

            /*
             * Investigations associées.
             */
            $investigations = DB::table('investigations as i')
                ->leftJoin('users as u', 'u.id', '=', 'i.assigned_user')
                ->select([
                    'i.id',
                    'i.alert_id',
                    'i.assigned_user',
                    'u.username as assigned_username',
                    'i.decision',
                    'i.comment',
                    'i.started_at',
                    'i.closed_at',
                ])
                ->where('i.alert_id', $id)
                ->orderByDesc('i.started_at')
                ->get();

            /*
             * Évaluations de risque liées à la transaction.
             */
            $riskAssessments = collect();

            if ($alert->transaction_id !== null) {
                $riskAssessments = DB::table('risk_assessments')
                    ->select([
                        'id',
                        'client_id',
                        'transaction_id',
                        'risk_type',
                        'score',
                        'risk_level',
                        'reason',
                        'source',
                        'created_at',
                    ])
                    ->where('transaction_id', $alert->transaction_id)
                    ->orderByDesc('score')
                    ->orderByDesc('created_at')
                    ->get();
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'alert' => $alert,
                    'actions' => $actions,
                    'investigations' => $investigations,
                    'risk_assessments' => $riskAssessments,
                ],
            ]);

        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération du détail de l’alerte.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Risque associé à une alerte.
     *
     * GET /api/v1/alerts/{id}/risk
     */
    public function risk(int $id): JsonResponse
    {
        try {
            $alert = DB::table('alerts')
                ->select([
                    'id',
                    'client_id',
                    'transaction_id',
                    'alert_type',
                    'priority',
                    'status',
                    'final_score',
                ])
                ->where('id', $id)
                ->first();

            if (!$alert) {
                return response()->json([
                    'success' => false,
                    'message' => 'Alerte introuvable.',
                ], 404);
            }

            $assessments = DB::table('risk_assessments')
                ->select([
                    'id',
                    'client_id',
                    'transaction_id',
                    'risk_type',
                    'score',
                    'risk_level',
                    'reason',
                    'source',
                    'created_at',
                ])
                ->where(function ($query) use ($alert) {
                    $query->where('transaction_id', $alert->transaction_id)
                        ->orWhere('client_id', $alert->client_id);
                })
                ->orderByDesc('score')
                ->orderByDesc('created_at')
                ->get();

            $summary = [
                'alert_score' => $alert->final_score,
                'alert_priority' => $alert->priority,
                'risk_assessment_count' => $assessments->count(),
                'max_assessment_score' => $assessments->max('score'),
                'risk_levels' => $assessments
                    ->pluck('risk_level')
                    ->filter()
                    ->unique()
                    ->values(),
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'summary' => $summary,
                    'assessments' => $assessments,
                ],
            ]);

        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération du risque.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Actions d'une alerte.
     *
     * GET /api/v1/alerts/{id}/actions
     */
    public function actions(int $id): JsonResponse
    {
        try {
            if (!DB::table('alerts')->where('id', $id)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Alerte introuvable.',
                ], 404);
            }

            $actions = DB::table('alert_actions as aa')
                ->leftJoin('users as u', 'u.id', '=', 'aa.user_id')
                ->select([
                    'aa.id',
                    'aa.alert_id',
                    'aa.user_id',
                    'u.username',
                    'aa.action_type',
                    'aa.comment',
                    'aa.created_at',
                ])
                ->where('aa.alert_id', $id)
                ->orderByDesc('aa.created_at')
                ->get();

            return response()->json([
                'success' => true,
                'count' => $actions->count(),
                'data' => $actions,
            ]);

        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des actions.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Investigations d'une alerte.
     *
     * GET /api/v1/alerts/{id}/investigations
     */
    public function investigations(int $id): JsonResponse
    {
        try {
            if (!DB::table('alerts')->where('id', $id)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Alerte introuvable.',
                ], 404);
            }

            $investigations = DB::table('investigations as i')
                ->leftJoin('users as u', 'u.id', '=', 'i.assigned_user')
                ->select([
                    'i.id',
                    'i.alert_id',
                    'i.assigned_user',
                    'u.username as assigned_username',
                    'i.decision',
                    'i.comment',
                    'i.started_at',
                    'i.closed_at',
                ])
                ->where('i.alert_id', $id)
                ->orderByDesc('i.started_at')
                ->get();

            return response()->json([
                'success' => true,
                'count' => $investigations->count(),
                'data' => $investigations,
            ]);

        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des investigations.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    
    
    /**
     * ============================================================
     * CLIENT ASSOCIÉ À UNE ALERTE
     * ============================================================
     *
     * GET /api/v1/alerts/{id}/client
     */
    public function client(int $id): JsonResponse
    {
        try {
            $alert = DB::table('alerts')
                ->where('id', $id)
                ->first();

            if (!$alert) {
                return response()->json([
                    'success' => false,
                    'message' => 'Alerte introuvable.',
                ], 404);
            }

            if (!$alert->client_id) {
                return response()->json([
                    'success' => true,
                    'data' => null,
                    'message' => 'Aucun client associé à cette alerte.',
                ]);
            }

            /*
             * On exploite le profil AML existant.
             */
            $client = DB::table('v_customer_aml_profile')
                ->where('client_id', $alert->client_id)
                ->first();

            /*
             * Si le profil enrichi n'existe pas,
             * on récupère au minimum le client principal.
             */
            if (!$client) {
                $client = DB::table('clients')
                    ->where('id', $alert->client_id)
                    ->first();
            }

            return response()->json([
                'success' => true,

                'alert_id' => $id,

                'data' => $client,
            ]);

        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération du client associé.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * ============================================================
     * TRANSACTION ASSOCIÉE À UNE ALERTE
     * ============================================================
     *
     * GET /api/v1/alerts/{id}/transaction
     */
    public function transaction(int $id): JsonResponse
    {
        try {
            $alert = DB::table('alerts')
                ->where('id', $id)
                ->first();

            if (!$alert) {
                return response()->json([
                    'success' => false,
                    'message' => 'Alerte introuvable.',
                ], 404);
            }

            if (!$alert->transaction_id) {
                return response()->json([
                    'success' => true,
                    'data' => null,
                    'message' => 'Aucune transaction associée à cette alerte.',
                ]);
            }

            /*
             * On exploite directement la vue transactionnelle
             * enrichie par les features AML/ML.
             */
            $transaction = DB::table('v_ml_transaction_features')
                ->where('transaction_id', $alert->transaction_id)
                ->first();

            /*
             * Fallback vers la table transactions.
             */
            if (!$transaction) {
                $transaction = DB::table('transactions')
                    ->where('id', $alert->transaction_id)
                    ->first();
            }

            return response()->json([
                'success' => true,

                'alert_id' => $id,

                'data' => $transaction,
            ]);

        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération de la transaction associée.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

           /**
     * ============================================================
     * ALERTES OUVERTES
     * ============================================================
     *
     * GET /api/v1/alerts/open
     *
     * Retourne les alertes actuellement ouvertes.
     *
     * Filtres :
     * ?priority=HIGH
     * ?alert_type=AML_RULE_ENGINE
     * ?client_id=1
     * ?transaction_id=10
     * ?search=AML
     * ?limit=50
     */
    public function open(Request $request): JsonResponse
    {
        try {
            $limit = min(
                max((int) $request->query('limit', 50), 1),
                100
            );

            $query = DB::table('alerts as a')
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
                ->leftJoin(
                    'transactions as t',
                    't.id',
                    '=',
                    'a.transaction_id'
                )
                ->leftJoin(
                    'accounts as acc',
                    'acc.id',
                    '=',
                    't.account_id'
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
                    'c.client_type',
                    'c.is_pep',
                    'c.risk_score',

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

                            ELSE CONCAT(
                                'CLIENT #',
                                c.id
                            )
                        END AS client_name
                    "),

                    't.transaction_reference',
                    't.transaction_type',
                    't.amount',
                    't.currency',
                    't.channel',
                    't.country',
                    't.country_from',
                    't.country_to',
                    't.transaction_date',
                    't.transaction_status',

                    'acc.id as account_id',
                    'acc.account_number',
                    'acc.account_type',
                ])
                ->where('a.status', 'OPEN');

            /*
             * ----------------------------------------------------
             * FILTRE PRIORITE
             * ----------------------------------------------------
             */
            if ($request->filled('priority')) {
                $query->where(
                    'a.priority',
                    strtoupper(
                        trim(
                            $request->query('priority')
                        )
                    )
                );
            }

            /*
             * ----------------------------------------------------
             * FILTRE TYPE
             * ----------------------------------------------------
             */
            if ($request->filled('alert_type')) {
                $query->where(
                    'a.alert_type',
                    trim(
                        $request->query('alert_type')
                    )
                );
            }

            /*
             * ----------------------------------------------------
             * FILTRE CLIENT
             * ----------------------------------------------------
             */
            if ($request->filled('client_id')) {
                $query->where(
                    'a.client_id',
                    (int) $request->query('client_id')
                );
            }

            /*
             * ----------------------------------------------------
             * FILTRE TRANSACTION
             * ----------------------------------------------------
             */
            if ($request->filled('transaction_id')) {
                $query->where(
                    'a.transaction_id',
                    (int) $request->query('transaction_id')
                );
            }

            /*
             * ----------------------------------------------------
             * RECHERCHE
             * ----------------------------------------------------
             */
            if ($request->filled('search')) {
                $search = trim(
                    $request->query('search')
                );

                $query->where(function ($q) use ($search) {

                    $q->where(
                        'a.reference',
                        'LIKE',
                        "%{$search}%"
                    )

                    ->orWhere(
                        'a.title',
                        'LIKE',
                        "%{$search}%"
                    )

                    ->orWhere(
                        'a.alert_type',
                        'LIKE',
                        "%{$search}%"
                    )

                    ->orWhere(
                        'a.description',
                        'LIKE',
                        "%{$search}%"
                    )

                    ->orWhere(
                        'c.client_number',
                        'LIKE',
                        "%{$search}%"
                    )

                    ->orWhere(
                        't.transaction_reference',
                        'LIKE',
                        "%{$search}%"
                    )

                    ->orWhere(
                        'ci.first_name',
                        'LIKE',
                        "%{$search}%"
                    )

                    ->orWhere(
                        'ci.last_name',
                        'LIKE',
                        "%{$search}%"
                    )

                    ->orWhere(
                        'ce.legal_name',
                        'LIKE',
                        "%{$search}%"
                    );
                });
            }

            /*
             * ----------------------------------------------------
             * TOTAL AVANT LIMIT
             * ----------------------------------------------------
             */
            $total = (clone $query)->count('a.id');

            /*
             * ----------------------------------------------------
             * DONNEES
             * ----------------------------------------------------
             */
            $alerts = $query
                ->orderByDesc('a.priority')
                ->orderByDesc('a.final_score')
                ->orderByDesc('a.created_at')
                ->orderByDesc('a.id')
                ->limit($limit)
                ->get();

            return response()->json([
                'success' => true,

                'filters' => [
                    'status' => 'OPEN',
                    'priority' =>
                        $request->query('priority'),
                    'alert_type' =>
                        $request->query('alert_type'),
                    'client_id' =>
                        $request->query('client_id'),
                    'transaction_id' =>
                        $request->query('transaction_id'),
                    'search' =>
                        $request->query('search'),
                    'limit' => $limit,
                ],

                'count' => $alerts->count(),

                'total' => $total,

                'data' => $alerts,
            ]);

        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'message' =>
                    'Erreur lors de la récupération des alertes ouvertes.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * ============================================================
     * ALERTES À RISQUE ÉLEVÉ
     * ============================================================
     *
     * GET /api/v1/alerts/high-risk
     *
     * Critère conforme au moteur AML :
     *
     * CRITICAL >= 80
     * HIGH     >= 60
     *
     * Par défaut :
     * - HIGH
     * - CRITICAL
     *
     * Le statut peut être contrôlé avec :
     *
     * ?status=OPEN
     *
     * ou :
     *
     * ?status=ALL
     *
     * Filtres supplémentaires :
     * ?alert_type=AML_RULE_ENGINE
     * ?client_id=1
     * ?transaction_id=10
     * ?search=AML
     * ?limit=50
     */
    public function highRisk(Request $request): JsonResponse
    {
        try {
            $limit = min(
                max((int) $request->query('limit', 50), 1),
                100
            );

            $query = DB::table('alerts as a')
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
                ->leftJoin(
                    'transactions as t',
                    't.id',
                    '=',
                    'a.transaction_id'
                )
                ->leftJoin(
                    'accounts as acc',
                    'acc.id',
                    '=',
                    't.account_id'
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
                    'c.client_type',
                    'c.is_pep',
                    'c.risk_score',

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

                            ELSE CONCAT(
                                'CLIENT #',
                                c.id
                            )
                        END AS client_name
                    "),

                    't.transaction_reference',
                    't.transaction_type',
                    't.amount',
                    't.currency',
                    't.channel',
                    't.country',
                    't.country_from',
                    't.country_to',
                    't.transaction_date',
                    't.transaction_status',

                    'acc.id as account_id',
                    'acc.account_number',
                    'acc.account_type',
                ])

                /*
                 * =================================================
                 * RISQUE ÉLEVÉ
                 *
                 * Le moteur AML utilise :
                 *
                 * >= 60 HIGH
                 * >= 80 CRITICAL
                 * =================================================
                 */
                ->where(function ($q) {
                    $q->where(
                        'a.final_score',
                        '>=',
                        60
                    )

                    ->orWhereIn(
                        DB::raw('UPPER(a.priority)'),
                        [
                            'HIGH',
                            'CRITICAL',
                        ]
                    );
                });

            /*
             * ----------------------------------------------------
             * STATUT
             *
             * Par défaut : OPEN
             * ALL permet d'obtenir également les alertes clôturées.
             * ----------------------------------------------------
             */
            $status = strtoupper(
                trim(
                    $request->query(
                        'status',
                        'OPEN'
                    )
                )
            );

            if ($status !== 'ALL') {
                $query->where(
                    'a.status',
                    $status
                );
            }

            /*
             * ----------------------------------------------------
             * TYPE
             * ----------------------------------------------------
             */
            if ($request->filled('alert_type')) {
                $query->where(
                    'a.alert_type',
                    trim(
                        $request->query('alert_type')
                    )
                );
            }

            /*
             * ----------------------------------------------------
             * CLIENT
             * ----------------------------------------------------
             */
            if ($request->filled('client_id')) {
                $query->where(
                    'a.client_id',
                    (int) $request->query('client_id')
                );
            }

            /*
             * ----------------------------------------------------
             * TRANSACTION
             * ----------------------------------------------------
             */
            if ($request->filled('transaction_id')) {
                $query->where(
                    'a.transaction_id',
                    (int) $request->query('transaction_id')
                );
            }

            /*
             * ----------------------------------------------------
             * RECHERCHE
             * ----------------------------------------------------
             */
            if ($request->filled('search')) {

                $search = trim(
                    $request->query('search')
                );

                $query->where(function ($q) use ($search) {

                    $q->where(
                        'a.reference',
                        'LIKE',
                        "%{$search}%"
                    )

                    ->orWhere(
                        'a.title',
                        'LIKE',
                        "%{$search}%"
                    )

                    ->orWhere(
                        'a.alert_type',
                        'LIKE',
                        "%{$search}%"
                    )

                    ->orWhere(
                        'a.description',
                        'LIKE',
                        "%{$search}%"
                    )

                    ->orWhere(
                        'c.client_number',
                        'LIKE',
                        "%{$search}%"
                    )

                    ->orWhere(
                        't.transaction_reference',
                        'LIKE',
                        "%{$search}%"
                    )

                    ->orWhere(
                        'ci.first_name',
                        'LIKE',
                        "%{$search}%"
                    )

                    ->orWhere(
                        'ci.last_name',
                        'LIKE',
                        "%{$search}%"
                    )

                    ->orWhere(
                        'ce.legal_name',
                        'LIKE',
                        "%{$search}%"
                    );
                });
            }

            /*
             * ----------------------------------------------------
             * TOTAL
             * ----------------------------------------------------
             */
            $total = (clone $query)->count(
                'a.id'
            );

            // Le total affiché sur le tableau de bord ne doit pas dépendre
            // de la limite de lignes renvoyées dans la file d’intervention.
            $criticalTotal = (clone $query)
                ->where(function ($criticalQuery) {
                    $criticalQuery->whereRaw("UPPER(a.priority) = 'CRITICAL'")
                        ->orWhere('a.final_score', '>=', 80);
                })
                ->count('a.id');

            /*
             * ----------------------------------------------------
             * DONNEES
             * ----------------------------------------------------
             */
            $alerts = $query
                ->orderByDesc('a.final_score')
                ->orderByRaw("
                    CASE
                        WHEN UPPER(a.priority) = 'CRITICAL'
                            THEN 1
                        WHEN UPPER(a.priority) = 'HIGH'
                            THEN 2
                        ELSE 3
                    END
                ")
                ->orderByDesc('a.created_at')
                ->orderByDesc('a.id')
                ->limit($limit)
                ->get();

            /*
             * ----------------------------------------------------
             * RESUME
             * ----------------------------------------------------
             */
            $critical = $alerts
                ->filter(function ($alert) {
                    return strtoupper(
                        (string) $alert->priority
                    ) === 'CRITICAL'
                    || (float) (
                        $alert->final_score ?? 0
                    ) >= 80;
                })
                ->count();

            $high = $alerts
                ->filter(function ($alert) {
                    $priority = strtoupper(
                        (string) $alert->priority
                    );

                    $score = (float) (
                        $alert->final_score ?? 0
                    );

                    return (
                        $priority === 'HIGH'
                        || (
                            $score >= 60
                            && $score < 80
                        )
                    );
                })
                ->count();

            return response()->json([
                'success' => true,

                'filters' => [
                    'status' => $status,
                    'alert_type' =>
                        $request->query('alert_type'),
                    'client_id' =>
                        $request->query('client_id'),
                    'transaction_id' =>
                        $request->query('transaction_id'),
                    'search' =>
                        $request->query('search'),
                    'limit' => $limit,
                ],

                'summary' => [
                    'total' => $total,
                    'returned' => $alerts->count(),
                    'critical_total' => $criticalTotal,
                    'critical_returned' => $critical,
                    'high_returned' => $high,
                ],

                'data' => $alerts,
            ]);

        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'message' =>
                    'Erreur lors de la récupération des alertes à risque élevé.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

        /**
     * ============================================================
     * DÉCISION SUR UNE ALERTE
     * ============================================================
     *
     * PATCH /api/v1/alerts/{id}/decision
     *
     * Corps attendu :
     * {
     *   "decision": "CONFIRMED_SUSPICIOUS" | "DISMISSED",
     *   "comment": "string (obligatoire, 10 caractères minimum)"
     * }
     *
     * Effets :
     * - alerts.status passe à CLOSED
     * - une ligne est ajoutée dans alert_actions, tracée et horodatée
     */
    public function decision(Request $request, int $id): JsonResponse
    {
        try {
            $alert = DB::table('alerts')->where('id', $id)->first();

            if (!$alert) {
                return response()->json([
                    'success' => false,
                    'message' => 'Alerte introuvable.',
                ], 404);
            }

            if (strtoupper((string) $alert->status) === 'CLOSED') {
                return response()->json([
                    'success' => false,
                    'message' => 'Cette alerte est déjà clôturée.',
                ], 409);
            }

            $decision = strtoupper(trim((string) $request->input('decision')));
            $comment = trim((string) $request->input('comment'));

            if (!in_array($decision, ['CONFIRMED_SUSPICIOUS', 'DISMISSED'], true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Décision invalide.',
                    'error' => 'decision doit valoir CONFIRMED_SUSPICIOUS ou DISMISSED.',
                ], 422);
            }

            if (mb_strlen($comment) < 10) {
                return response()->json([
                    'success' => false,
                    'message' => 'Motif obligatoire.',
                    'error' => 'comment doit contenir au moins 10 caractères.',
                ], 422);
            }

            $userId = $request->user()->id;

            DB::transaction(function () use ($id, $decision, $comment, $userId) {
                DB::table('alerts')
                    ->where('id', $id)
                    ->update(['status' => 'CLOSED']);

                DB::table('alert_actions')->insert([
                    'alert_id' => $id,
                    'user_id' => $userId,
                    'action_type' => $decision,
                    'comment' => $comment,
                    'created_at' => now(),
                ]);
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'alert_id' => $id,
                    'status' => 'CLOSED',
                    'decision' => $decision,
                    'decided_by' => $userId,
                    'decided_at' => now()->toIso8601String(),
                ],
            ]);

        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’enregistrement de la décision.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


}
