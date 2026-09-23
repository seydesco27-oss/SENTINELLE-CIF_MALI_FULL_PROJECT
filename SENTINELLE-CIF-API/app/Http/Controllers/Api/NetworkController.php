<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AgencyAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
    public function index(Request $request): JsonResponse
    {
        try {
                        /*
             * =========================================================
             * CAISSES
             * =========================================================
             */
            $caisseQuery = DB::table('caisses as ca')
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
                ->orderBy('ca.code');

            $scope = AgencyAccess::scopeFor($request->user());
            if ($scope['type'] === AgencyAccess::CAISSE) {
                $caisseQuery->where('ca.id', $scope['caisse_id'] ?? 0);
            } elseif ($scope['type'] === AgencyAccess::AGENCY) {
                $caisseQuery->whereExists(function ($query) use ($scope): void {
                    $query->selectRaw('1')->from('agencies as scoped_agency')
                        ->whereColumn('scoped_agency.caisse_id', 'ca.id')
                        ->where('scoped_agency.id', $scope['agency_id'] ?? 0);
                });
            }
            $caisses = $caisseQuery->get();


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
            $agencyQuery = DB::table('agencies as ag')
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
                ->orderBy('ag.code');

            AgencyAccess::constrain($agencyQuery, $request, 'ag.id');
            $agencies = $agencyQuery->get();
            // Les compteurs d'une caisse ne doivent pas révéler les agences hors périmètre.
            if ($scope['type'] !== AgencyAccess::PLATFORM) {
                $caisses = $caisses->filter(fn ($caisse) => $agencies->contains('caisse_id', $caisse->id))->values();
                foreach ($caisses as $caisse) {
                    $visible = $agencies->where('caisse_id', $caisse->id);
                    $caisse->agency_count = $visible->count();
                    $caisse->client_count = (int) $visible->sum('client_count');
                }
            }


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
                'client_count' => (int) $agencies->sum('client_count'),
                'transaction_count' => (int) $agencies->sum('transaction_count'),
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
