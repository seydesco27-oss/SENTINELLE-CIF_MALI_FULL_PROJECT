<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class CentifDeclarationController extends Controller
{
    /**
     * ============================================================
     * LISTE DES DÉCLARATIONS CENTIF
     * ============================================================
     *
     * GET /api/v1/centif/declarations
     *
     * Filtres :
     * ?transmission_status=DRAFT
     * ?client_id=1
     * ?limit=50
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $limit = min(max((int) $request->query('limit', 50), 1), 100);

            $query = DB::table('centif_declarations as d')
                ->leftJoin('clients as c', 'c.id', '=', 'd.client_id')
                ->leftJoin('users as u', 'u.id', '=', 'd.declared_by')
                ->leftJoin('alerts as a', 'a.id', '=', 'd.alert_id')
                ->select([
                    'd.id',
                    'd.reference',
                    'd.alert_id',
                    'a.reference as alert_reference',
                    'd.client_id',
                    'c.client_number',
                    'd.declared_by',
                    'u.username as declared_by_username',
                    'd.declaration_date',
                    'd.transmission_status',
                    'd.centif_opposition_until',
                    'd.created_at',
                ]);

            if ($request->filled('transmission_status')) {
                $query->where('d.transmission_status', strtoupper($request->query('transmission_status')));
            }

            if ($request->filled('client_id')) {
                $query->where('d.client_id', (int) $request->query('client_id'));
            }

            $declarations = $query
                ->orderByDesc('d.created_at')
                ->limit($limit)
                ->get();

            return response()->json([
                'success' => true,
                'count' => $declarations->count(),
                'data' => $declarations,
            ]);

        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des déclarations CENTIF.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * ============================================================
     * CRÉATION D'UNE DÉCLARATION CENTIF (brouillon)
     * ============================================================
     *
     * POST /api/v1/centif/declarations
     *
     * Corps attendu :
     * {
     *   "alert_id": 4521,
     *   "content_summary": "string (obligatoire, 30 caractères minimum)"
     * }
     *
     * Le déclarant (declared_by) est automatiquement l'utilisateur
     * authentifié. client_id est déduit de l'alerte, jamais saisi
     * manuellement, pour éviter toute incohérence.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $alertId = (int) $request->input('alert_id');
            $alert = DB::table('alerts')->where('id', $alertId)->first();

            if (!$alert) {
                return response()->json([
                    'success' => false,
                    'message' => 'Alerte introuvable.',
                    'error' => 'alert_id doit référencer une alerte existante.',
                ], 422);
            }

            if (!$alert->client_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cette alerte n’est rattachée à aucun client, déclaration impossible.',
                ], 422);
            }

            $summary = trim((string) $request->input('content_summary'));

            if (mb_strlen($summary) < 30) {
                return response()->json([
                    'success' => false,
                    'message' => 'Résumé des faits obligatoire.',
                    'error' => 'content_summary doit contenir au moins 30 caractères.',
                ], 422);
            }

            $reference = null;

            for ($attempt = 0; $attempt < 5; $attempt++) {
                $candidate = 'DOS-' . now()->format('Y') . '-' . strtoupper(Str::random(6));

                if (!DB::table('centif_declarations')->where('reference', $candidate)->exists()) {
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

            $declarationId = DB::table('centif_declarations')->insertGetId([
                'reference' => $reference,
                'alert_id' => $alertId,
                'client_id' => $alert->client_id,
                'declared_by' => $request->user()->id,
                'declaration_date' => null,
                'content_summary' => $summary,
                'transmission_status' => 'DRAFT',
                'created_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $declarationId,
                    'reference' => $reference,
                    'transmission_status' => 'DRAFT',
                ],
            ], 201);

        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création de la déclaration CENTIF.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * ============================================================
     * DÉTAIL D'UNE DÉCLARATION
     * ============================================================
     *
     * GET /api/v1/centif/declarations/{id}
     */
    public function show(int $id): JsonResponse
    {
        try {
            $declaration = DB::table('centif_declarations as d')
                ->leftJoin('clients as c', 'c.id', '=', 'd.client_id')
                ->leftJoin('users as u', 'u.id', '=', 'd.declared_by')
                ->leftJoin('alerts as a', 'a.id', '=', 'd.alert_id')
                ->select([
                    'd.*',
                    'c.client_number',
                    'u.username as declared_by_username',
                    'a.reference as alert_reference',
                    'a.title as alert_title',
                ])
                ->where('d.id', $id)
                ->first();

            if (!$declaration) {
                return response()->json([
                    'success' => false,
                    'message' => 'Déclaration introuvable.',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $declaration,
            ]);

        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération de la déclaration.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * ============================================================
     * MISE À JOUR DU STATUT DE TRANSMISSION
     * ============================================================
     *
     * PATCH /api/v1/centif/declarations/{id}
     *
     * Corps attendu :
     * {
     *   "transmission_status": "TRANSMITTED" | "ACKNOWLEDGED" | "OPPOSED" | "CLOSED",
     *   "centif_opposition_until": "YYYY-MM-DD (obligatoire uniquement si OPPOSED)"
     * }
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $declaration = DB::table('centif_declarations')->where('id', $id)->first();

            if (!$declaration) {
                return response()->json([
                    'success' => false,
                    'message' => 'Déclaration introuvable.',
                ], 404);
            }

            $allowedStatuses = ['TRANSMITTED', 'ACKNOWLEDGED', 'OPPOSED', 'CLOSED'];
            $newStatus = strtoupper(trim((string) $request->input('transmission_status')));

            if (!in_array($newStatus, $allowedStatuses, true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Statut invalide.',
                    'error' => 'transmission_status doit valoir ' . implode(', ', $allowedStatuses) . '.',
                ], 422);
            }

            $update = ['transmission_status' => $newStatus];

            if ($newStatus === 'TRANSMITTED') {
                $update['declaration_date'] = now();
            }

            if ($newStatus === 'OPPOSED') {
                $oppositionUntil = $request->input('centif_opposition_until');

                if (!$oppositionUntil) {
                    return response()->json([
                        'success' => false,
                        'message' => 'centif_opposition_until est obligatoire pour un statut OPPOSED.',
                    ], 422);
                }

                $update['centif_opposition_until'] = $oppositionUntil;
            }

            DB::table('centif_declarations')->where('id', $id)->update($update);

            /*
             * Réponse construite séparément de $update : jamais
             * renvoyer un objet Carbon brut, NormalizeApiJson le
             * confond avec un montant décimal à reformater.
             */
            $responseData = ['id' => $id, 'transmission_status' => $newStatus];

            if (isset($update['declaration_date'])) {
                $responseData['declaration_date'] = $update['declaration_date']->toIso8601String();
            }

            if (isset($update['centif_opposition_until'])) {
                $responseData['centif_opposition_until'] = $update['centif_opposition_until'];
            }

            return response()->json([
                'success' => true,
                'data' => $responseData,
            ]);


        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour de la déclaration.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}