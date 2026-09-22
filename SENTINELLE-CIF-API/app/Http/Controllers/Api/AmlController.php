<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class AmlController extends Controller
{
    /**
     * ============================================================
     * TRAITEMENT AML HISTORIQUE PAR LOT
     * ============================================================
     *
     * POST /api/v1/aml/process
     *
     * Body JSON :
     * {
     *     "batch_size": 100
     * }
     *
     * Exploite :
     * sp_aml_process_batch(batch_size)
     *
     * Le batch utilise lui-même :
     * sp_aml_evaluate_transaction_silent(transaction_id)
     */
    public function process(Request $request): JsonResponse
    {
        try {

            $validated = $request->validate([
                'batch_size' => [
                    'required',
                    'integer',
                    'min:1',
                    'max:1000',
                ],
            ]);

            $batchSize = (int) $validated['batch_size'];

            /*
             * La procédure retourne un UNIQUE result set final.
             */
            $result = DB::select(
                'CALL sp_aml_process_batch(?)',
                [$batchSize]
            );

            if (empty($result)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Le moteur AML batch n’a retourné aucun résultat.',
                ], 500);
            }

            $summary = (array) $result[0];

            /*
             * La procédure SQL possède son propre statut.
             */
            if (
                isset($summary['status'])
                && strtoupper((string) $summary['status']) !== 'SUCCESS'
            ) {
                return response()->json([
                    'success' => false,
                    'data' => $summary,
                ], 422);
            }

            return response()->json([
                'success' => true,
                'message' => 'Traitement AML batch exécuté avec succès.',
                'data' => $summary,
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