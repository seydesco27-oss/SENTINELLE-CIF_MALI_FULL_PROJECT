<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class DashboardController extends Controller
{
    /**
     * Dashboard global AML
     */
    public function index(): JsonResponse
    {
        try {
            $clients = DB::table('clients')->count();

            $transactions = DB::table('transactions')->count();

            $alerts = DB::table('alerts')->count();

            $openAlerts = DB::table('alerts')
                ->where('status', 'OPEN')
                ->count();

            $blockedClients = DB::table('clients')
                ->where('status', 'BLOCKED')
                ->count();

            $pepClients = DB::table('clients')
                ->where('is_pep', 1)
                ->count();

            return response()->json([
                'success' => true,
                'data' => [
                    'clients' => $clients,
                    'transactions' => $transactions,
                    'alerts' => $alerts,
                    'open_alerts' => $openAlerts,
                    'blocked_clients' => $blockedClients,
                    'pep_clients' => $pepClients,
                ],
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération du dashboard.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}