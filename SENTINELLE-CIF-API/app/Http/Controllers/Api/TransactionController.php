<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AgencyAccess;
use App\Services\MlRiskScoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;
use Illuminate\Support\Str;

class TransactionController extends Controller
{
    /**
     * ============================================================
     * LISTE DES TRANSACTIONS
     * ============================================================
     *
     * GET /api/v1/transactions
     *
     * Filtres :
     *
     * ?client_id=1
     * ?account_id=10
     * ?status=COMPLETED
     * ?transaction_type=TRANSFER_IN
     * ?channel=MOBILE
     * ?currency=XOF
     * ?search=REF001
     * ?limit=50
     */
    public function index(Request $request): JsonResponse
    {
        try {

            $limit = min(
                max((int) $request->query('limit', 50), 1),
                100
            );

            $query = DB::table('transactions as t')
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
                ->leftJoin('agencies as ag', 'ag.id', '=', 't.agency_id')
                ->leftJoin('caisses as ca', 'ca.id', '=', 'ag.caisse_id')
                ->select([
                    't.id',
                    't.transaction_reference',

                    't.account_id',
                    'a.account_number',
                    'a.account_type',
                    'a.status as account_status',
                    'a.current_balance as account_current_balance',

                    't.agency_id',
                    'ag.code as agency_code',
                    'ag.name as agency_name',
                    'ag.city as agency_city',
                    'ag.caisse_id',
                    'ca.code as caisse_code',
                    'ca.name as caisse_name',
                    'ca.city as caisse_city',

                    'a.client_id',
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

                    't.transaction_type',
                    't.amount',
                    't.currency',
                    't.channel',
                    't.country_from',
                    't.country_to',
                    't.country',
                    't.transaction_date',
                    't.transaction_status',
                    't.status_changed_at',
                    't.cancellation_reason',
                    't.reversal_of_transaction_id',
                    't.created_at',
                ]);
            AgencyAccess::constrain($query, $request, 't.agency_id');


            /*
             * ====================================================
             * FILTRE CLIENT
             * ====================================================
             */
            if ($request->filled('client_id')) {

                $query->where(
                    'a.client_id',
                    (int) $request->query('client_id')
                );
            }


            /*
             * ====================================================
             * FILTRE COMPTE
             * ====================================================
             */
            if ($request->filled('account_id')) {

                $query->where(
                    't.account_id',
                    (int) $request->query('account_id')
                );
            }


            /*
             * ====================================================
             * STATUT
             * ====================================================
             */
            if ($request->filled('status')) {

                $query->where(
                    't.transaction_status',
                    strtoupper(
                        trim($request->query('status'))
                    )
                );
            }


            /*
             * ====================================================
             * TYPE
             * ====================================================
             */
            if ($request->filled('transaction_type')) {

                $query->where(
                    't.transaction_type',
                    $request->query('transaction_type')
                );
            }


            /*
             * ====================================================
             * CHANNEL
             * ====================================================
             */
            if ($request->filled('channel')) {

                $query->where(
                    't.channel',
                    $request->query('channel')
                );
            }


            /*
             * ====================================================
             * DEVISE
             * ====================================================
             */
            if ($request->filled('currency')) {

                $query->where(
                    't.currency',
                    strtoupper(
                        trim($request->query('currency'))
                    )
                );
            }


            /*
             * ====================================================
             * RECHERCHE
             * ====================================================
             */
            if ($request->filled('search')) {

                $search = trim(
                    $request->query('search')
                );

                $query->where(function ($q) use ($search) {

                    $q->where(
                        't.transaction_reference',
                        'LIKE',
                        "%{$search}%"
                    )

                    ->orWhere(
                        'c.client_number',
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
                    )

                    ->orWhere(
                        'a.account_number',
                        'LIKE',
                        "%{$search}%"
                    );
                });
            }


            $transactions = $query
                ->orderByDesc('t.transaction_date')
                ->orderByDesc('t.id')
                ->limit($limit)
                ->get();

            if (AgencyAccess::restrictedAgencyId($request) !== null) {
                $transactions->each(function ($transaction): void {
                    unset($transaction->is_pep, $transaction->risk_score);
                });
            }


            return response()->json([
                'success' => true,

                'count' => $transactions->count(),

                'filters' => [
                    'client_id' => $request->query('client_id'),
                    'account_id' => $request->query('account_id'),
                    'status' => $request->query('status'),
                    'transaction_type' =>
                        $request->query('transaction_type'),
                    'channel' => $request->query('channel'),
                    'currency' => $request->query('currency'),
                    'search' => $request->query('search'),
                ],

                'data' => $transactions,
            ]);

        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'message' =>
                    'Erreur lors de la récupération des transactions.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * ============================================================
     * DETAIL ENRICHI D'UNE TRANSACTION
     * ============================================================
     *
     * GET /api/v1/transactions/{id}
     *
     * La vue v_ml_transaction_features est utilisée comme source
     * principale des caractéristiques AML/ML.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $restrictedAgencyId = AgencyAccess::restrictedAgencyId($request);
            if ($restrictedAgencyId !== null && ! DB::table('transactions')
                ->where('id', $id)
                ->where('agency_id', $restrictedAgencyId)
                ->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Transaction introuvable.',
                ], 404);
            }

            /*
             * ====================================================
             * TRANSACTION + FEATURES AML / ML
             * ====================================================
             */
            $transaction = $restrictedAgencyId === null
                ? DB::table('v_ml_transaction_features')
                    ->where('transaction_id', $id)
                    ->first()
                : null;


            /*
             * ====================================================
             * FALLBACK
             *
             * Une transaction peut exister sans disposer encore
             * de toutes les données exploitées par la vue.
             * ====================================================
             */
            if (!$transaction) {

                $transaction = DB::table(
                    'transactions as t'
                )
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
                    ->leftJoin('agencies as ag', 'ag.id', '=', 't.agency_id')
                    ->leftJoin('caisses as ca', 'ca.id', '=', 'ag.caisse_id')
                    ->select([
                        't.id as transaction_id',
                        't.transaction_reference',
                        't.account_id',
                        'a.client_id',

                        't.transaction_type',
                        't.amount',
                        't.currency',
                        't.channel',
                        't.country_from',
                        't.country_to',
                        't.country',
                        't.transaction_date',
                        't.transaction_status',
                        't.status_changed_at',
                        't.cancellation_reason',
                        't.reversal_of_transaction_id',

                        'a.account_number',
                        'a.account_type',
                        'a.status as account_status',
                        'a.current_balance as account_current_balance',

                        't.agency_id',
                        'ag.code as agency_code',
                        'ag.name as agency_name',
                        'ag.city as agency_city',
                        'ag.caisse_id',
                        'ca.code as caisse_code',
                        'ca.name as caisse_name',
                        'ca.city as caisse_city',

                        'c.client_number',
                        'c.client_type',
                        'c.is_pep',
                        'c.risk_score',
                    ])
                    ->where(
                        't.id',
                        $id
                    )
                    ->first();
            }

            if ($restrictedAgencyId !== null) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'transaction' => $transaction,
                        'aml' => null,
                        'alerts' => [],
                        'risk_assessments' => [],
                        'rule_executions' => [],
                        'ml_label' => null,
                    ],
                ]);
            }


            if ($transaction) {
                $clientContext = DB::table('transactions as t')
                    ->leftJoin('accounts as a', 'a.id', '=', 't.account_id')
                    ->leftJoin('clients as c', 'c.id', '=', 'a.client_id')
                    ->leftJoin('client_individuals as ci', 'ci.client_id', '=', 'c.id')
                    ->leftJoin('client_entities as ce', 'ce.client_id', '=', 'c.id')
                    ->where('t.id', $id)
                    ->select([
                        'a.client_id',
                        'a.account_number',
                        'a.current_balance as account_current_balance',
                        'c.client_number',
                        'c.client_type',
                        DB::raw("CASE WHEN ci.client_id IS NOT NULL THEN TRIM(CONCAT(COALESCE(ci.first_name,''),' ',COALESCE(ci.last_name,''))) WHEN ce.client_id IS NOT NULL THEN ce.legal_name ELSE c.client_number END as customer_name"),
                    ])
                    ->first();

                if ($clientContext) {
                    foreach ((array) $clientContext as $field => $value) {
                        if (!isset($transaction->{$field}) || $transaction->{$field} === '') {
                            $transaction->{$field} = $value;
                        }
                    }
                }
            }


            if (!$transaction) {

                return response()->json([
                    'success' => false,
                    'message' =>
                        'Transaction introuvable.',
                ], 404);
            }


            /*
             * ====================================================
             * ALERTES
             * ====================================================
             */
            $alerts = DB::table('alerts')
                ->select([
                    'id',
                    'reference',
                    'client_id',
                    'transaction_id',
                    'alert_type',
                    'priority',
                    'status',
                    'final_score',
                    'title',
                    'description',
                    'created_at',
                ])
                ->where(
                    'transaction_id',
                    $id
                )
                ->orderByDesc('created_at')
                ->get();


            /*
             * ====================================================
             * RISK ASSESSMENTS
             * ====================================================
             */
            $riskAssessments = DB::table(
                'risk_assessments'
            )
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
                ->where(
                    'transaction_id',
                    $id
                )
                ->orderByDesc('score')
                ->orderByDesc('created_at')
                ->get();


            /*
             * ====================================================
             * RULE EXECUTIONS
             * ====================================================
             */
            $ruleExecutions = DB::table(
                'rule_executions as re'
            )
                ->leftJoin(
                    'aml_rules as ar',
                    'ar.id',
                    '=',
                    're.rule_id'
                )
                ->select([
                    're.id',
                    're.rule_id',
                    're.transaction_id',
                    're.execution_result',
                    're.executed_at',

                    'ar.rule_code',
                    'ar.name',
                    'ar.description',
                    'ar.score as rule_score',
                    'ar.severity',
                ])
                ->where(
                    're.transaction_id',
                    $id
                )
                ->orderByDesc('re.executed_at')
                ->get();


            /*
             * ====================================================
             * LABEL ML
             * ====================================================
             */
            $label = DB::table(
                'v_ml_transaction_labels'
            )
                ->where(
                    'transaction_id',
                    $id
                )
                ->first();


            /*
             * ====================================================
             * STATUT AML
             * ====================================================
             */
            $hasAlerts =
                $alerts->isNotEmpty();

            $hasRiskAssessment =
                $riskAssessments->isNotEmpty();

            $amlStatus =
                $hasAlerts || $hasRiskAssessment
                    ? 'ANALYZED'
                    : 'NOT_ANALYZED';

            $assessmentScore = $riskAssessments->max('score');
            $alertScore = $alerts->max('final_score');
            $amlScore = collect([$assessmentScore, $alertScore])
                ->filter(fn ($value) => $value !== null)
                ->max();
            $amlLevel = $riskAssessments->first()->risk_level
                ?? $alerts->first()->priority
                ?? null;


            return response()->json([
                'success' => true,

                'data' => [

                    'transaction' => $transaction,

                    'aml' => [
                        'status' => $amlStatus,

                        'alert_count' =>
                            $alerts->count(),

                        'risk_assessment_count' =>
                            $riskAssessments->count(),

                        'rule_execution_count' =>
                            $ruleExecutions->count(),

                        'max_risk_score' =>
                            $riskAssessments->max('score'),

                        'max_alert_score' =>
                            $alerts->max('final_score'),

                        // Aliases consommés par le frontend transactionnel.
                        'risk_score' => $amlScore,
                        'risk_level' => $amlLevel,
                    ],

                    'alerts' => $alerts,

                    'risk_assessments' =>
                        $riskAssessments,

                    'rule_executions' =>
                        $ruleExecutions,

                    'ml_label' => $label,
                ],
            ]);

        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'message' =>
                    'Erreur lors de la récupération du détail de la transaction.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * ============================================================
     * RISQUE D'UNE TRANSACTION
     * ============================================================
     *
     * GET /api/v1/transactions/{id}/risk
     */
    public function risk(int $id): JsonResponse
    {
        try {

            $exists = DB::table('transactions')
                ->where('id', $id)
                ->exists();

            if (!$exists) {

                return response()->json([
                    'success' => false,
                    'message' =>
                        'Transaction introuvable.',
                ], 404);
            }


            $assessments = DB::table(
                'risk_assessments'
            )
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
                ->where(
                    'transaction_id',
                    $id
                )
                ->orderByDesc('score')
                ->orderByDesc('created_at')
                ->get();


            $alerts = DB::table('alerts')
                ->select([
                    'id',
                    'reference',
                    'alert_type',
                    'priority',
                    'status',
                    'final_score',
                    'title',
                    'description',
                    'created_at',
                ])
                ->where(
                    'transaction_id',
                    $id
                )
                ->orderByDesc('final_score')
                ->get();


            return response()->json([
                'success' => true,

                'data' => [

                    'summary' => [
                        'assessment_count' =>
                            $assessments->count(),

                        'alert_count' =>
                            $alerts->count(),

                        'max_assessment_score' =>
                            $assessments->max('score'),

                        'max_alert_score' =>
                            $alerts->max('final_score'),

                        'risk_levels' =>
                            $assessments
                                ->pluck('risk_level')
                                ->filter()
                                ->unique()
                                ->values(),
                    ],

                    'assessments' =>
                        $assessments,

                    'alerts' =>
                        $alerts,
                ],
            ]);

        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'message' =>
                    'Erreur lors de la récupération du risque transactionnel.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * ============================================================
     * ALERTES D'UNE TRANSACTION
     * ============================================================
     *
     * GET /api/v1/transactions/{id}/alerts
     */
    public function alerts(int $id): JsonResponse
    {
        try {

            if (!DB::table('transactions')
                ->where('id', $id)
                ->exists()
            ) {

                return response()->json([
                    'success' => false,
                    'message' =>
                        'Transaction introuvable.',
                ], 404);
            }


            $alerts = DB::table('alerts')
                ->select([
                    'id',
                    'reference',
                    'client_id',
                    'transaction_id',
                    'alert_type',
                    'priority',
                    'status',
                    'final_score',
                    'title',
                    'description',
                    'created_at',
                ])
                ->where(
                    'transaction_id',
                    $id
                )
                ->orderByDesc('created_at')
                ->get();


            return response()->json([
                'success' => true,
                'count' => $alerts->count(),
                'data' => $alerts,
            ]);

        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'message' =>
                    'Erreur lors de la récupération des alertes.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * ============================================================
     * TRANSACTIONS SUSPECTES
     * ============================================================
     *
     * GET /api/v1/transactions/suspicious
     *
     * Source :
     * v_suspicious_transactions
     *
     * Filtres :
     *
     * ?client_id=1
     * ?min_score=60
     * ?pep_only=1
     * ?sanctions_only=1
     * ?limit=50
     */
    public function suspicious(Request $request): JsonResponse
    {
        try {

            $limit = min(
                max(
                    (int) $request->query('limit', 50),
                    1
                ),
                100
            );


            $query = DB::table(
                'v_suspicious_transactions'
            );


            /*
             * ====================================================
             * CLIENT
             * ====================================================
             */
            if ($request->filled('client_id')) {

                $query->where(
                    'client_id',
                    (int) $request->query('client_id')
                );
            }


            /*
             * ====================================================
             * SCORE AML MINIMUM
             * ====================================================
             */
            if ($request->filled('min_score')) {

                $query->where(
                    'aml_risk_score',
                    '>=',
                    (float) $request->query('min_score')
                );
            }


            /*
             * ====================================================
             * PEP
             * ====================================================
             */
            if ($request->boolean('pep_only')) {

                $query->where(
                    'pep_indicator',
                    1
                );
            }


            /*
             * ====================================================
             * SANCTIONS
             * ====================================================
             */
            if ($request->boolean('sanctions_only')) {

                $query->where(
                    'sanctions_match_indicator',
                    1
                );
            }


            /*
             * ====================================================
             * RECHERCHE
             * ====================================================
             */
            if ($request->filled('search')) {

                $search = trim(
                    $request->query('search')
                );

                $query->where(function ($q) use ($search) {

                    $q->where(
                        'transaction_reference',
                        'LIKE',
                        "%{$search}%"
                    )

                    ->orWhere(
                        'client_number',
                        'LIKE',
                        "%{$search}%"
                    )

                    ->orWhere(
                        'country',
                        'LIKE',
                        "%{$search}%"
                    )

                    ->orWhere(
                        'country_from',
                        'LIKE',
                        "%{$search}%"
                    )

                    ->orWhere(
                        'country_to',
                        'LIKE',
                        "%{$search}%"
                    );
                });
            }


            /*
             * ====================================================
             * TRI AML
             * ====================================================
             */
            $transactions = $query
                ->orderByDesc('aml_risk_score')
                ->orderByDesc('sanctions_match_score')
                ->orderByDesc('screening_score')
                ->orderByDesc('transaction_date')
                ->limit($limit)
                ->get();


            return response()->json([
                'success' => true,

                'count' =>
                    $transactions->count(),

                'filters' => [
                    'client_id' =>
                        $request->query('client_id'),

                    'min_score' =>
                        $request->query('min_score'),

                    'pep_only' =>
                        $request->boolean('pep_only'),

                    'sanctions_only' =>
                        $request->boolean('sanctions_only'),

                    'search' =>
                        $request->query('search'),
                ],

                'data' => $transactions,
            ]);

        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'message' =>
                    'Erreur lors de la récupération des transactions suspectes.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


        /**
     * ============================================================
     * ENREGISTREMENT MANUEL D'UNE TRANSACTION
     * ============================================================
     *
     * POST /api/v1/transactions
     *
     * Corps attendu :
     * {
     *   "account_id": 10,
     *   "agency_id": 1,
     *   "transaction_type": "DEPOSIT" | "PAYMENT" | "TRANSFER_IN" | "TRANSFER_OUT" | "WITHDRAWAL",
     *   "amount": 1500000,
     *   "currency": "XOF (optionnel, défaut XOF)",
     *   "channel": "string (optionnel)",
     *   "country_from": "string (optionnel)",
     *   "country_to": "string (optionnel)"
     * }
     *
     * IMPORTANT — NE PAS APPELER sp_aml_evaluate_transaction ICI.
     * Le trigger trg_aml_transaction_realtime (AFTER INSERT sur
     * transactions) déclenche déjà sp_aml_engine_execute
     * automatiquement, avant même que ce code ne reprenne la main.
     * Un second appel manuel créerait des rule_executions et des
     * risk_assessments en double. On se contente de relire le
     * résultat déjà produit par le trigger.
     *
     * De même, accounts.current_balance est mis à jour
     * automatiquement par trg_account_balance_after_transaction_insert
     * — ne jamais le modifier manuellement ici non plus.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $accountId = (int) $request->input('account_id');

            if ($accountId <= 0 || !DB::table('accounts')->where('id', $accountId)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Compte invalide.',
                    'error' => 'account_id doit référencer un compte existant.',
                ], 422);
            }

            $agencyId = $request->filled('agency_id') ? (int) $request->input('agency_id') : null;
            if ($agencyId !== null && $agencyId > 0) {
                if (!DB::table('agencies')->where('id', $agencyId)->exists()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Agence invalide.',
                        'error' => 'agency_id doit référencer une agence existante.',
                    ], 422);
                }
            } else {
                $agencyId = null; // procédure déduira depuis le client
            }

            $allowedTypes = ['DEPOSIT', 'PAYMENT', 'TRANSFER_IN', 'TRANSFER_OUT', 'WITHDRAWAL'];
            $transactionType = strtoupper(trim((string) $request->input('transaction_type')));

            if (!in_array($transactionType, $allowedTypes, true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Type de transaction invalide.',
                    'error' => 'transaction_type doit valoir ' . implode(', ', $allowedTypes) . '.',
                ], 422);
            }

            $amount = (float) $request->input('amount');
            if ($amount <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Montant invalide.',
                    'error' => 'amount doit être strictement positif.',
                ], 422);
            }

            // Référence unique si non fournie
            $reference = trim((string) $request->input('transaction_reference'));
            if ($reference === '') {
                $reference = null;
                for ($attempt = 0; $attempt < 5; $attempt++) {
                    $candidate = 'TRX-' . now()->format('Y') . '-' . strtoupper(\Illuminate\Support\Str::random(8));
                    if (!DB::table('transactions')->where('transaction_reference', $candidate)->exists()) {
                        $reference = $candidate;
                        break;
                    }
                }
                if ($reference === null) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Impossible de générer une transaction_reference unique.',
                    ], 500);
                }
            }

            /*
             |--------------------------------------------------------------------------
             | Enregistrement via sp_transaction_register
             |--------------------------------------------------------------------------
             | Valide compte ACTIVE, insère, déclenche AML silencieux.
             | Point unique métier — cohérent avec triggers + sp_aml_*.
             */
            DB::select(
                'CALL sp_transaction_register(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, @p_transaction_id, @p_status, @p_message)',
                [
                    $accountId,
                    $reference,
                    $transactionType,
                    $amount,
                    $request->input('currency', 'XOF'),
                    $request->input('channel'),
                    $request->input('country', 'ML'),
                    $request->input('transaction_date'), // null → NOW() côté proc
                    $agencyId,
                    $request->input('initiated_by_type'),
                    $request->input('initiated_by_mandate_id'),
                    $request->input('initiated_by_client_id'),
                    $request->input('counterpart_account_id'),
                    $request->input('counterpart_client_id'),
                    $request->input('counterpart_name'),
                ]
            );

            $out = DB::selectOne('SELECT @p_transaction_id AS transaction_id, @p_status AS status, @p_message AS message');

            if (!$out || strtoupper((string) $out->status) !== 'SUCCESS') {
                return response()->json([
                    'success' => false,
                    'message' => $out->message ?? 'Échec enregistrement transaction.',
                    'status' => $out->status ?? 'ERROR',
                ], 422);
            }

            $transactionId = (int) $out->transaction_id;

            $mlAnalysis = null;
            $mlWarning = null;
            try {
                $linkedAlertId = DB::table('alerts')
                    ->where('transaction_id', $transactionId)
                    ->value('id');
                $mlAnalysis = app(MlRiskScoringService::class)->scoreTransaction(
                    $transactionId,
                    $linkedAlertId ? (int) $linkedAlertId : null
                );
            } catch (Throwable $mlError) {
                // L'enregistrement métier reste valide si le microservice est
                // momentanément indisponible; l'alerte pourra être rescannée.
                $mlWarning = $mlError->getMessage();
            }

            $account = DB::table('accounts')->where('id', $accountId)->first(['id', 'current_balance', 'account_number']);

            $ruleExecutions = DB::table('rule_executions')
                ->where('transaction_id', $transactionId)
                ->count();

            $riskAssessments = DB::table('risk_assessments')
                ->where('transaction_id', $transactionId)
                ->orderByDesc('score')
                ->get(['id', 'score', 'risk_level', 'reason']);

            $alerts = DB::table('alerts')
                ->where('transaction_id', $transactionId)
                ->get(['id', 'reference', 'priority', 'status', 'final_score', 'title']);

            $tx = DB::table('transactions')->where('id', $transactionId)->first();

            return response()->json([
                'success' => true,
                'message' => $out->message,
                'data' => [
                    'id' => $transactionId,
                    'transaction_reference' => $tx->transaction_reference ?? $reference,
                    'transaction' => $tx,
                    'account_current_balance' => $account->current_balance ?? null,
                    'account_number' => $account->account_number ?? null,
                    'rule_executions_count' => $ruleExecutions,
                    'risk_assessments' => $riskAssessments,
                    'alerts' => $alerts,
                    'ml_analysis' => $mlAnalysis,
                    'ml_warning' => $mlWarning,
                ],
            ], 201);

        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’enregistrement de la transaction.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


}
