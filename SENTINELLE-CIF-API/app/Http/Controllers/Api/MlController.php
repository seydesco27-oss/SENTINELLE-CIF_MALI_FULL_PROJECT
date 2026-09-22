<?php

namespace App\Http\Controllers\Api;

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
            $query = DB::table('v_ml_customer_features_current');

            if ($request->filled('client_id')) {
                $query->where(
                    'client_id',
                    $request->integer('client_id')
                );
            }

            if ($request->filled('client_type')) {
                $query->where(
                    'client_type',
                    strtoupper($request->input('client_type'))
                );
            }

            if ($request->filled('pep')) {
                $query->where(
                    'pep_flag',
                    $request->boolean('pep') ? 1 : 0
                );
            }

            if ($request->filled('search')) {
                $search = trim($request->input('search'));

                $query->where(function ($q) use ($search) {
                    $q->where(
                        'client_number',
                        'like',
                        '%' . $search . '%'
                    )
                    ->orWhere(
                        'primary_name',
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
                ->orderByDesc('client_id')
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
            $query = DB::table('v_ml_transaction_features');

            if ($request->filled('transaction_id')) {
                $query->where(
                    'transaction_id',
                    $request->integer('transaction_id')
                );
            }

            if ($request->filled('client_id')) {
                $query->where(
                    'client_id',
                    $request->integer('client_id')
                );
            }

            if ($request->filled('transaction_status')) {
                $query->where(
                    'transaction_status',
                    strtoupper($request->input('transaction_status'))
                );
            }

            if ($request->filled('client_type')) {
                $query->where(
                    'client_type',
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

            $data = $query
                ->orderByDesc('transaction_date')
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
            $query = DB::table('v_suspicious_transactions');

            if ($request->filled('client_id')) {
                $query->where(
                    'client_id',
                    $request->integer('client_id')
                );
            }

            if ($request->filled('client_type')) {
                $query->where(
                    'client_type',
                    strtoupper($request->input('client_type'))
                );
            }

            if ($request->filled('risk_level')) {
                $query->where(
                    'aml_risk_level',
                    strtoupper($request->input('risk_level'))
                );
            }

            if ($request->filled('min_score')) {
                $query->where(
                    'aml_risk_score',
                    '>=',
                    (float) $request->input('min_score')
                );
            }

            if ($request->filled('search')) {
                $search = trim($request->input('search'));

                $query->where(function ($q) use ($search) {
                    $q->where(
                        'transaction_reference',
                        'like',
                        '%' . $search . '%'
                    )
                    ->orWhere(
                        'client_number',
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
                ->orderByDesc('aml_risk_score')
                ->orderByDesc('transaction_date')
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