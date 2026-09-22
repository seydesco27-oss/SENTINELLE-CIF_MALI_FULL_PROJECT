<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AgencyAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class ClientActivityController extends Controller
{
    /**
     * Transactions d'un client.
     */
    public function transactions(Request $request, int $id): JsonResponse
    {
        try {

            $clientQuery = DB::table('clients')->where('id', $id);
            AgencyAccess::constrain($clientQuery, $request, 'agency_id');
            $clientExists = $clientQuery->exists();

            if (!$clientExists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Client introuvable.',
                    'client_id' => $id,
                ], 404);
            }

            /*
             * On passe par accounts -> transactions
             * car transactions possède account_id,
             * et accounts possède client_id.
             */

            $transactions = DB::table('transactions as t')
                ->join(
                    'accounts as a',
                    'a.id',
                    '=',
                    't.account_id'
                )
                ->leftJoin('agencies as ag', 'ag.id', '=', 't.agency_id')
                ->leftJoin('caisses as ca', 'ca.id', '=', 'ag.caisse_id')
                ->where('a.client_id', $id)
                ->select([
                    't.id',
                    't.transaction_reference',
                    't.account_id',
                    'a.account_number',
                    'a.account_type',
                    'a.current_balance as account_current_balance',

                    'ag.id as agency_id',
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
                ->orderByDesc('t.transaction_date')
                ->limit(100)
                ->get();

            /*
             * =====================================================
             * STATISTIQUES
             * =====================================================
             */

            $statistics = DB::table('transactions as t')
                ->join(
                    'accounts as a',
                    'a.id',
                    '=',
                    't.account_id'
                )
                ->where('a.client_id', $id)
                ->selectRaw('COUNT(*) as total_transactions')
                ->selectRaw(
                    "COALESCE(SUM(
                        CASE
                            WHEN UPPER(COALESCE(t.transaction_status,'')) NOT IN
                            ('CANCELLED','CANCELED','REVERSED','VOID')
                            THEN t.amount
                            ELSE 0
                        END
                    ), 0) as total_volume"
                )
                ->selectRaw(
                    "COALESCE(AVG(
                        CASE
                            WHEN UPPER(COALESCE(t.transaction_status,'')) NOT IN
                            ('CANCELLED','CANCELED','REVERSED','VOID')
                            THEN t.amount
                        END
                    ), 0) as average_amount"
                )
                ->selectRaw(
                    "COUNT(DISTINCT t.country_to) as countries_to"
                )
                ->first();

            return response()->json([
                'success' => true,

                'data' => [
                    'client_id' => $id,

                    'statistics' => $statistics,

                    'transactions' => $transactions,
                ],
            ]);

        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des transactions.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * Alertes AML d'un client.
     */
    public function alerts(int $id): JsonResponse
    {
        try {

            $clientExists = DB::table('clients')
                ->where('id', $id)
                ->exists();

            if (!$clientExists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Client introuvable.',
                    'client_id' => $id,
                ], 404);
            }

            $alerts = DB::table('alerts as a')
                ->leftJoin(
                    'transactions as t',
                    't.id',
                    '=',
                    'a.transaction_id'
                )
                ->where('a.client_id', $id)
                ->select([
                    'a.id',
                    'a.reference',
                    'a.client_id',
                    'a.transaction_id',

                    'a.alert_type',
                    'a.priority',
                    'a.status',

                    'a.final_score',

                    'a.title',
                    'a.description',

                    'a.created_at',

                    't.transaction_reference',
                    't.amount as transaction_amount',
                    't.transaction_type',
                    't.transaction_date',
                    't.channel',
                ])
                ->orderByDesc('a.created_at')
                ->get();

            /*
             * =====================================================
             * STATISTIQUES ALERTES
             * =====================================================
             */

            $statistics = DB::table('alerts')
                ->where('client_id', $id)
                ->selectRaw('COUNT(*) as total_alerts')
                ->selectRaw(
                    "SUM(
                        CASE
                            WHEN UPPER(COALESCE(status,'')) = 'OPEN'
                            THEN 1
                            ELSE 0
                        END
                    ) as open_alerts"
                )
                ->selectRaw(
                    "SUM(
                        CASE
                            WHEN UPPER(COALESCE(priority,'')) = 'CRITICAL'
                            THEN 1
                            ELSE 0
                        END
                    ) as critical_alerts"
                )
                ->selectRaw(
                    "SUM(
                        CASE
                            WHEN UPPER(COALESCE(priority,'')) = 'HIGH'
                            THEN 1
                            ELSE 0
                        END
                    ) as high_alerts"
                )
                ->first();

            return response()->json([
                'success' => true,

                'data' => [
                    'client_id' => $id,

                    'statistics' => $statistics,

                    'alerts' => $alerts,
                ],
            ]);

        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des alertes.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
