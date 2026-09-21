<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class TransactionAmlController extends Controller
{
    /**
     * ============================================================
     * ÉVALUATION AML D'UNE TRANSACTION
     * ============================================================
     *
     * POST /api/v1/transactions/{id}/evaluate
     *
     * Exploite directement :
     *
     * sp_aml_evaluate_transaction(transaction_id)
     *
     * Cette procédure est le moteur AML transactionnel interactif.
     */
    public function evaluate(int $id): JsonResponse
    {
        try {

            /*
             * Vérification préalable de l'existence.
             *
             * Cela permet de retourner un vrai 404 HTTP
             * plutôt que de dépendre uniquement du result set SQL.
             */
            $exists = DB::table('transactions')
                ->where('id', $id)
                ->exists();

            if (!$exists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Transaction introuvable.',
                    'transaction_id' => $id,
                ], 404);
            }

            /*
             * Appel du moteur AML principal.
             *
             * IMPORTANT :
             * on utilise volontairement la procédure qui
             * retourne le résultat final.
             */
            $result = DB::select(
                'CALL sp_aml_evaluate_transaction(?)',
                [$id]
            );

            if (empty($result)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Le moteur AML n’a retourné aucun résultat.',
                    'transaction_id' => $id,
                ], 500);
            }

            $analysis = (array) $result[0];

            /*
             * Gestion des statuts produits par la procédure.
             */
            $status = strtoupper(
                (string) ($analysis['status'] ?? '')
            );

            if ($status === 'ERROR') {

                return response()->json([
                    'success' => false,
                    'data' => $analysis,
                ], 422);
            }

            if ($status === 'SKIPPED') {

                return response()->json([
                    'success' => true,
                    'message' => 'La transaction n’a pas été analysée par le moteur AML.',
                    'data' => $analysis,
                ], 200);
            }

            /*
             * SUCCESS.
             */
            return response()->json([
                'success' => true,
                'message' => 'Transaction évaluée avec succès par le moteur AML.',
                'data' => $analysis,
            ], 200);

        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’évaluation AML de la transaction.',
                'transaction_id' => $id,
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * ============================================================
     * ANALYSE AML ENRICHIE D'UNE TRANSACTION
     * ============================================================
     *
     * GET /api/v1/transactions/{id}/analysis
     *
     * Cette route ne relance PAS le moteur.
     *
     * Elle récupère :
     *
     * 1. transaction + features AML/ML
     * 2. exécutions des 7 règles
     * 3. évaluations de risque
     * 4. alertes
     *
     * La logique de calcul reste dans SQL.
     */
    public function analysis(int $id): JsonResponse
    {
        try {

            /*
             * ----------------------------------------------------
             * 1. FEATURES TRANSACTIONNELLES
             * ----------------------------------------------------
             *
             * v_ml_transaction_features est une vue riche.
             */
            $features = DB::table('v_ml_transaction_features')
                ->where('transaction_id', $id)
                ->first();

            if (!$features) {

                /*
                 * Vérification pour distinguer :
                 *
                 * - transaction inexistante
                 * - vue sans ligne
                 */
                $transactionExists = DB::table('transactions')
                    ->where('id', $id)
                    ->exists();

                if (!$transactionExists) {

                    return response()->json([
                        'success' => false,
                        'message' => 'Transaction introuvable.',
                        'transaction_id' => $id,
                    ], 404);
                }

                /*
                 * La transaction existe mais la vue ne retourne
                 * aucune ligne.
                 */
                $features = null;
            }

            /*
             * ----------------------------------------------------
             * 2. EXECUTIONS DES REGLES AML
             * ----------------------------------------------------
             */
            $ruleExecutions = DB::table('rule_executions as re')
                ->join(
                    'aml_rules as ar',
                    'ar.id',
                    '=',
                    're.rule_id'
                )
                ->select([
                    're.id',
                    're.rule_id',
                    'ar.rule_code',
                    'ar.name',
                    'ar.description',
                    'ar.score',
                    'ar.severity',
                    'ar.active',
                    're.transaction_id',
                    're.execution_result',
                    're.executed_at',
                ])
                ->where('re.transaction_id', $id)
                ->orderBy('ar.id')
                ->get();

            /*
             * ----------------------------------------------------
             * 3. EVALUATIONS DE RISQUE
             * ----------------------------------------------------
             */
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
                ->where('transaction_id', $id)
                ->orderByDesc('score')
                ->orderByDesc('created_at')
                ->get();

            /*
             * ----------------------------------------------------
             * 4. ALERTES
             * ----------------------------------------------------
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
                ->where('transaction_id', $id)
                ->orderByDesc('created_at')
                ->get();

            /*
             * ----------------------------------------------------
             * 5. SYNTHÈSE
             * ----------------------------------------------------
             *
             * Aucun nouveau calcul AML complexe.
             * On synthétise uniquement les données déjà produites
             * par le moteur.
             */
            $matchCount = $ruleExecutions
                ->where('execution_result', 'MATCH')
                ->count();

            $executedCount = $ruleExecutions->count();

            $activeRuleCount = DB::table('aml_rules')
                ->where('active', 1)
                ->count();

            $maxRiskScore = $riskAssessments->max('score');

            $riskLevel = match (true) {
                $maxRiskScore !== null && $maxRiskScore >= 80 => 'CRITICAL',
                $maxRiskScore !== null && $maxRiskScore >= 60 => 'HIGH',
                $maxRiskScore !== null && $maxRiskScore >= 30 => 'MEDIUM',
                default => 'LOW',
            };

            return response()->json([
                'success' => true,

                'data' => [

                    'transaction_id' => $id,

                    'summary' => [
                        'rules_expected' => $activeRuleCount,
                        'rules_executed' => $executedCount,
                        'rules_matched' => $matchCount,
                        'analysis_complete' =>
                            $activeRuleCount > 0
                            && $executedCount >= $activeRuleCount,

                        'risk_assessment_count' =>
                            $riskAssessments->count(),

                        'max_risk_score' =>
                            $maxRiskScore,

                        'risk_level' =>
                            $riskLevel,

                        'alert_count' =>
                            $alerts->count(),

                        'has_alert' =>
                            $alerts->isNotEmpty(),
                    ],

                    'features' => $features,

                    'rule_executions' => $ruleExecutions,

                    'risk_assessments' => $riskAssessments,

                    'alerts' => $alerts,
                ],
            ], 200);

        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération de l’analyse AML.',
                'transaction_id' => $id,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}