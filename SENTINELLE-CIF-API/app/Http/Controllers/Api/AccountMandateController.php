<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Mandats d'un compte — lecture seule.
 */
class AccountMandateController extends Controller
{
    /**
     * GET /accounts/{id}/mandates
     */
    public function index(int $id): JsonResponse
    {
        try {
            $account = DB::table('accounts')->where('id', $id)->first();
            if (!$account) {
                return response()->json([
                    'success' => false,
                    'message' => 'Compte introuvable.',
                    'account_id' => $id,
                ], 404);
            }

            $mandates = DB::table('account_mandates as m')
                ->leftJoin('clients as mc', 'mc.id', '=', 'm.mandate_client_id')
                ->leftJoin('client_individuals as mi', 'mi.client_id', '=', 'm.mandate_client_id')
                ->where('m.account_id', $id)
                ->orderByDesc('m.status')
                ->orderByDesc('m.id')
                ->select([
                    'm.id',
                    'm.account_id',
                    'm.mandate_client_id',
                    'm.full_name',
                    'm.identity_number',
                    'm.phone',
                    'm.mandate_role',
                    'm.powers',
                    'm.status',
                    'm.valid_from',
                    'm.valid_to',
                    'm.created_at',
                    'mc.client_number as mandate_client_number',
                    'mi.first_name as mandate_first_name',
                    'mi.last_name as mandate_last_name',
                    DB::raw(
                        "CASE
                            WHEN m.status = 'ACTIVE'
                             AND (m.valid_from IS NULL OR m.valid_from <= CURDATE())
                             AND (m.valid_to IS NULL OR m.valid_to >= CURDATE())
                            THEN 1 ELSE 0
                         END AS is_currently_active"
                    ),
                ])
                ->get();

            return response()->json([
                'success' => true,
                'account_id' => $id,
                'client_id' => $account->client_id,
                'account_number' => $account->account_number,
                'mandates' => $mandates,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lecture mandats.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
}
