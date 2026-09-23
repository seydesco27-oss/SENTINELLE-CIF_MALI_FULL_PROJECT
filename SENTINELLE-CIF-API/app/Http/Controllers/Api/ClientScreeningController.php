<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AgencyAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class ClientScreeningController extends Controller
{
    /**
     * ============================================================
     * SCREENING D'UN CLIENT
     * ============================================================
     *
     * GET /api/v1/clients/{id}/screening
     *
     * Cette méthode NE RELANCE PAS le moteur.
     *
     * Elle récupère les résultats déjà présents dans :
     * - screenings
     * - screening_lists
     * - sanction_matches
     */
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            if (! AgencyAccess::canAccessClient($request, $id)) {
                return response()->json(['success' => false, 'message' => 'Client introuvable.'], 404);
            }

            /*
             * ----------------------------------------------------
             * Vérification du client
             * ----------------------------------------------------
             */
            $client = DB::table('clients')
                ->select([
                    'id',
                    'client_number',
                    'client_type',
                    'is_pep',
                    'risk_score',
                ])
                ->where('id', $id)
                ->first();

            if (!$client) {
                return response()->json([
                    'success' => false,
                    'message' => 'Client introuvable.',
                ], 404);
            }


            /*
             * ----------------------------------------------------
             * Résultats des screenings
             * ----------------------------------------------------
             */
            $screenings = DB::table('screenings as s')
                ->leftJoin(
                    'screening_lists as sl',
                    'sl.id',
                    '=',
                    's.screening_list_id'
                )
                ->select([
                    's.id',
                    's.client_id',
                    's.screening_list_id',
                    'sl.name as screening_list_name',
                    'sl.type as screening_list_type',
                    'sl.source_organization',
                    'sl.last_update as screening_list_last_update',
                    's.screening_date',
                    's.status',
                    's.match_found',
                    's.confidence_score',
                ])
                ->where('s.client_id', $id)
                ->orderByDesc('s.screening_date')
                ->orderByDesc('s.id')
                ->get();


            /*
             * ----------------------------------------------------
             * Matches sanctions
             * ----------------------------------------------------
             */
            $sanctionMatches = DB::table('sanction_matches as sm')
                ->leftJoin(
                    'screenings as s',
                    's.id',
                    '=',
                    'sm.screening_id'
                )
                ->leftJoin(
                    'screening_lists as sl',
                    'sl.id',
                    '=',
                    's.screening_list_id'
                )
                ->select([
                    'sm.id',
                    'sm.client_id',
                    'sm.screening_id',
                    'sm.sanction_type',
                    'sm.authority',
                    'sm.reason',
                    'sm.match_score',
                    's.screening_date',
                    's.status as screening_status',
                    'sl.name as screening_list_name',
                    'sl.source_organization',
                ])
                ->where('sm.client_id', $id)
                ->orderByDesc('sm.match_score')
                ->orderByDesc('s.screening_date')
                ->orderByDesc('sm.id')
                ->get();


            /*
             * ----------------------------------------------------
             * Synthèse
             * ----------------------------------------------------
             */
            $bestConfidence = $screenings->max('confidence_score');

            $matchCount = $screenings
                ->where('match_found', 1)
                ->count();

            $finalStatus = 'CLEAR';

            if ($sanctionMatches->count() > 0) {
                $finalStatus = 'MATCH';
            } elseif ($matchCount > 0) {
                $finalStatus = 'MATCH';
            }


            return response()->json([
                'success' => true,

                'data' => [
                    'client' => $client,

                    'summary' => [
                        'screening_count' => $screenings->count(),
                        'screenings_with_match' => $matchCount,
                        'sanction_match_count' => $sanctionMatches->count(),
                        'best_confidence_score' => $bestConfidence ?? 0,
                        'final_screening_status' => $finalStatus,
                    ],

                    'screenings' => $screenings,

                    'sanction_matches' => $sanctionMatches,
                ],
            ]);

        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération du screening.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * ============================================================
     * LANCEMENT DU SCREENING
     * ============================================================
     *
     * POST /api/v1/clients/{id}/screening
     *
     * Body optionnel :
     *
     * {
     *     "force": false
     * }
     *
     * force = false :
     * utilise l'idempotence native de sp_screen_client.
     *
     * force = true :
     * force un nouveau screening.
     */
    public function run(Request $request, int $id): JsonResponse
    {
        try {
            if (! AgencyAccess::canAccessClient($request, $id)) {
                return response()->json(['success' => false, 'message' => 'Client introuvable.'], 404);
            }

            /*
             * ----------------------------------------------------
             * Validation
             * ----------------------------------------------------
             */
            $validated = $request->validate([
                'force' => [
                    'sometimes',
                    'boolean',
                ],
            ]);

            $force = (int) ($validated['force'] ?? false);


            /*
             * ----------------------------------------------------
             * Vérification client
             * ----------------------------------------------------
             */
            $client = DB::table('clients')
                ->select([
                    'id',
                    'client_number',
                    'client_type',
                ])
                ->where('id', $id)
                ->first();

            if (!$client) {
                return response()->json([
                    'success' => false,
                    'message' => 'Client introuvable.',
                ], 404);
            }


            /*
             * ----------------------------------------------------
             * Validation du profil enfant
             *
             * sp_screen_client nécessite un profil
             * client_individuals ou client_entities.
             * ----------------------------------------------------
             */
            $hasIndividual = DB::table('client_individuals')
                ->where('client_id', $id)
                ->exists();

            $hasEntity = DB::table('client_entities')
                ->where('client_id', $id)
                ->exists();

            if (!$hasIndividual && !$hasEntity) {
                return response()->json([
                    'success' => false,
                    'message' => 'Le client ne possède aucun profil individual/entity.',
                    'client_id' => $id,
                ], 422);
            }


            /*
             * ----------------------------------------------------
             * Moteur SQL CANONIQUE
             * ----------------------------------------------------
             *
             * IMPORTANT :
             * Laravel ne réalise aucun matching.
             *
             * Toute la logique reste dans :
             *
             * sp_screen_client(client_id, force)
             */
            DB::statement(
                'CALL sp_screen_client(?, ?)',
                [
                    $id,
                    $force,
                ]
            );


            /*
             * ----------------------------------------------------
             * Récupération des résultats produits par le moteur
             * ----------------------------------------------------
             */
            $screenings = DB::table('screenings as s')
                ->leftJoin(
                    'screening_lists as sl',
                    'sl.id',
                    '=',
                    's.screening_list_id'
                )
                ->select([
                    's.id',
                    's.client_id',
                    's.screening_list_id',
                    'sl.name as screening_list_name',
                    'sl.type as screening_list_type',
                    'sl.source_organization',
                    's.screening_date',
                    's.status',
                    's.match_found',
                    's.confidence_score',
                ])
                ->where('s.client_id', $id)
                ->whereDate('s.screening_date', now()->toDateString())
                ->orderByDesc('s.screening_date')
                ->orderByDesc('s.id')
                ->get();


            /*
             * ----------------------------------------------------
             * Matches créés / existants aujourd'hui
             * ----------------------------------------------------
             */
            $sanctionMatches = DB::table('sanction_matches as sm')
                ->leftJoin(
                    'screenings as s',
                    's.id',
                    '=',
                    'sm.screening_id'
                )
                ->leftJoin(
                    'screening_lists as sl',
                    'sl.id',
                    '=',
                    's.screening_list_id'
                )
                ->select([
                    'sm.id',
                    'sm.client_id',
                    'sm.screening_id',
                    'sm.sanction_type',
                    'sm.authority',
                    'sm.reason',
                    'sm.match_score',
                    's.screening_date',
                    's.status as screening_status',
                    'sl.name as screening_list_name',
                ])
                ->where('sm.client_id', $id)
                ->whereDate('s.screening_date', now()->toDateString())
                ->orderByDesc('sm.match_score')
                ->orderByDesc('sm.id')
                ->get();


            /*
             * ----------------------------------------------------
             * Synthèse
             * ----------------------------------------------------
             */
            $matchCount = $screenings
                ->where('match_found', 1)
                ->count();

            $bestScore = $screenings->max('confidence_score') ?? 0;

            $finalStatus = 'CLEAR';

            if ($sanctionMatches->count() > 0 || $matchCount > 0) {
                $finalStatus = 'MATCH';
            }


            return response()->json([
                'success' => true,

                'message' => $force
                    ? 'Screening forcé exécuté avec succès.'
                    : 'Screening exécuté avec succès.',

                'data' => [
                    'client_id' => $id,
                    'force' => (bool) $force,

                    'summary' => [
                        'screenings' => $screenings->count(),
                        'screenings_with_match' => $matchCount,
                        'sanction_matches' => $sanctionMatches->count(),
                        'best_confidence_score' => $bestScore,
                        'final_screening_status' => $finalStatus,
                    ],

                    'screenings' => $screenings,

                    'sanction_matches' => $sanctionMatches,
                ],
            ]);

        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’exécution du screening.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * ============================================================
     * SANCTIONS
     * ============================================================
     *
     * GET /api/v1/clients/{id}/sanctions
     */
    public function sanctions(Request $request, int $id): JsonResponse
    {
        try {

            if (! AgencyAccess::canAccessClient($request, $id)) {
                return response()->json(['success' => false, 'message' => 'Client introuvable.'], 404);
            }

            if (!DB::table('clients')->where('id', $id)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Client introuvable.',
                ], 404);
            }


            $matches = DB::table('sanction_matches as sm')
                ->leftJoin(
                    'screenings as s',
                    's.id',
                    '=',
                    'sm.screening_id'
                )
                ->leftJoin(
                    'screening_lists as sl',
                    'sl.id',
                    '=',
                    's.screening_list_id'
                )
                ->select([
                    'sm.id',
                    'sm.client_id',
                    'sm.screening_id',
                    'sm.sanction_type',
                    'sm.authority',
                    'sm.reason',
                    'sm.match_score',

                    's.screening_date',
                    's.status as screening_status',
                    's.confidence_score',

                    'sl.id as screening_list_id',
                    'sl.name as screening_list_name',
                    'sl.type as screening_list_type',
                    'sl.source_organization',
                    'sl.last_update',
                ])
                ->where('sm.client_id', $id)
                ->orderByDesc('sm.match_score')
                ->orderByDesc('s.screening_date')
                ->orderByDesc('sm.id')
                ->get();


            return response()->json([
                'success' => true,

                'client_id' => $id,

                'summary' => [
                    'match_count' => $matches->count(),
                    'has_match' => $matches->isNotEmpty(),
                    'max_match_score' => $matches->max('match_score') ?? 0,
                ],

                'data' => $matches,
            ]);

        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des sanctions.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * ============================================================
     * PEP
     * ============================================================
     *
     * GET /api/v1/clients/{id}/pep
     *
     * IMPORTANT :
     * Aucune procédure PEP dédiée n'a été identifiée dans
     * le SQL comme équivalent à sp_screen_client.
     *
     * Nous exploitons donc les données réellement présentes :
     *
     * clients.is_pep
     * pep_matches
     */
    public function pep(Request $request, int $id): JsonResponse
    {
        try {

            if (! AgencyAccess::canAccessClient($request, $id)) {
                return response()->json(['success' => false, 'message' => 'Client introuvable.'], 404);
            }

            $client = DB::table('clients')
                ->select([
                    'id',
                    'client_number',
                    'client_type',
                    'is_pep',
                    'risk_score',
                ])
                ->where('id', $id)
                ->first();

            if (!$client) {
                return response()->json([
                    'success' => false,
                    'message' => 'Client introuvable.',
                ], 404);
            }


            $matches = DB::table('pep_matches as pm')
                ->leftJoin(
                    'screenings as s',
                    's.id',
                    '=',
                    'pm.screening_id'
                )
                ->select([
                    'pm.id',
                    'pm.client_id',
                    'pm.screening_id',
                    'pm.pep_category',
                    'pm.position',
                    'pm.country',
                    'pm.match_score',
                    's.screening_date',
                    's.status as screening_status',
                    's.confidence_score',
                ])
                ->where('pm.client_id', $id)
                ->orderByDesc('pm.match_score')
                ->orderByDesc('s.screening_date')
                ->orderByDesc('pm.id')
                ->get();


            return response()->json([
                'success' => true,

                'client_id' => $id,

                'summary' => [
                    'is_pep' => (bool) $client->is_pep,
                    'pep_match_count' => $matches->count(),
                    'has_pep_match' => $matches->isNotEmpty(),
                    'max_match_score' => $matches->max('match_score') ?? 0,
                ],

                'client' => $client,

                'data' => $matches,
            ]);

        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des informations PEP.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
