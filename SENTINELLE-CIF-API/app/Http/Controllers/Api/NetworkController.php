<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class NetworkController extends Controller
{
    /**
     * Vue réseau des caisses et agences.
     *
     * GET /api/v1/network
     *
     * L'endpoint prépare les données nécessaires au frontend
     * pour construire une visualisation réseau.
     */
    public function index(): JsonResponse
    {
        try {
                        /*
             * =========================================================
             * CAISSES
             * =========================================================
             */
            $caisses = DB::table('caisses as ca')
                ->select([
                    'ca.id',
                    'ca.code',
                    'ca.name',
                    'ca.country',
                    'ca.city',
                    'ca.status',
                    'ca.created_at',
                    'ca.updated_at',

                    DB::raw(
                        '(SELECT COUNT(*) FROM agencies ag
                          WHERE ag.caisse_id = ca.id) AS agency_count'
                    ),
                    DB::raw(
                        '(SELECT COUNT(*) FROM clients cl
                          JOIN agencies ag ON ag.id = cl.agency_id
                          WHERE ag.caisse_id = ca.id) AS client_count'
                    ),
                ])
                ->orderBy('ca.code')
                ->get();


            /*
             * =========================================================
             * AGENCES
             * =========================================================
             *
             * Tous les comptages passent par des sous-requêtes
             * corrélées plutôt que des JOIN directs sur clients/
             * accounts/transactions : avec des dizaines de milliers
             * de transactions, un JOIN direct multiplie les lignes
             * avant le GROUP BY et force MySQL à écrire une table
             * temporaire sur disque (cause exacte de l'erreur
             * "No space left on device" observée).
             */
            $agencies = DB::table('agencies as ag')
                ->leftJoin(
                    'caisses as ca',
                    'ca.id',
                    '=',
                    'ag.caisse_id'
                )
                ->select([
                    'ag.id',
                    'ag.code',
                    'ag.name',
                    'ag.city',
                    'ag.created_at',

                    'ca.id as caisse_id',
                    'ca.code as caisse_code',
                    'ca.name as caisse_name',
                    'ca.city as caisse_city',
                    'ca.status as caisse_status',

                    DB::raw(
                        '(SELECT COUNT(*) FROM clients cl
                          WHERE cl.agency_id = ag.id) AS client_count'
                    ),
                    DB::raw(
                        '(SELECT COUNT(*) FROM accounts acc
                          JOIN clients cl ON cl.id = acc.client_id
                          WHERE cl.agency_id = ag.id) AS account_count'
                    ),
                    DB::raw(
                        '(SELECT COUNT(*) FROM transactions tr
                          WHERE tr.agency_id = ag.id) AS transaction_count'
                    ),
                    DB::raw(
                        '(SELECT COALESCE(SUM(tr.amount), 0) FROM transactions tr
                          WHERE tr.agency_id = ag.id) AS transaction_volume'
                    ),
                    DB::raw(
                        '(SELECT COUNT(DISTINCT al.id) FROM alerts al
                          JOIN clients cl ON cl.id = al.client_id
                          WHERE cl.agency_id = ag.id) AS alert_count'
                    ),
                    DB::raw(
                        "(SELECT COUNT(DISTINCT al.id) FROM alerts al
                          JOIN clients cl ON cl.id = al.client_id
                          WHERE cl.agency_id = ag.id
                          AND al.priority = 'CRITICAL') AS critical_alert_count"
                    ),
                ])
                ->orderBy('ag.code')
                ->get();


            /*
             * =========================================================
             * STATISTIQUES GLOBALES
             * =========================================================
             */
            $summary = [
                'caisse_count' => $caisses->count(),
                'agency_count' => $agencies->count(),
                'active_caisse_count' => $caisses
                    ->where('status', 'ACTIVE')
                    ->count(),
                'inactive_caisse_count' => $caisses
                    ->where('status', 'INACTIVE')
                    ->count(),
                'suspended_caisse_count' => $caisses
                    ->where('status', 'SUSPENDED')
                    ->count(),
                'client_count' => (int) DB::table('clients')->count(),
                'transaction_count' => (int) DB::table('transactions')->count(),
            ];

            /*
             * =========================================================
             * RELATIONS POUR LE GRAPHE FRONTEND
             * =========================================================
             */
            $links = $agencies
                ->map(function ($agency) {
                    return [
                        'source' => 'caisse:' . $agency->caisse_id,
                        'target' => 'agency:' . $agency->id,
                        'type' => 'CAISSE_AGENCY',
                    ];
                })
                ->values();

            return response()->json([
                'success' => true,
                'data' => [
                    'summary' => $summary,
                    'caisses' => $caisses,
                    'agencies' => $agencies,
                    'links' => $links,
                ],
            ]);

        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération du réseau.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
