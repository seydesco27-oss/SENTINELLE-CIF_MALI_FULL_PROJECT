<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class ClientFeaturesController extends Controller
{
    /**
     * ============================================================
     * FEATURES AML / ML COURANTES D'UN CLIENT
     * ============================================================
     *
     * GET /api/v1/clients/{id}/features
     */
    public function show(int $id): JsonResponse
    {
        try {

            /*
             * ====================================================
             * La vue utilise explicitement client_id.
             * ====================================================
             */
            $features = DB::table('v_ml_customer_features_current')
                ->where('client_id', $id)
                ->first();

            if (!$features) {

                /*
                 * Vérifier si le client existe réellement.
                 */
                $clientExists = DB::table('clients')
                    ->where('id', $id)
                    ->exists();

                if (!$clientExists) {

                    return response()->json([
                        'success' => false,
                        'message' => 'Client introuvable.',
                    ], 404);
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Aucune feature disponible pour ce client.',
                    'data' => null,
                ]);
            }


            return response()->json([
                'success' => true,

                'data' => $features,
            ]);

        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des features client.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}