<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AgencyAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class ClientProfileController extends Controller
{
    /**
     * GET /api/v1/clients/{id}/profile
     *
     * Profil Client 360° : KYC, agence/caisse, comptes, AML et features ML.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            if (! AgencyAccess::canAccessClient($request, $id)) {
                return response()->json(['success' => false, 'message' => 'Client introuvable.'], 404);
            }

            $amlProfile = DB::table('v_customer_aml_profile')
                ->where('client_id', $id)
                ->first();

            if (!$amlProfile) {
                return response()->json([
                    'success' => false,
                    'message' => 'Client introuvable.',
                    'client_id' => $id,
                ], 404);
            }

            $features = DB::table('v_ml_customer_features_current')
                ->where('client_id', $id)
                ->first();

            $client = DB::table('clients as c')
                ->leftJoin('risk_levels as rl', 'rl.id', '=', 'c.risk_level_id')
                ->leftJoin('agencies as ag', 'ag.id', '=', 'c.agency_id')
                ->leftJoin('caisses as ca', 'ca.id', '=', 'ag.caisse_id')
                ->where('c.id', $id)
                ->select([
                    'c.id',
                    'c.client_number',
                    'c.client_type',
                    'c.status',
                    'c.phone',
                    'c.email',
                    'c.is_pep',
                    'c.risk_level_id',
                    'c.risk_score',
                    'c.created_at',
                    'rl.code as risk_level_code',
                    'rl.label as risk_level_label',
                    'rl.description as risk_level_description',
                    'ag.id as agency_id',
                    'ag.code as agency_code',
                    'ag.name as agency_name',
                    'ag.city as agency_city',
                    'ca.id as caisse_id',
                    'ca.code as caisse_code',
                    'ca.name as caisse_name',
                    'ca.city as caisse_city',
                ])
                ->first();

            $individual = DB::table('client_individuals')
                ->where('client_id', $id)
                ->first();

            $entity = DB::table('client_entities')
                ->where('client_id', $id)
                ->first();

            $aliases = DB::table('client_aliases')
                ->where('client_id', $id)
                ->get();

            $identityDocuments = DB::table('identity_documents')
                ->where('client_id', $id)
                ->get();

            $beneficialOwners = DB::table('beneficial_owners')
                ->where('client_id', $id)
                ->get();

            $accounts = DB::table('accounts as a')
                ->where('a.client_id', $id)
                ->select([
                    'a.id',
                    'a.account_number',
                    'a.account_type',
                    'a.opening_balance',
                    'a.current_balance',
                    'a.currency',
                    'a.status',
                    'a.opened_at',
                ])
                ->orderByDesc('a.id')
                ->get();

            $accountSummary = [
                'count' => $accounts->count(),
                'active_count' => $accounts->where('status', 'ACTIVE')->count(),
                'total_current_balance' => $accounts->sum(fn ($a) => (float) ($a->current_balance ?? 0)),
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'client' => $client,
                    'profile' => [
                        'aml' => $amlProfile,
                        'features' => $features,
                    ],
                    'individual' => $individual,
                    'entity' => $entity,
                    'accounts' => $accounts,
                    'account_summary' => $accountSummary,
                    'aliases' => $aliases,
                    'identity_documents' => $identityDocuments,
                    'beneficial_owners' => $beneficialOwners,
                ],
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération du profil client.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
