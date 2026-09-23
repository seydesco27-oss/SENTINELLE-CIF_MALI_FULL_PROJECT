<?php

namespace App\Http\Controllers\Api;

use App\Support\AgencyAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class MlController extends \App\Http\Controllers\Controller
{
    /**
     * Features ML actuelles des clients.
     *
     * Source :
     * v_ml_customer_features_current
     */
    public function customerFeatures(Request $request): JsonResponse
    {
        try {
            $query = DB::table('v_ml_customer_features_current as f')
                ->join('clients as c', 'c.id', '=', 'f.client_id')
                ->leftJoin('accounts as a', 'a.client_id', '=', 'c.id')
                ->select(['f.*'])->distinct();
            AgencyAccess::constrain($query, $request, 'c.agency_id', 'a.account_manager_id');

            if ($request->filled('client_id')) {
                $query->where(
                    'f.client_id',
                    $request->integer('client_id')
                );
            }

            if ($request->filled('client_type')) {
                $query->where(
                    'f.client_type',
                    strtoupper($request->input('client_type'))
                );
            }

            if ($request->filled('pep')) {
                $query->where(
                    'f.pep_flag',
                    $request->boolean('pep') ? 1 : 0
                );
            }

            if ($request->filled('search')) {
                $search = trim($request->input('search'));

                $query->where(function ($q) use ($search) {
                    $q->where(
                        'f.client_number',
                        'like',
                        '%' . $search . '%'
                    )
                    ->orWhere(
                        'f.primary_name',
                        'like',
                        '%' . $search . '%'
                    );
                });
            }

            $limit = min(
                max(
                    (int) $request->input('limit', 100),
                    1
                ),
                500
            );

            $data = $query
                ->orderByDesc('f.client_id')
                ->limit($limit)
                ->get();

            return response()->json([
                'success' => true,
                'count' => $data->count(),
                'data' => $data,
            ]);

        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Impossible de récupérer les features ML clients.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Features ML transactionnelles.
     *
     * Source :
     * v_ml_transaction_features
     */
    public function transactionFeatures(Request $request): JsonResponse
    {
        try {
            $query = DB::table('transactions as t')
                ->leftJoin('accounts as a', 'a.id', '=', 't.account_id')
                ->leftJoin('clients as c', 'c.id', '=', 'a.client_id');
            AgencyAccess::constrain($query, $request, 't.agency_id', 'a.account_manager_id');

            if ($request->filled('transaction_id')) {
                $query->where(
                    't.id',
                    $request->integer('transaction_id')
                );
            }

            if ($request->filled('client_id')) {
                $query->where(
                    'a.client_id',
                    $request->integer('client_id')
                );
            }

            if ($request->filled('transaction_status')) {
                $query->where(
                    't.transaction_status',
                    strtoupper($request->input('transaction_status'))
                );
            }

            if ($request->filled('client_type')) {
                $query->where(
                    'c.client_type',
                    strtoupper($request->input('client_type'))
                );
            }

            $limit = min(
                max(
                    (int) $request->input('limit', 100),
                    1
                ),
                500
            );

            $transactionIds = $query->orderByDesc('t.transaction_date')
                ->limit($limit)->pluck('t.id');
            $data = $transactionIds->isEmpty()
                ? collect()
                : DB::table('v_ml_transaction_features as f')
                    ->whereIn('f.transaction_id', $transactionIds)
                    ->orderByDesc('f.transaction_date')
                    ->get();

            return response()->json([
                'success' => true,
                'count' => $data->count(),
                'data' => $data,
            ]);

        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Impossible de récupérer les features ML transactionnelles.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Transactions suspectes.
     *
     * Source :
     * v_suspicious_transactions
     */
    public function suspiciousTransactions(Request $request): JsonResponse
    {
        try {
            $query = DB::table('v_suspicious_transactions as f')
                ->join('transactions as t', 't.id', '=', 'f.transaction_id')
                ->leftJoin('accounts as a', 'a.id', '=', 't.account_id')
                ->select(['f.*']);
            AgencyAccess::constrain($query, $request, 't.agency_id', 'a.account_manager_id');

            if ($request->filled('client_id')) {
                $query->where(
                    'f.client_id',
                    $request->integer('client_id')
                );
            }

            if ($request->filled('client_type')) {
                $query->where(
                    'f.client_type',
                    strtoupper($request->input('client_type'))
                );
            }

            if ($request->filled('risk_level')) {
                $query->where(
                    'f.aml_risk_level',
                    strtoupper($request->input('risk_level'))
                );
            }

            if ($request->filled('min_score')) {
                $query->where(
                    'f.aml_risk_score',
                    '>=',
                    (float) $request->input('min_score')
                );
            }

            if ($request->filled('search')) {
                $search = trim($request->input('search'));

                $query->where(function ($q) use ($search) {
                    $q->where(
                        'f.transaction_reference',
                        'like',
                        '%' . $search . '%'
                    )
                    ->orWhere(
                        'f.client_number',
                        'like',
                        '%' . $search . '%'
                    );
                });
            }

            $limit = min(
                max(
                    (int) $request->input('limit', 100),
                    1
                ),
                500
            );

            $data = $query
                ->orderByDesc('f.aml_risk_score')
                ->orderByDesc('f.transaction_date')
                ->limit($limit)
                ->get();

            return response()->json([
                'success' => true,
                'count' => $data->count(),
                'data' => $data,
            ]);

        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Impossible de récupérer les transactions suspectes.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
