<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AgencyAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class AuditController extends Controller
{
    /**
     * Journal d'audit.
     *
     * GET /api/v1/audit
     *
     * Filtres :
     * ?user_id=1
     * ?action=UPDATE
     * ?entity=CLIENT
     * ?entity_id=10
     * ?date_from=2026-08-01
     * ?date_to=2026-08-19
     * ?limit=50
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $limit = min(
                max((int) $request->query('limit', 50), 1),
                100
            );

            $query = DB::table('audit_logs as al')
                ->leftJoin('users as u', 'u.id', '=', 'al.user_id')
                ->select([
                    'al.id',
                    'al.user_id',
                    'u.username',
                    'al.action',
                    'al.entity',
                    'al.entity_id',
                    DB::raw("CONCAT(al.entity, '#', al.entity_id) as object_ref"),
                    DB::raw("CASE WHEN al.hash_current IS NOT NULL THEN 'ENREGISTRE' ELSE 'SANS_INTEGRITE' END as result"),
                    'al.old_data',
                    'al.new_data',
                    'al.created_at',
                    'al.hash_previous',
                    'al.hash_current',
                ]);

            AgencyAccess::constrain($query, $request, 'u.agency_id');

            if ($request->filled('user_id')) {
                $query->where(
                    'al.user_id',
                    (int) $request->query('user_id')
                );
            }

            if ($request->filled('action')) {
                $query->where(
                    'al.action',
                    strtoupper(trim($request->query('action')))
                );
            }

            if ($request->filled('entity')) {
                $query->where(
                    'al.entity',
                    strtoupper(trim($request->query('entity')))
                );
            }

            if ($request->filled('entity_id')) {
                $query->where(
                    'al.entity_id',
                    (int) $request->query('entity_id')
                );
            }

            if ($request->filled('date_from')) {
                $query->where(
                    'al.created_at',
                    '>=',
                    $request->query('date_from') . ' 00:00:00'
                );
            }

            if ($request->filled('date_to')) {
                $query->where(
                    'al.created_at',
                    '<=',
                    $request->query('date_to') . ' 23:59:59'
                );
            }

            $logs = $query
                ->orderByDesc('al.created_at')
                ->orderByDesc('al.id')
                ->limit($limit)
                ->get();

            return response()->json([
                'success' => true,
                'count' => $logs->count(),
                'data' => $logs,
            ]);

        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération du journal d’audit.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Détail d'un événement d'audit.
     *
     * GET /api/v1/audit/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $query = DB::table('audit_logs as al')
                ->leftJoin('users as u', 'u.id', '=', 'al.user_id')
                ->select([
                    'al.id',
                    'al.user_id',
                    'u.username',
                    'al.action',
                    'al.entity',
                    'al.entity_id',
                    DB::raw("CONCAT(al.entity, '#', al.entity_id) as object_ref"),
                    DB::raw("CASE WHEN al.hash_current IS NOT NULL THEN 'ENREGISTRE' ELSE 'SANS_INTEGRITE' END as result"),
                    'al.old_data',
                    'al.new_data',
                    'al.created_at',
                    'al.hash_previous',
                    'al.hash_current',
                ])
                ->where('al.id', $id);

            AgencyAccess::constrain($query, $request, 'u.agency_id');
            $log = $query->first();

            if (!$log) {
                return response()->json([
                    'success' => false,
                    'message' => 'Événement d’audit introuvable.',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $log,
            ]);

        } catch (Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération de l’événement d’audit.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
