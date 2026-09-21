<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    public function check(): JsonResponse
    {
        try {
            $database = DB::connection()->getDatabaseName();

            return response()->json([
                'success' => true,
                'status' => 'healthy',
                'service' => 'SENTINELLE-CIF-API',
                'database' => $database,
                'timestamp' => now()->toIso8601String(),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'status' => 'unhealthy',
                'service' => 'SENTINELLE-CIF-API',
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ], 500);
        }
    }
}