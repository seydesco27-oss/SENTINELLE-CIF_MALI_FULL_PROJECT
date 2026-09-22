<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class ClientRiskController extends Controller
{
    /**
     * ============================================================
     * RISQUE COMPLET D'UN CLIENT
     * ============================================================
     *
     * GET /api/v1/clients/{id}/risk
     */
    public function show(int $id): JsonResponse
    {
        try {

            /*
             * ----------------------------------------------------
             * 1. CLIENT PRINCIPAL + NIVEAU DE RISQUE
             * ----------------------------------------------------
             */
            $client = DB::table('clients as c')
                ->leftJoin(
                    'risk_levels as rl',
                    'rl.id',
                    '=',
                    'c.risk_level_id'
                )
                ->select([
                    'c.id',
                    'c.client_number',
                    'c.client_type',
                    'c.status',
                    'c.is_pep',
                    'c.risk_score',
                    'c.risk_level_id',

                    'rl.code as risk_level_code',
                    'rl.label as risk_level_label',
                    'rl.description as risk_level_description',
                ])
                ->where('c.id', $id)
                ->first();

            /*
             * ----------------------------------------------------
             * Client inexistant
             * ----------------------------------------------------
             */
            if (!$client) {

                return response()->json([
                    'success' => false,
                    'message' => 'Client introuvable.',
                ], 404);
            }


            /*
             * ----------------------------------------------------
             * 2. DERNIER SCORE STRUCTURÉ
             *
             * risk_scores contient :
             * pep_score
             * transaction_score
             * ai_score
             * final_score
             * risk_decision
             * explanation
             * ----------------------------------------------------
             */
            $latestRiskScore = DB::table('risk_scores')
                ->select([
                    'id',
                    'client_id',
                    'pep_score',
                    'transaction_score',
                    'ai_score',
                    'final_score',
                    'risk_decision',
                    'explanation',
                    'created_at',
                ])
                ->where('client_id', $id)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first();


            /*
             * ----------------------------------------------------
             * 3. ÉVALUATIONS DE RISQUE
             * ----------------------------------------------------
             */
            $assessments = DB::table('risk_assessments as ra')
                ->leftJoin(
                    'transactions as t',
                    't.id',
                    '=',
                    'ra.transaction_id'
                )
                ->select([
                    'ra.id',
                    'ra.client_id',
                    'ra.transaction_id',
                    'ra.risk_type',
                    'ra.score',
                    'ra.risk_level',
                    'ra.reason',
                    'ra.source',
                    'ra.created_at',

                    't.transaction_reference',
                    't.transaction_type',
                    't.amount',
                    't.currency',
                    't.transaction_date',
                    't.transaction_status',
                ])
                ->where('ra.client_id', $id)
                ->orderByDesc('ra.created_at')
                ->orderByDesc('ra.id')
                ->get();


            /*
             * ----------------------------------------------------
             * 4. HISTORIQUE DU RISQUE
             * ----------------------------------------------------
             */
            $history = DB::table('client_risk_history')
                ->select([
                    'id',
                    'client_id',
                    'old_score',
                    'new_score',
                    'old_level',
                    'new_level',
                    'reason',
                    'created_at',
                ])
                ->where('client_id', $id)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get();


            /*
             * ----------------------------------------------------
             * 5. STATISTIQUES DES ÉVALUATIONS
             * ----------------------------------------------------
             */
            $assessmentCount = $assessments->count();

            $maxAssessmentScore = $assessments->max('score');

            $averageAssessmentScore = $assessmentCount > 0
                ? round(
                    (float) $assessments->avg('score'),
                    2
                )
                : null;


            /*
             * ----------------------------------------------------
             * 6. RÉPARTITION DES NIVEAUX
             * ----------------------------------------------------
             */
            $riskLevelDistribution = $assessments
                ->groupBy('risk_level')
                ->map(function ($items, $level) {

                    return [
                        'risk_level' => $level,
                        'count' => $items->count(),
                        'max_score' => $items->max('score'),
                    ];

                })
                ->values();


            /*
             * ----------------------------------------------------
             * 7. RÉPONSE JSON
             * ----------------------------------------------------
             */
            return response()->json([

                'success' => true,

                'data' => [

                    /*
                     * Identité minimale du client.
                     */
                    'client' => $client,

                    /*
                     * Niveau de risque actuellement enregistré
                     * dans clients.
                     */
                    'current_risk' => [

                        'score' => $client->risk_score,

                        'level' => [
                            'id' => $client->risk_level_id,
                            'code' => $client->risk_level_code,
                            'label' => $client->risk_level_label,
                            'description' => $client->risk_level_description,
                        ],

                    ],

                    /*
                     * Dernier enregistrement de risk_scores.
                     */
                    'latest_risk_score' => $latestRiskScore,

                    /*
                     * Statistiques calculées à partir des
                     * risk_assessments existantes.
                     */
                    'summary' => [

                        'assessment_count' => $assessmentCount,

                        'max_assessment_score' => $maxAssessmentScore,

                        'average_assessment_score' => $averageAssessmentScore,

                        'risk_levels' => $riskLevelDistribution,

                    ],

                    /*
                     * Historique complet.
                     */
                    'history' => $history,

                    /*
                     * Évaluations détaillées.
                     */
                    'assessments' => $assessments,

                ],
            ]);

        } catch (Throwable $e) {

            return response()->json([

                'success' => false,

                'message' => 'Erreur lors de la récupération du risque du client.',

                'error' => $e->getMessage(),

            ], 500);
        }
    }
}