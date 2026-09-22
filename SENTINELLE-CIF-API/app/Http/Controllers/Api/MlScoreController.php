<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MlRiskScoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Throwable;

class MlScoreController extends Controller
{
    public function __construct(
        private readonly MlRiskScoringService $scoring
    ) {
    }

    public function health(): JsonResponse
    {
        $mlUrl = rtrim((string) config('services.ml.url', env('ML_SCORE_URL', 'http://127.0.0.1:8100')), '/');

        try {
            $response = Http::timeout(3)->get($mlUrl.'/health');

            return response()->json([
                'success' => $response->successful(),
                'data' => $response->json(),
                'message' => $response->successful()
                    ? 'Service ML disponible.'
                    : 'Service ML indisponible.',
            ], $response->successful() ? 200 : 503);
        } catch (Throwable) {
            return response()->json([
                'success' => false,
                'message' => 'Service ML indisponible.',
            ], 503);
        }
    }

    public function score(Request $request): JsonResponse
    {
        $request->validate([
            'alert_id' => ['nullable', 'integer', 'min:1'],
            'transaction_id' => ['nullable', 'integer', 'min:1'],
            'client_id' => ['nullable', 'integer', 'min:1'],
        ]);

        if (! $request->filled('alert_id')
            && ! $request->filled('transaction_id')
            && ! $request->filled('client_id')) {
            return response()->json([
                'success' => false,
                'message' => 'alert_id, transaction_id ou client_id requis.',
            ], 422);
        }

        try {
            $data = match (true) {
                $request->integer('alert_id') > 0 => $this->scoring->scoreAlert($request->integer('alert_id')),
                $request->integer('transaction_id') > 0 => $this->scoring->scoreTransaction($request->integer('transaction_id')),
                default => $this->scoring->scoreClient($request->integer('client_id')),
            };

            return response()->json([
                'success' => true,
                'message' => 'Scoring ML exécuté et enregistré.',
                'data' => $data,
            ]);
        } catch (Throwable $error) {
            return response()->json([
                'success' => false,
                'message' => 'Le scoring ML n’a pas pu être exécuté.',
                'error' => $error->getMessage(),
            ], 502);
        }
    }
}
