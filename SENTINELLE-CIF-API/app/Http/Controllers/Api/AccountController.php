<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AgencyAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class AccountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $limit = min(max((int) $request->query('limit', 50), 1), 100);

            $query = DB::table('accounts as a')
                ->leftJoin('clients as c', 'c.id', '=', 'a.client_id')
                ->leftJoin('client_individuals as ci', 'ci.client_id', '=', 'c.id')
                ->leftJoin('client_entities as ce', 'ce.client_id', '=', 'c.id')
                ->leftJoin('risk_levels as rl', 'rl.id', '=', 'c.risk_level_id')
                ->leftJoin('agencies as ag', 'ag.id', '=', 'c.agency_id')
                ->leftJoin('caisses as ca', 'ca.id', '=', 'ag.caisse_id')
                ->leftJoin('users as mgr', 'mgr.id', '=', 'a.account_manager_id')
                ->leftJoin('roles as mgr_role', 'mgr_role.id', '=', 'mgr.role_id')
                ->select([
                    'a.id', 'a.client_id', 'a.account_number', 'a.account_type',
                    'a.opening_balance', 'a.current_balance', 'a.currency', 'a.status', 'a.opened_at',
                    'a.account_manager_id',
                    'mgr.username as manager_username', 'mgr.agency_id as manager_agency_id',
                    'mgr_role.name as manager_role',
                    'c.client_number', 'c.client_type', 'c.is_pep', 'c.risk_score',
                    'rl.code as risk_level',
                    'ag.id as agency_id', 'ag.code as agency_code', 'ag.name as agency_name', 'ag.city as agency_city',
                    'ca.id as caisse_id', 'ca.code as caisse_code', 'ca.name as caisse_name', 'ca.city as caisse_city',
                    DB::raw("CASE WHEN ci.client_id IS NOT NULL THEN TRIM(CONCAT(COALESCE(ci.first_name,''), ' ', COALESCE(ci.last_name,''))) WHEN ce.client_id IS NOT NULL THEN ce.legal_name ELSE CONCAT('CLIENT #', c.id) END AS client_name"),
                    DB::raw('(SELECT COUNT(*) FROM transactions t WHERE t.account_id = a.id) AS transaction_count'),
                    DB::raw("(SELECT COALESCE(SUM(t.amount),0) FROM transactions t WHERE t.account_id = a.id AND UPPER(COALESCE(t.transaction_status,'COMPLETED')) NOT IN ('CANCELLED','CANCELED','REVERSED','VOID')) AS transaction_volume"),
                    DB::raw('(SELECT COUNT(*) FROM alerts al WHERE al.client_id = a.client_id) AS alert_count'),
                ]);
            AgencyAccess::constrain($query, $request, 'c.agency_id');

            if ($request->filled('status')) $query->where('a.status', strtoupper(trim($request->query('status'))));
            if ($request->filled('account_type')) $query->where('a.account_type', $request->query('account_type'));
            if ($request->filled('client_id')) $query->where('a.client_id', (int) $request->query('client_id'));
            if ($request->filled('currency')) $query->where('a.currency', strtoupper(trim($request->query('currency'))));
            if ($request->filled('agency_id')) $query->where('c.agency_id', (int) $request->query('agency_id'));
            if ($request->filled('caisse_id')) $query->where('ag.caisse_id', (int) $request->query('caisse_id'));
            if ($request->filled('account_manager_id')) $query->where('a.account_manager_id', (int) $request->query('account_manager_id'));

            if ($request->filled('search')) {
                $search = trim($request->query('search'));
                $query->where(function ($q) use ($search) {
                    $q->where('a.account_number', 'LIKE', "%{$search}%")
                        ->orWhere('c.client_number', 'LIKE', "%{$search}%")
                        ->orWhere('ci.first_name', 'LIKE', "%{$search}%")
                        ->orWhere('ci.last_name', 'LIKE', "%{$search}%")
                        ->orWhere('ce.legal_name', 'LIKE', "%{$search}%")
                        ->orWhere('mgr.username', 'LIKE', "%{$search}%");
                });
            }

            $accounts = $query->orderByDesc('a.id')->limit($limit)->get();
            if (AgencyAccess::restrictedAgencyId($request) !== null) {
                $accounts->each(function ($account): void {
                    unset(
                        $account->is_pep,
                        $account->risk_score,
                        $account->risk_level,
                        $account->alert_count
                    );
                });
            }

            return response()->json(['success' => true, 'count' => $accounts->count(), 'data' => $accounts]);
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors de la recuperation des comptes.', 'error' => $e->getMessage()], 500);
        }
    }

    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $accountQuery = DB::table('accounts as a')
                ->leftJoin('clients as c', 'c.id', '=', 'a.client_id')
                ->leftJoin('client_individuals as ci', 'ci.client_id', '=', 'c.id')
                ->leftJoin('client_entities as ce', 'ce.client_id', '=', 'c.id')
                ->leftJoin('risk_levels as rl', 'rl.id', '=', 'c.risk_level_id')
                ->leftJoin('agencies as ag', 'ag.id', '=', 'c.agency_id')
                ->leftJoin('caisses as ca', 'ca.id', '=', 'ag.caisse_id')
                ->leftJoin('users as mgr', 'mgr.id', '=', 'a.account_manager_id')
                ->leftJoin('roles as mgr_role', 'mgr_role.id', '=', 'mgr.role_id')
                ->select([
                    'a.id', 'a.client_id', 'a.account_number', 'a.account_type',
                    'a.opening_balance', 'a.current_balance', 'a.currency', 'a.status', 'a.opened_at',
                    'a.account_manager_id',
                    'mgr.username as manager_username', 'mgr.agency_id as manager_agency_id', 'mgr_role.name as manager_role',
                    'c.client_number', 'c.client_type', 'c.status as client_status', 'c.phone', 'c.email',
                    'c.is_pep', 'c.risk_score', 'rl.code as risk_level', 'rl.label as risk_level_label',
                    'ag.id as agency_id', 'ag.code as agency_code', 'ag.name as agency_name', 'ag.city as agency_city',
                    'ca.id as caisse_id', 'ca.code as caisse_code', 'ca.name as caisse_name', 'ca.city as caisse_city',
                    DB::raw("CASE WHEN ci.client_id IS NOT NULL THEN TRIM(CONCAT(COALESCE(ci.first_name,''), ' ', COALESCE(ci.last_name,''))) WHEN ce.client_id IS NOT NULL THEN ce.legal_name ELSE CONCAT('CLIENT #', c.id) END AS client_name"),
                    'ci.first_name', 'ci.last_name', 'ci.gender', 'ci.birth_date', 'ci.nationality', 'ci.profession', 'ci.activity_sector',
                    'ce.legal_name', 'ce.entity_type', 'ce.nationality as entity_nationality', 'ce.activity_sector as entity_activity_sector',
                ])
                ->where('a.id', $id);
            AgencyAccess::constrain($accountQuery, $request, 'c.agency_id');
            $account = $accountQuery->first();

            if (!$account) {
                return response()->json(['success' => false, 'message' => 'Compte introuvable.'], 404);
            }

            $statistics = DB::table('transactions')->where('account_id', $id)
                ->selectRaw('COUNT(*) AS transaction_count')
                ->selectRaw("COALESCE(SUM(CASE WHEN UPPER(COALESCE(transaction_status,'COMPLETED')) NOT IN ('CANCELLED','CANCELED','REVERSED','VOID') THEN amount ELSE 0 END),0) AS transaction_volume")
                ->selectRaw("COALESCE(AVG(CASE WHEN UPPER(COALESCE(transaction_status,'COMPLETED')) NOT IN ('CANCELLED','CANCELED','REVERSED','VOID') THEN amount END),0) AS average_transaction_amount")
                ->first();

            $alertCount = DB::table('alerts')->where('client_id', $account->client_id)->count();
            $riskAssessmentCount = DB::table('risk_assessments as ra')
                ->join('transactions as t', 't.id', '=', 'ra.transaction_id')
                ->where('t.account_id', $id)->count();

            $isRestrictedAgent = AgencyAccess::restrictedAgencyId($request) !== null;
            if ($isRestrictedAgent) {
                unset(
                    $account->is_pep,
                    $account->risk_score,
                    $account->risk_level,
                    $account->risk_level_label
                );
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'account' => $account,
                    'balance' => [
                        'opening_balance' => $account->opening_balance,
                        'current_balance' => $account->current_balance,
                        'currency' => $account->currency,
                    ],
                    'manager' => $account->account_manager_id ? [
                        'id' => $account->account_manager_id,
                        'username' => $account->manager_username,
                        'role' => $account->manager_role,
                        'agency_id' => $account->manager_agency_id,
                    ] : null,
                    'statistics' => [
                        'transaction_count' => (int) ($statistics->transaction_count ?? 0),
                        'transaction_volume' => $statistics->transaction_volume ?? 0,
                        'average_transaction_amount' => $statistics->average_transaction_amount ?? 0,
                        'alert_count' => $isRestrictedAgent ? null : $alertCount,
                        'risk_assessment_count' => $isRestrictedAgent ? null : $riskAssessmentCount,
                    ],
                ],
            ]);
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors de la recuperation du compte.', 'error' => $e->getMessage()], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $clientId = (int) $request->input('client_id');
            $accountNumber = trim((string) $request->input('account_number'));

            if ($clientId <= 0) {
                return response()->json(['success' => false, 'message' => 'client_id obligatoire.'], 422);
            }
            if ($accountNumber === '') {
                return response()->json(['success' => false, 'message' => 'account_number obligatoire.'], 422);
            }

            $accountType = $request->input('account_type', 'SAVINGS');
            $openingBalance = $request->input('opening_balance', 0);
            $currency = $request->input('currency', 'XOF');
            $managerId = $request->filled('account_manager_id') ? (int) $request->input('account_manager_id') : null;

            if ($managerId !== null && $managerId > 0) {
                if (!DB::table('users')->where('id', $managerId)->exists()) {
                    return response()->json(['success' => false, 'message' => 'account_manager_id introuvable.'], 422);
                }
            } else {
                $managerId = null;
            }

            DB::select(
                'CALL sp_account_open(?, ?, ?, ?, ?, ?, @p_account_id, @p_status, @p_message)',
                [$clientId, $accountNumber, $accountType, $openingBalance, $currency, $managerId]
            );

            $out = DB::selectOne('SELECT @p_account_id AS account_id, @p_status AS status, @p_message AS message');

            if (!$out || strtoupper((string) $out->status) !== 'SUCCESS') {
                return response()->json([
                    'success' => false,
                    'message' => $out->message ?? 'Echec ouverture de compte.',
                    'status' => $out->status ?? 'ERROR',
                ], 422);
            }

            $account = DB::table('accounts as a')
                ->leftJoin('users as mgr', 'mgr.id', '=', 'a.account_manager_id')
                ->select(['a.*', 'mgr.username as manager_username'])
                ->where('a.id', (int) $out->account_id)
                ->first();

            return response()->json(['success' => true, 'message' => $out->message, 'data' => $account], 201);
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors de l\'ouverture du compte.', 'error' => $e->getMessage()], 500);
        }
    }

    public function managers(Request $request): JsonResponse
    {
        try {
            $roles = ['AGENT', 'SUPERVISOR'];
            if ($request->filled('role')) {
                $roles = [strtoupper(trim($request->query('role')))];
            }

            $query = DB::table('users as u')
                ->leftJoin('roles as r', 'r.id', '=', 'u.role_id')
                ->leftJoin('agencies as ag', 'ag.id', '=', 'u.agency_id')
                ->whereIn('r.name', $roles)
                ->select([
                    'u.id', 'u.username', 'u.agency_id',
                    'ag.code as agency_code', 'ag.name as agency_name', 'r.name as role',
                ])
                ->orderBy('u.username');

            if ($request->filled('agency_id')) {
                $query->where('u.agency_id', (int) $request->query('agency_id'));
            }

            $managers = $query->get();

            return response()->json(['success' => true, 'count' => $managers->count(), 'data' => $managers]);
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Erreur recuperation gestionnaires.', 'error' => $e->getMessage()], 500);
        }
    }
}
