<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class InvestigationController extends Controller
{
    /**
     * Liste des investigations AML.
     *
     * GET /api/v1/investigations
     *
     * Filtres :
     * ?status=OPEN|CLOSED
     * ?alert_id=54027
     * ?assigned_user=1
     * ?decision=...
     * ?search=...
     * ?limit=50
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $limit = min(
                max((int) $request->query('limit', 50), 1),
                100
            );

            $query = DB::table('investigations as i')
                ->leftJoin('alerts as a', 'a.id', '=', 'i.alert_id')
                ->leftJoin('users as u', 'u.id', '=', 'i.assigned_user')
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
                ->select([
                    'i.id',
                    'i.alert_id',
                    'i.assigned_user',
                    'u.username as assigned_username',

                    'i.decision',
                    'i.comment',
                    'i.started_at',
                    'i.closed_at',

                    'a.reference as alert_reference',
                    'a.alert_type',
                    'a.priority as alert_priority',
                    'a.status as alert_status',
                    'a.final_score as alert_score',
                    'a.title as alert_title',

                    'c.id as client_id',
                    'c.client_number',
                    'c.client_type',
                    'c.risk_score as client_risk_score',

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

                    't.id as transaction_id',
                    't.transaction_reference',
                    't.amount as transaction_amount',
                    't.currency as transaction_currency',
                    't.transaction_date',

                    DB::raw("
                        CASE
                            WHEN i.closed_at IS NULL THEN 'OPEN'
                            ELSE 'CLOSED'
                        END AS investigation_status
                    "),
                ]);

            if ($request->filled('status')) {
                $status = strtoupper(trim($request->query('status')));

                if ($status === 'OPEN') {
                    $query->whereNull('i.closed_at');
                } elseif ($status === 'CLOSED') {
                    $query->whereNotNull('i.closed_at');
                }
            }

            if ($request->filled('alert_id')) {
                $query->where(
                    'i.alert_id',
                    (int) $request->query('alert_id')
                );
            }

            if ($request->filled('assigned_user')) {
                $query->where(
                    'i.assigned_user',
                    (int) $request->query('assigned_user')
                );
            }

            if ($request->filled('decision')) {
                $query->where(
                    'i.decision',
                    $request->query('decision')
                );
            }

            if ($request->filled('search')) {
                $search = trim($request->query('search'));

                $query->where(function ($q) use ($search) {
                    $q->where('a.reference', 'LIKE', "%{$search}%")
                        ->orWhere('a.title', 'LIKE', "%{$search}%")
                        ->orWhere('a.alert_type', 'LIKE', "%{$search}%")
                        ->orWhere('c.client_number', 'LIKE', "%{$search}%")
                        ->orWhere('ci.first_name', 'LIKE', "%{$search}%")
                        ->orWhere('ci.last_name', 'LIKE', "%{$search}%")
                        ->orWhere('ce.legal_name', 'LIKE', "%{$search}%")
                        ->orWhere('t.transaction_reference', 'LIKE', "%{$search}%")
                        ->orWhere('u.username', 'LIKE', "%{$search}%");
                });
            }

            $investigations = $query
                ->orderByRaw('CASE WHEN i.closed_at IS NULL THEN 0 ELSE 1 END')
                ->orderByDesc('i.started_at')
                ->orderByDesc('i.id')
                ->limit($limit)
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
 * DÉTAIL D'UNE INVESTIGATION
 * ============================================================
 *
 * GET /api/v1/investigations/{id}
 */
public function show($id): JsonResponse
{
    try {

        /*
         * Laravel transmet les paramètres de route sous forme
         * de chaîne. On valide donc nous-mêmes la valeur.
         */
        if (!is_string($id) || !ctype_digit($id)) {
            return response()->json([
                'success' => false,
                'message' => 'Identifiant d’investigation invalide.',
                'error' => 'L’identifiant doit être un entier positif.',
            ], 400);
        }

        $investigationId = (int) $id;

        if ($investigationId <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Identifiant d’investigation invalide.',
                'error' => 'L’identifiant doit être supérieur à zéro.',
            ], 400);
        }

        /*
         * Structure confirmée dans la base :
         *
         * investigations
         * - id
         * - alert_id
         * - assigned_user
         * - decision
         * - comment
         * - started_at
         * - closed_at
         *
         * Relation :
         * assigned_user -> users.id
         * alert_id      -> alerts.id
         */
        $investigation = DB::table('investigations as i')
            ->leftJoin('users as u', 'u.id', '=', 'i.assigned_user')
            ->leftJoin('alerts as a', 'a.id', '=', 'i.alert_id')
            ->select([
                'i.id',
                'i.alert_id',
                'i.assigned_user',

                'u.username as assigned_username',

                'i.decision',
                'i.comment',
                'i.started_at',
                'i.closed_at',

                'a.reference as alert_reference',
                'a.client_id',
                'a.transaction_id',
                'a.alert_type',
                'a.priority as alert_priority',
                'a.status as alert_status',
                'a.final_score as alert_score',
            ])
            ->where('i.id', $investigationId)
            ->first();

        if (!$investigation) {
            return response()->json([
                'success' => false,
                'message' => 'Investigation introuvable.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $investigation,
        ]);

    } catch (Throwable $e) {

        return response()->json([
            'success' => false,
            'message' => 'Erreur lors de la récupération de l’investigation.',
            'error' => $e->getMessage(),
        ], 500);
    }
}

       /**
     * ============================================================
     * CRÉATION D'UNE INVESTIGATION
     * ============================================================
     *
     * POST /api/v1/investigations
     *
     * Corps attendu :
     * {
     *   "alert_id": 4521,
     *   "assigned_user": 12   // optionnel
     * }
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $alertId = (int) $request->input('alert_id');

            if ($alertId <= 0 || !DB::table('alerts')->where('id', $alertId)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Alerte introuvable.',
                    'error' => 'alert_id doit référencer une alerte existante.',
                ], 422);
            }

            $assignedUser = $request->input('assigned_user');

            if ($assignedUser !== null) {
                $assignedUser = (int) $assignedUser;

                if (!DB::table('users')->where('id', $assignedUser)->exists()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Utilisateur assigné introuvable.',
                    ], 422);
                }
            }

            $investigationId = DB::table('investigations')->insertGetId([
                'alert_id' => $alertId,
                'assigned_user' => $assignedUser,
                'decision' => null,
                'comment' => null,
                'started_at' => now(),
                'closed_at' => null,
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $investigationId,
                    'alert_id' => $alertId,
                    'assigned_user' => $assignedUser,
                    'started_at' => now()->toIso8601String(),
                ],
            ], 201);

        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création de l’investigation.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * ============================================================
     * CLÔTURE D'UNE INVESTIGATION
     * ============================================================
     *
     * PATCH /api/v1/investigations/{id}/close
     *
     * Corps attendu :
     * {
     *   "decision": "string",
     *   "comment": "string (obligatoire, 10 caractères minimum)"
     * }
     *
     * Effet secondaire : l'alerte liée passe également à CLOSED,
     * par cohérence — on ne peut pas clôturer un dossier d'investigation
     * en laissant l'alerte source ouverte.
     */
    public function close(Request $request, $id): JsonResponse
    {
        try {
            if (!is_string($id) || !ctype_digit($id)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Identifiant d’investigation invalide.',
                ], 400);
            }

            $investigationId = (int) $id;

            $investigation = DB::table('investigations')
                ->where('id', $investigationId)
                ->first();

            if (!$investigation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Investigation introuvable.',
                ], 404);
            }

            if ($investigation->closed_at !== null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cette investigation est déjà clôturée.',
                ], 409);
            }

            $decision = trim((string) $request->input('decision'));
            $comment = trim((string) $request->input('comment'));

            if ($decision === '' || mb_strlen($comment) < 10) {
                return response()->json([
                    'success' => false,
                    'message' => 'decision et comment (10 caractères minimum) sont obligatoires.',
                ], 422);
            }

            DB::transaction(function () use ($investigationId, $investigation, $decision, $comment) {
                DB::table('investigations')
                    ->where('id', $investigationId)
                    ->update([
                        'decision' => $decision,
                        'comment' => $comment,
                        'closed_at' => now(),
                    ]);

                if ($investigation->alert_id !== null) {
                    DB::table('alerts')
                        ->where('id', $investigation->alert_id)
                        ->update(['status' => 'CLOSED']);
                }
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $investigationId,
                    'decision' => $decision,
                    'closed_at' => now()->toIso8601String(),
                ],
            ]);

        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la clôture de l’investigation.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


}
