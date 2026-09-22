<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AgencyAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class ClientAccountController extends Controller
{
    public function index(Request $request, int $id): JsonResponse
    {
        try {
            $clientQuery = DB::table('clients')->where('id', $id);
            AgencyAccess::constrain($clientQuery, $request, 'agency_id');
            $client = $clientQuery->first();
            if (!$client) {
                return response()->json(['success' => false, 'message' => 'Client introuvable.', 'client_id' => $id], 404);
            }

            $accounts = DB::table('accounts as a')
                ->leftJoin('clients as c', 'c.id', '=', 'a.client_id')
                ->leftJoin('agencies as ag', 'ag.id', '=', 'c.agency_id')
                ->leftJoin('caisses as ca', 'ca.id', '=', 'ag.caisse_id')
                ->leftJoin('users as mgr', 'mgr.id', '=', 'a.account_manager_id')
                ->leftJoin('roles as mgr_role', 'mgr_role.id', '=', 'mgr.role_id')
                ->where('a.client_id', $id)
                ->select([
                    'a.id', 'a.client_id', 'a.account_number', 'a.account_type',
                    'a.opening_balance', 'a.current_balance', 'a.currency', 'a.status', 'a.opened_at',
                    'a.account_manager_id', 'mgr.username as manager_username', 'mgr_role.name as manager_role',
                    'ag.id as agency_id', 'ag.code as agency_code', 'ag.name as agency_name',
                    'ca.id as caisse_id', 'ca.code as caisse_code', 'ca.name as caisse_name',
                ])
                ->orderByDesc('a.id')->get();

            $statistics = [
                'total_accounts' => $accounts->count(),
                'active_accounts' => $accounts->where('status', 'ACTIVE')->count(),
                'inactive_accounts' => $accounts->whereNotIn('status', ['ACTIVE'])->count(),
            ];

            return response()->json([
                'success' => true,
                'data' => ['client_id' => $id, 'statistics' => $statistics, 'accounts' => $accounts],
            ]);
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Erreur recuperation comptes.', 'error' => $e->getMessage()], 500);
        }
    }
}
