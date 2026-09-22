<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class AccountTransactionController extends Controller
{
    /**
     * ============================================================
     * TRANSACTIONS D'UN COMPTE
     * ============================================================
     *
     * GET /api/v1/accounts/{id}/transactions
     *
     * Filtres :
     *
     * ?status=COMPLETED
     * ?transaction_type=TRANSFER_IN
     * ?limit=50
     */
    public function index(Request $request, int $id): JsonResponse
    {
        try {

            /*
             * ====================================================
             * COMPTE
             * ====================================================
             */
            $account = DB::table('accounts as a')
                ->leftJoin(
                    'clients as c',
                    'c.id',
                    '=',
                    'a.client_id'
                )
                ->leftJoin('agencies as ag', 'ag.id', '=', 'c.agency_id')
                ->leftJoin('caisses as ca', 'ca.id', '=', 'ag.caisse_id')
                ->select([
                    'a.id',
                    'a.client_id',
                    'a.account_number',
                    'a.account_type',
                    'a.opening_balance',
                    'a.current_balance',
                    'a.currency',
                    'a.status',
                    'a.opened_at',

                    'c.client_number',
                    'c.client_type',
                    'c.risk_score',
                    'c.is_pep',
                    'ag.id as agency_id',
                    'ag.code as agency_code',
                    'ag.name as agency_name',
                    'ca.id as caisse_id',
                    'ca.code as caisse_code',
                    'ca.name as caisse_name',
                ])
                ->where('a.id', $id)
                ->first();


            if (!$account) {

                return response()->json([
                    'success' => false,
                    'message' =>
                        'Compte introuvable.',
                ], 404);
            }


            $limit = min(
                max(
                    (int) $request->query('limit', 50),
                    1
                ),
                100
            );


            /*
             * ====================================================
             * TRANSACTIONS
             * ====================================================
             */
            $query = DB::table(
                'transactions as t'
            )
                ->leftJoin('agencies as ag', 'ag.id', '=', 't.agency_id')
                ->leftJoin('caisses as ca', 'ca.id', '=', 'ag.caisse_id')
                ->select([
                    't.id',
                    't.transaction_reference',
                    't.account_id',
                    't.agency_id',
                    'ag.code as agency_code',
                    'ag.name as agency_name',
                    'ca.id as caisse_id',
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
                    't.status_changed_at',
                    't.cancellation_reason',
                    't.reversal_of_transaction_id',
                    't.created_at',
                ])
                ->where(
                    't.account_id',
                    $id
                );


            if ($request->filled('status')) {

                $query->where(
                    't.transaction_status',
                    strtoupper(
                        trim(
                            $request->query('status')
                        )
                    )
                );
            }


            if ($request->filled('transaction_type')) {

                $query->where(
                    't.transaction_type',
                    $request->query(
                        'transaction_type'
                    )
                );
            }


            $transactions = $query
                ->orderByDesc(
                    't.transaction_date'
                )
                ->orderByDesc('t.id')
                ->limit($limit)
                ->get();


            /*
             * ====================================================
             * STATISTIQUES DU COMPTE
             * ====================================================
             */
            $statistics = DB::table(
                'transactions'
            )
                ->where(
                    'account_id',
                    $id
                )
                ->selectRaw(
                    'COUNT(*) AS transaction_count'
                )
                ->selectRaw(
                    'COALESCE(SUM(amount), 0) AS transaction_volume'
                )
                ->selectRaw(
                    'COALESCE(AVG(amount), 0) AS average_transaction_amount'
                )
                ->selectRaw(
                    "SUM(
                        CASE
                            WHEN transaction_status = 'COMPLETED'
                            THEN 1
                            ELSE 0
                        END
                    ) AS completed_transaction_count"
                )
                ->first();


            return response()->json([
                'success' => true,

                'data' => [

                    'account' => $account,

                    'statistics' => $statistics,

                    'count' =>
                        $transactions->count(),

                    'transactions' =>
                        $transactions,
                ],
            ]);

        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'message' =>
                    'Erreur lors de la récupération des transactions du compte.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}