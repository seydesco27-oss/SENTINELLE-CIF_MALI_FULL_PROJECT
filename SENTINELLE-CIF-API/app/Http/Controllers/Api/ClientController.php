<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AgencyAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;
use Illuminate\Support\Str;

class ClientController extends Controller
{
    /**
     * GET /api/v1/clients
     *
     * Liste paginée des clients avec leur profil AML.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = DB::table('v_customer_aml_profile');
            AgencyAccess::constrain($query, $request, 'client_agency_id');

            /*
             * Recherche générale
             */
            if ($request->filled('search')) {
                $search = trim($request->input('search'));

                $query->where(function ($q) use ($search) {
                    $q->where('client_number', 'like', "%{$search}%")
                      ->orWhere('customer_name', 'like', "%{$search}%");
                });
            }

            /*
             * Filtre type client
             *
             * INDIVIDUAL
             * ENTITY
             */
            if ($request->filled('client_type')) {
                $query->where(
                    'client_type',
                    strtoupper(trim($request->input('client_type')))
                );
            }

            /*
             * Filtre niveau de risque
             */
            if ($request->filled('risk_level')) {
                $query->where(
                    'risk_level',
                    strtoupper(trim($request->input('risk_level')))
                );
            }

            /*
             * Filtre PEP
             */
            if ($request->has('is_pep')) {
                $query->where(
                    'is_pep',
                    (int) $request->boolean('is_pep')
                );
            }

            /*
             * Tri
             */
            $allowedSorts = [
                'client_id',
                'client_number',
                'customer_name',
                'client_type',
                'risk_level',
                'risk_score',
                'alert_count',
                'transaction_count',
                'total_volume',
            ];

            $sort = $request->input('sort', 'client_id');

            if (!in_array($sort, $allowedSorts, true)) {
                $sort = 'client_id';
            }

            $direction = strtolower(
                $request->input('direction', 'desc')
            );

            if (!in_array($direction, ['asc', 'desc'], true)) {
                $direction = 'desc';
            }

            $query->orderBy($sort, $direction);

            /*
             * Pagination
             */
            $perPage = min(
                max((int) $request->input('per_page', 20), 1),
                100
            );

            $clients = $query->paginate($perPage);
            $items = $clients->items();

            // La vue historique expose par erreur le nombre de comptes sous
            // `transaction_count`. Recalculer le compteur réel évite une
            // incohérence entre les indicateurs et l'historique affiché.
            $clientIds = collect($items)
                ->pluck('client_id')
                ->filter()
                ->values();

            $transactionCounts = $clientIds->isEmpty()
                ? collect()
                : DB::table('accounts as a')
                    ->leftJoin('transactions as t', 't.account_id', '=', 'a.id')
                    ->whereIn('a.client_id', $clientIds)
                    ->groupBy('a.client_id')
                    ->select(
                        'a.client_id',
                        DB::raw('COUNT(t.id) as transaction_count')
                    )
                    ->pluck('transaction_count', 'a.client_id');

            foreach ($items as $item) {
                $item->transaction_count = (int) ($transactionCounts[$item->client_id] ?? 0);
            }

            if (AgencyAccess::restrictedAgencyId($request) !== null) {
                foreach ($items as $item) {
                    unset(
                        $item->is_pep,
                        $item->risk_level,
                        $item->risk_score,
                        $item->alert_count
                    );
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Clients récupérés avec succès.',
                'data' => $items,
                'meta' => [
                    'current_page' => $clients->currentPage(),
                    'last_page' => $clients->lastPage(),
                    'per_page' => $clients->perPage(),
                    'total' => $clients->total(),
                ],
            ]);

        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des clients.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * GET /api/v1/clients/{id}
     *
     * Profil AML complet d'un client.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        try {

            /*
             * Profil AML principal.
             *
             * Cette vue prend déjà en compte :
             * clients
             * client_individuals
             * client_entities
             * risk_levels
             * accounts
             * transactions
             * alerts
             */
            $profileQuery = DB::table('v_customer_aml_profile')
                ->where('client_id', $id);
            AgencyAccess::constrain($profileQuery, $request, 'client_agency_id');
            $profile = $profileQuery->first();

            if (!$profile) {
                return response()->json([
                    'success' => false,
                    'message' => 'Client introuvable.',
                ], 404);
            }


            /*
             * Informations individuelles
             */
            $individual = DB::table('client_individuals')
                ->where('client_id', $id)
                ->first();


            /*
             * Informations entreprise / entité
             */
            $entity = DB::table('client_entities')
                ->where('client_id', $id)
                ->first();


            /*
             * Comptes du client
             */
            $accounts = DB::table('accounts')
                ->where('client_id', $id)
                ->orderBy('id')
                ->get();


            /*
             * Alertes du client
             */
            $isRestrictedAgent = AgencyAccess::restrictedAgencyId($request) !== null;
            $alerts = $isRestrictedAgent
                ? collect()
                : DB::table('alerts')
                    ->where('client_id', $id)
                    ->orderByDesc('created_at')
                    ->limit(100)
                    ->get();


            /*
             * Transactions du client
             */
            $transactions = DB::table('transactions as t')
                ->join(
                    'accounts as a',
                    'a.id',
                    '=',
                    't.account_id'
                )
                ->where('a.client_id', $id)
                ->select(
                    't.*'
                )
                ->orderByDesc('t.transaction_date')
                ->limit(100)
                ->get();

            $profile->transaction_count = DB::table('transactions as t')
                ->join('accounts as a', 'a.id', '=', 't.account_id')
                ->where('a.client_id', $id)
                ->count('t.id');
            $profile->account_count = $accounts->count();


            /*
             * Score AML détaillé
             */
            $riskScore = $isRestrictedAgent
                ? null
                : DB::table('risk_scores')
                    ->where('client_id', $id)
                    ->first();

            if ($isRestrictedAgent) {
                unset(
                    $profile->is_pep,
                    $profile->risk_level,
                    $profile->risk_score,
                    $profile->alert_count
                );
            }


            /*
             * Réponse structurée.
             */
            return response()->json([
                'success' => true,

                'data' => [

                    'profile' => $profile,

                    'individual' => $individual,

                    'entity' => $entity,

                    'accounts' => $accounts,

                    'transactions' => $transactions,

                    'alerts' => $alerts,

                    'risk_score' => $riskScore,
                ],
            ]);

        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération du profil client.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

        /**
     * ============================================================
     * CHANGEMENT DE STATUT D'UN CLIENT
     * ============================================================
     *
     * PATCH /api/v1/clients/{id}/status
     *
     * Corps attendu :
     * {
     *   "status": "ACTIVE" | "SUSPENDED" | "BLOCKED" | "CLOSED",
     *   "reason": "string (obligatoire, 10 caractères minimum)"
     * }
     *
     * Trace la modification dans audit_logs (old_data/new_data),
     * sans altérer la structure de la table clients.
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        try {
            $client = DB::table('clients')->where('id', $id)->first();

            if (!$client) {
                return response()->json([
                    'success' => false,
                    'message' => 'Client introuvable.',
                ], 404);
            }

            $allowedStatuses = ['ACTIVE', 'SUSPENDED', 'BLOCKED', 'CLOSED'];
            $newStatus = strtoupper(trim((string) $request->input('status')));
            $reason = trim((string) $request->input('reason'));

            if (!in_array($newStatus, $allowedStatuses, true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Statut invalide.',
                    'error' => 'status doit valoir ACTIVE, SUSPENDED, BLOCKED ou CLOSED.',
                ], 422);
            }

            if (mb_strlen($reason) < 10) {
                return response()->json([
                    'success' => false,
                    'message' => 'Motif obligatoire.',
                    'error' => 'reason doit contenir au moins 10 caractères.',
                ], 422);
            }

            if ($newStatus === $client->status) {
                return response()->json([
                    'success' => false,
                    'message' => 'Le client possède déjà ce statut.',
                ], 409);
            }

            $userId = $request->user()->id;
            $oldStatus = $client->status;

            DB::transaction(function () use ($id, $newStatus, $oldStatus, $reason, $userId) {
                DB::table('clients')
                    ->where('id', $id)
                    ->update(['status' => $newStatus]);

                DB::table('audit_logs')->insert([
                    'user_id' => $userId,
                    'action' => 'STATUS_CHANGE',
                    'entity' => 'CLIENT',
                    'entity_id' => $id,
                    'old_data' => json_encode(['status' => $oldStatus]),
                    'new_data' => json_encode(['status' => $newStatus, 'reason' => $reason]),
                    'created_at' => now(),
                ]);
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'client_id' => $id,
                    'old_status' => $oldStatus,
                    'new_status' => $newStatus,
                    'changed_by' => $userId,
                    'changed_at' => now()->toIso8601String(),
                ],
            ]);

        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du changement de statut du client.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * ============================================================
     * CRÉATION D'UN CLIENT
     * ============================================================
     *
     * POST /api/v1/clients
     *
     * Corps attendu (personne physique) :
     * {
     *   "client_type": "INDIVIDUAL",
     *   "agency_id": 1,
     *   "phone": "string",
     *   "email": "string (optionnel)",
     *   "first_name": "string",
     *   "last_name": "string",
     *   "gender": "string (optionnel)",
     *   "birth_date": "YYYY-MM-DD (optionnel)",
     *   "nationality": "string (optionnel)",
     *   "profession": "string (optionnel)"
     * }
     *
     * Corps attendu (personne morale) :
     * {
     *   "client_type": "ENTITY",
     *   "agency_id": 1,
     *   "phone": "string",
     *   "legal_name": "string",
     *   "entity_type": "string (optionnel)",
     *   "registration_number": "string (optionnel)",
     *   "registration_country": "string (optionnel)"
     * }
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $clientType = strtoupper(trim((string) $request->input('client_type', 'INDIVIDUAL')));

            if (!in_array($clientType, ['INDIVIDUAL', 'ENTITY'], true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Type de client invalide.',
                    'error' => 'client_type doit valoir INDIVIDUAL ou ENTITY.',
                ], 422);
            }

            $agencyId = (int) $request->input('agency_id');

            if ($agencyId <= 0 || !DB::table('agencies')->where('id', $agencyId)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Agence invalide.',
                    'error' => 'agency_id doit référencer une agence existante.',
                ], 422);
            }

            $phone = trim((string) $request->input('phone'));
            if ($phone === '') {
                return response()->json([
                    'success' => false,
                    'message' => 'Téléphone obligatoire.',
                ], 422);
            }

            /*
             |--------------------------------------------------------------------------
             | PERSONNE PHYSIQUE — procédure métier sp_client_onboard_individual
             |--------------------------------------------------------------------------
             | Centralise : KYC level, documents, reviews, screening optionnel.
             | Évite la duplication de règles entre API et SQL.
             */
            if ($clientType === 'INDIVIDUAL') {
                $firstName = trim((string) $request->input('first_name'));
                $lastName = trim((string) $request->input('last_name'));

                if ($firstName === '' || $lastName === '') {
                    return response()->json([
                        'success' => false,
                        'message' => 'first_name et last_name sont obligatoires pour une personne physique.',
                    ], 422);
                }

                // Génération numéro client si non fourni
                $clientNumber = trim((string) $request->input('client_number'));
                if ($clientNumber === '') {
                    $prefix = 'CLI-IND-';
                    $clientNumber = null;
                    for ($attempt = 0; $attempt < 8; $attempt++) {
                        $candidate = $prefix . str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);
                        if (!DB::table('clients')->where('client_number', $candidate)->exists()) {
                            $clientNumber = $candidate;
                            break;
                        }
                    }
                    if ($clientNumber === null) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Impossible de générer un client_number unique.',
                        ], 500);
                    }
                }

                $runScreening = $request->boolean('run_screening', true) ? 1 : 0;

                DB::select(
                    'CALL sp_client_onboard_individual(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, @p_client_id, @p_status, @p_message)',
                    [
                        $agencyId,
                        $clientNumber,
                        $phone,
                        $request->input('email'),
                        $firstName,
                        $lastName,
                        $request->input('gender'),
                        $request->input('birth_date'),
                        $request->input('place_of_birth'),
                        $request->input('nationality'),
                        $request->input('nina'),
                        $request->input('marital_status'),
                        $request->input('profession'),
                        $request->input('activity_sector'),
                        $request->input('declared_income'),
                        $request->input('income_source'),
                        $request->input('employer_name'),
                        $request->input('photo_path'),
                        $request->input('address_country'),
                        $request->input('address_city'),
                        $request->input('address_text'),
                        $request->input('doc_type'),
                        $request->input('doc_number'),
                        $request->input('doc_issue_date'),
                        $request->input('doc_expiry_date'),
                        $request->input('doc_path'),
                        $request->boolean('is_pep') ? 1 : 0,
                        $request->boolean('is_rca') ? 1 : 0,
                        $request->input('rca_note'),
                        $runScreening,
                    ]
                );

                $out = DB::selectOne('SELECT @p_client_id AS client_id, @p_status AS status, @p_message AS message');

                if (!$out || strtoupper((string) $out->status) !== 'SUCCESS') {
                    return response()->json([
                        'success' => false,
                        'message' => $out->message ?? 'Échec onboarding client.',
                        'status' => $out->status ?? 'ERROR',
                    ], 422);
                }

                $clientId = (int) $out->client_id;

                $client = DB::table('clients as c')
                    ->leftJoin('client_individuals as ci', 'ci.client_id', '=', 'c.id')
                    ->leftJoin('risk_levels as rl', 'rl.id', '=', 'c.risk_level_id')
                    ->where('c.id', $clientId)
                    ->select([
                        'c.id', 'c.client_number', 'c.client_type', 'c.status',
                        'c.phone', 'c.email', 'c.is_pep', 'c.is_rca',
                        'c.kyc_status', 'c.kyc_completed_at',
                        'c.risk_score', 'rl.code as risk_level',
                        'c.agency_id',
                        'ci.first_name', 'ci.last_name', 'ci.nina', 'ci.profession',
                    ])
                    ->first();

                $screeningCount = DB::table('screenings')->where('client_id', $clientId)->count();

                return response()->json([
                    'success' => true,
                    'message' => $out->message,
                    'data' => [
                        'client' => $client,
                        'screening_run' => (bool) $runScreening,
                        'screening_count' => $screeningCount,
                        'onboarding_stage' => DB::table('v_client_onboarding_status')
                            ->where('client_id', $clientId)
                            ->value('onboarding_stage'),
                    ],
                ], 201);
            }

            /*
             |--------------------------------------------------------------------------
             | PERSONNE MORALE — insert contrôlé (pas de procédure dédiée en BD)
             |--------------------------------------------------------------------------
             */
            $legalName = trim((string) $request->input('legal_name'));
            if ($legalName === '') {
                return response()->json([
                    'success' => false,
                    'message' => 'legal_name est obligatoire pour une personne morale.',
                ], 422);
            }

            $prefix = 'CLI-ENT-';
            $clientNumber = trim((string) $request->input('client_number'));
            if ($clientNumber === '') {
                $clientNumber = null;
                for ($attempt = 0; $attempt < 8; $attempt++) {
                    $candidate = $prefix . str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);
                    if (!DB::table('clients')->where('client_number', $candidate)->exists()) {
                        $clientNumber = $candidate;
                        break;
                    }
                }
                if ($clientNumber === null) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Impossible de générer un client_number unique.',
                    ], 500);
                }
            }

            $clientId = DB::transaction(function () use ($request, $agencyId, $clientNumber, $phone, $legalName) {
                $riskLow = DB::table('risk_levels')->where('code', 'LOW')->value('id');

                $id = DB::table('clients')->insertGetId([
                    'client_number' => $clientNumber,
                    'client_type' => 'ENTITY',
                    'status' => 'ACTIVE',
                    'phone' => $phone,
                    'email' => $request->input('email'),
                    'is_pep' => $request->boolean('is_pep') ? 1 : 0,
                    'is_rca' => $request->boolean('is_rca') ? 1 : 0,
                    'risk_level_id' => $riskLow,
                    'risk_score' => 0,
                    'agency_id' => $agencyId,
                    'kyc_status' => 'PENDING',
                    'created_at' => now(),
                ]);

                DB::table('client_entities')->insert([
                    'client_id' => $id,
                    'legal_name' => $legalName,
                    'entity_type' => $request->input('entity_type'),
                    'registration_number' => $request->input('registration_number'),
                    'tax_identification_number' => $request->input('tax_identification_number'),
                    'registration_country' => $request->input('registration_country'),
                    'nationality' => $request->input('nationality'),
                    'activity_sector' => $request->input('activity_sector'),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return $id;
            });

            if ($request->boolean('run_screening', true)) {
                try {
                    DB::select('CALL sp_screen_client(?, ?)', [$clientId, 0]);
                } catch (\Throwable $e) {
                    // screening non bloquant
                }
            }

            $client = DB::table('clients as c')
                ->leftJoin('client_entities as ce', 'ce.client_id', '=', 'c.id')
                ->where('c.id', $clientId)
                ->select(['c.*', 'ce.legal_name', 'ce.entity_type'])
                ->first();

            return response()->json([
                'success' => true,
                'message' => 'Client personne morale créé.',
                'data' => [
                    'client' => $client,
                    'onboarding_stage' => DB::table('v_client_onboarding_status')
                        ->where('client_id', $clientId)
                        ->value('onboarding_stage'),
                ],
            ], 201);

        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création du client.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $client = DB::table('clients')->where('id', $id)->first();

            if (!$client) {
                return response()->json([
                    'success' => false,
                    'message' => 'Client introuvable.',
                ], 404);
            }

            DB::transaction(function () use ($request, $id, $client) {
                $clientUpdate = array_filter([
                    'phone' => $request->input('phone'),
                    'email' => $request->input('email'),
                ], fn($v) => $v !== null);

                if ($request->has('is_pep')) {
                    $clientUpdate['is_pep'] = (bool) $request->boolean('is_pep');
                }

                if (!empty($clientUpdate)) {
                    DB::table('clients')->where('id', $id)->update($clientUpdate);
                }

                if ($client->client_type === 'INDIVIDUAL') {
                    $individualUpdate = array_filter([
                        'first_name' => $request->input('first_name'),
                        'last_name' => $request->input('last_name'),
                        'gender' => $request->input('gender'),
                        'birth_date' => $request->input('birth_date'),
                        'nationality' => $request->input('nationality'),
                        'profession' => $request->input('profession'),
                        'activity_sector' => $request->input('activity_sector'),
                    ], fn($v) => $v !== null);

                    if (!empty($individualUpdate)) {
                        $individualUpdate['updated_at'] = now();
                        DB::table('client_individuals')->where('client_id', $id)->update($individualUpdate);
                    }
                } else {
                    $entityUpdate = array_filter([
                        'legal_name' => $request->input('legal_name'),
                        'entity_type' => $request->input('entity_type'),
                        'registration_number' => $request->input('registration_number'),
                        'tax_identification_number' => $request->input('tax_identification_number'),
                        'registration_country' => $request->input('registration_country'),
                        'nationality' => $request->input('nationality'),
                        'activity_sector' => $request->input('activity_sector'),
                    ], fn($v) => $v !== null);

                    if (!empty($entityUpdate)) {
                        $entityUpdate['updated_at'] = now();
                        DB::table('client_entities')->where('client_id', $id)->update($entityUpdate);
                    }
                }
            });

            return response()->json([
                'success' => true,
                'message' => 'Client mis à jour avec succès.',
                'data' => ['id' => $id],
            ]);

        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour du client.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


        /**
     * ============================================================
     * SIGNALEMENT MANUEL D'UN CLIENT
     * ============================================================
     *
     * POST /api/v1/clients/{id}/report
     *
     * Corps attendu :
     * {
     *   "reason": "string (obligatoire, 20 caractères minimum)"
     * }
     */
    public function report(Request $request, int $id): JsonResponse
    {
        try {
            $client = DB::table('clients')->where('id', $id)->first();

            if (!$client) {
                return response()->json([
                    'success' => false,
                    'message' => 'Client introuvable.',
                ], 404);
            }

            $reason = trim((string) $request->input('reason'));

            if (mb_strlen($reason) < 20) {
                return response()->json([
                    'success' => false,
                    'message' => 'Motif obligatoire.',
                    'error' => 'reason doit contenir au moins 20 caractères.',
                ], 422);
            }

            $reference = null;

            for ($attempt = 0; $attempt < 5; $attempt++) {
                $candidate = 'MANUAL-' . now()->format('Y') . '-' . strtoupper(Str::random(6));

                if (!DB::table('alerts')->where('reference', $candidate)->exists()) {
                    $reference = $candidate;
                    break;
                }
            }

            if ($reference === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Impossible de générer une référence unique, réessayez.',
                ], 500);
            }

            $alertId = DB::table('alerts')->insertGetId([
                'reference' => $reference,
                'client_id' => $id,
                'transaction_id' => null,
                'alert_type' => 'MANUAL_REPORT',
                'priority' => 'MEDIUM',
                'status' => 'OPEN',
                'final_score' => null,
                'title' => 'Signalement manuel',
                'description' => $reason,
                'created_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'alert_id' => $alertId,
                    'reference' => $reference,
                    'client_id' => $id,
                    'status' => 'OPEN',
                ],
            ], 201);

        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du signalement du client.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


}
