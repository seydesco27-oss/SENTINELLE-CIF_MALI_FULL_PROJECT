<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AgencyAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class TransactionMlController extends Controller
{
    /**
     * ============================================================
     * FEATURES ML D'UNE TRANSACTION
     * ============================================================
     *
     * GET /api/v1/transactions/{id}/ml-features
     *
     * Source principale :
     * v_ml_transaction_features
     *
     * Label :
     * v_ml_transaction_labels
     *
     * IMPORTANT :
     * Aucun calcul ML n'est effectué dans Laravel.
     * La logique reste dans MySQL.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        try {

            if (! AgencyAccess::canAccessTransaction($request, $id)) {
                return response()->json(['success' => false, 'message' => 'Transaction introuvable.'], 404);
            }

            /*
             * ====================================================
             * 1. VÉRIFICATION DE LA TRANSACTION
             * ====================================================
             */
            $transaction = DB::table('transactions as t')
                ->leftJoin('accounts as a', 'a.id', '=', 't.account_id')
                ->leftJoin('agencies as ag', 'ag.id', '=', 't.agency_id')
                ->leftJoin('caisses as ca', 'ca.id', '=', 'ag.caisse_id')
                ->select([
                    't.id',
                    't.transaction_reference',
                    't.account_id',
                    'a.current_balance as account_current_balance',
                    't.agency_id',
                    'ag.code as agency_code',
                    'ag.name as agency_name',
                    'ag.caisse_id',
                    'ca.code as caisse_code',
                    'ca.name as caisse_name',
                    't.transaction_type',
                    't.amount',
                    't.currency',
                    't.channel',
                    't.country_from',
                    't.country_to',
                    't.country',
                    't.transaction_date',
                    't.transaction_status',
                ])
                ->where('t.id', $id)
                ->first();

            if (!$transaction) {

                return response()->json([
                    'success' => false,
                    'message' => 'Transaction introuvable.',
                    'transaction_id' => $id,
                ], 404);
            }


            /*
             * ====================================================
             * 2. FEATURES ML
             * ====================================================
             */
            $features = DB::table(
                'v_ml_transaction_features'
            )
                ->where(
                    'transaction_id',
                    $id
                )
                ->first();


            /*
             * ====================================================
             * 3. LABEL ML
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
             * 4. RÉPONSE
             * ====================================================
             */
            return response()->json([
                'success' => true,

                'data' => [

                    'transaction' => $transaction,

                    'ml' => [
                        'available' => $features !== null,

                        'features' => $features,

                        'label' => $label,
                    ],
                ],
            ]);

        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'message' =>
                    'Erreur lors de la récupération des features ML de la transaction.',
                'transaction_id' => $id,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
