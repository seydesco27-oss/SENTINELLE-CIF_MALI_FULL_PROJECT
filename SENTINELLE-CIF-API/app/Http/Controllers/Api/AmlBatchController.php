<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class AmlBatchController extends \App\Http\Controllers\Controller
{
    /**
     * Lance le traitement AML batch officiel DigiAML.
     *
     * La logique métier reste entièrement dans MySQL :
     *
     * sp_aml_process_batch
     *      ↓
     * sp_aml_evaluate_transaction_silent
     *
     * Laravel assure uniquement :
     * - validation HTTP
     * - orchestration
     * - transformation JSON
     * - gestion des erreurs
     */
    public function process(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'batch_size' => [
                'nullable',
                'integer',
                'min:1',
            ],
        ]);

        $batchSize = (int) ($validated['batch_size'] ?? 100);

        try {
            /*
             * IMPORTANT :
             * On utilise la procédure AML officielle.
             *
             * NE PAS remplacer par :
             * sp_process_transaction_batch_safe
             * sp_process_transaction
             * aml_engine_batch
             *
             * Le moteur validé pour le batch est :
             * sp_aml_process_batch
             */
            $result = DB::select(
                'CALL sp_aml_process_batch(?)',
                [$batchSize]
            );

            $row = $result[0] ?? null;

            if (!$row) {
                return response()->json([
                    'success' => false,
                    'message' => 'Le moteur AML batch n’a retourné aucun résultat.',
                ], 500);
            }

            /*
             * La procédure retourne notamment :
             *
             * status
             * requested_batch_size
             * processed_transactions
             * pending_before
             * pending_after
             * transactions_completed_by_batch
             * remaining_transactions
             * active_rules
             * execution_time
             */

            return response()->json([
                'success' => true,
                'message' => 'Traitement AML batch exécuté avec succès.',
                'data' => $row,
            ], 200);

        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du traitement AML batch.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}