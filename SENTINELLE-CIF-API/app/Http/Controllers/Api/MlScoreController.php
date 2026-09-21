<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Pont Laravel → microservice ML (couche 2).
 * Construit un payload transactionnel depuis la base SENTINELLE puis appelle POST {ML_URL}/score
 */
class MlScoreController extends \App\Http\Controllers\Controller
{
    public function health(): JsonResponse
    {
        $mlUrl = rtrim(env('ML_SCORE_URL', 'http://127.0.0.1:8100'), '/');

        try {
            $response = Http::timeout(3)->get($mlUrl . '/health');

            return response()->json([
                'success' => $response->successful(),
                'data' => $response->json(),
                'message' => $response->successful()
                    ? 'Service ML disponible.'
                    : 'Service ML indisponible.',
            ], $response->successful() ? 200 : 503);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Service ML indisponible.',
            ], 503);
        }
    }

    public function score(Request $request): JsonResponse
    {
        try {
            $transactionId = $request->integer('transaction_id');
            $clientId = $request->integer('client_id');
            $alertId = $request->integer('alert_id');

            if ($alertId > 0 && $transactionId <= 0) {
                $transactionId = (int) DB::table('alerts')
                    ->where('id', $alertId)
                    ->value('transaction_id');
            }

            if ($transactionId <= 0 && $clientId <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'alert_id, transaction_id ou client_id requis.',
                ], 422);
            }

            $payload = $transactionId > 0
                ? $this->buildFromTransaction($transactionId)
                : $this->buildFromClient($clientId);

            if (!$payload) {
                return response()->json([
                    'success' => false,
                    'message' => 'Impossible de construire le contexte de scoring.',
                ], 404);
            }

            $mlUrl = rtrim(env('ML_SCORE_URL', 'http://127.0.0.1:8100'), '/');
            $response = Http::timeout(8)->post($mlUrl . '/score', $payload);

            if (!$response->successful()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Service ML indisponible ou erreur de scoring.',
                    'upstream_status' => $response->status(),
                    'upstream_body' => $response->json() ?: $response->body(),
                    'payload_sent' => $payload,
                ], 502);
            }

            $data = $response->json();

            return response()->json([
                'success' => true,
                'data' => [
                    'transaction_id' => $payload['transaction']['transaction_id'] ?? null,
                    'client_id' => $payload['transaction']['client_id'] ?? $clientId,
                    'model_score' => $data['model_score'] ?? null,
                    'rule_score' => $data['rule_score'] ?? null,
                    'screening_score' => $data['screening_score'] ?? null,
                    'final_score' => $data['final_score'] ?? null,
                    'screening_risk_level' => $data['screening_risk_level'] ?? null,
                    'engine' => $data['engine'] ?? null,
                    'label' => $this->labelFromScore($data['final_score'] ?? $data['model_score'] ?? null),
                ],
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur scoring ML.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function labelFromScore($score): string
    {
        if ($score === null) {
            return 'UNKNOWN';
        }
        $s = (float) $score;
        if ($s >= 0.8) {
            return 'CRITICAL';
        }
        if ($s >= 0.6) {
            return 'HIGH';
        }
        if ($s >= 0.3) {
            return 'MEDIUM';
        }
        return 'LOW';
    }

    private function buildFromTransaction(int $transactionId): ?array
    {
        $t = DB::table('transactions as t')
            ->join('accounts as ac', 'ac.id', '=', 't.account_id')
            ->leftJoin('clients as c', 'c.id', '=', 'ac.client_id')
            ->leftJoin('client_individuals as ci', 'ci.client_id', '=', 'c.id')
            ->leftJoin('client_entities as ce', 'ce.client_id', '=', 'c.id')
            ->where('t.id', $transactionId)
            ->select([
                't.id as transaction_id',
                't.amount',
                't.transaction_type',
                't.transaction_date',
                't.channel',
                't.country_from',
                't.country_to',
                'ac.client_id',
                'c.client_number',
                'c.is_pep',
                DB::raw("CASE WHEN ci.client_id IS NOT NULL THEN TRIM(CONCAT(COALESCE(ci.first_name,''),' ',COALESCE(ci.last_name,''))) WHEN ce.client_id IS NOT NULL THEN ce.legal_name ELSE c.client_number END as customer_name"),
            ])
            ->first();

        if (!$t) {
            return null;
        }

        $clientId = (int) $t->client_id;
        $dayOfWeek = $t->transaction_date
            ? (int) date('N', strtotime($t->transaction_date))
            : (int) date('N');

        $agg30 = DB::table('transactions as t2')
            ->join('accounts as ac2', 'ac2.id', '=', 't2.account_id')
            ->where('ac2.client_id', $clientId)
            ->where('t2.transaction_date', '>=', DB::raw("DATE_SUB(COALESCE((SELECT transaction_date FROM transactions WHERE id = {$transactionId}), NOW()), INTERVAL 30 DAY)"))
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(t2.amount),0) as vol')
            ->first();

        $agg24 = DB::table('transactions as t2')
            ->join('accounts as ac2', 'ac2.id', '=', 't2.account_id')
            ->where('ac2.client_id', $clientId)
            ->where('t2.transaction_date', '>=', DB::raw("DATE_SUB(COALESCE((SELECT transaction_date FROM transactions WHERE id = {$transactionId}), NOW()), INTERVAL 24 HOUR)"))
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(t2.amount),0) as vol')
            ->first();

        $amlRuleScore = (float) (DB::table('risk_assessments')
            ->where('transaction_id', $transactionId)
            ->where('source', 'AML_RULE_ENGINE')
            ->max('score') ?? 0);

        $maxSanction = (float) (DB::table('sanction_matches')
            ->where('client_id', $clientId)
            ->max('match_score') ?? 0);

        $transaction = [
            'transaction_id' => $t->transaction_id,
            'client_id' => $clientId,
            'amount' => (float) $t->amount,
            'transaction_type' => $t->transaction_type,
            'transaction_day_of_week' => $dayOfWeek,
            'channel' => $t->channel,
            'previous_transaction_count_30d' => (int) ($agg30->cnt ?? 0),
            'previous_volume_30d' => (float) ($agg30->vol ?? 0),
            'previous_transaction_count_24h' => (int) ($agg24->cnt ?? 0),
            'previous_volume_24h' => (float) ($agg24->vol ?? 0),
            'country_count_30d' => 1,
            'aml_rule_score' => $amlRuleScore,
            'max_sanction_match_score' => $maxSanction,
            'sanction_match_flag' => $maxSanction > 0 ? 1 : 0,
            'in_degree' => 0,
            'out_degree' => 0,
        ];

        return [
            'transaction' => $transaction,
            'client_name' => $t->customer_name,
            'features' => $transaction,
        ];
    }

    private function buildFromClient(int $clientId): ?array
    {
        // Prend la dernière transaction du client comme ancre de scoring
        $txId = DB::table('transactions as t')
            ->join('accounts as ac', 'ac.id', '=', 't.account_id')
            ->where('ac.client_id', $clientId)
            ->orderByDesc('t.transaction_date')
            ->value('t.id');

        if (!$txId) {
            return null;
        }

        return $this->buildFromTransaction((int) $txId);
    }
}
