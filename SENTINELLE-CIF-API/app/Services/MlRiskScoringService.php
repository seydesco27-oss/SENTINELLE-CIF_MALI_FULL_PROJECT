<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class MlRiskScoringService
{
    public function scoreAlert(int $alertId): array
    {
        $alert = DB::table('alerts')
            ->where('id', $alertId)
            ->first(['id', 'client_id', 'transaction_id', 'final_score']);

        if (! $alert) {
            throw new RuntimeException('Alerte introuvable.');
        }

        if (! $alert->transaction_id) {
            throw new RuntimeException('Cette alerte ne possède pas de transaction à scorer.');
        }

        return $this->scoreTransaction((int) $alert->transaction_id, $alertId);
    }

    public function scoreClient(int $clientId): array
    {
        $transactionId = DB::table('transactions as t')
            ->join('accounts as a', 'a.id', '=', 't.account_id')
            ->where('a.client_id', $clientId)
            ->orderByDesc('t.transaction_date')
            ->value('t.id');

        if (! $transactionId) {
            throw new RuntimeException('Aucune transaction disponible pour ce client.');
        }

        return $this->scoreTransaction((int) $transactionId);
    }

    public function scoreTransaction(int $transactionId, ?int $alertId = null): array
    {
        $payload = $this->buildPayload($transactionId);
        if (! $payload) {
            throw new RuntimeException('Impossible de construire le contexte de scoring.');
        }

        $mlUrl = rtrim((string) config('services.ml.url', env('ML_SCORE_URL', 'http://127.0.0.1:8100')), '/');
        $response = Http::timeout(12)->post($mlUrl.'/score', $payload);

        if (! $response->successful()) {
            throw new RuntimeException(
                'Le service ML a refusé le scoring (HTTP '.$response->status().').'
            );
        }

        $raw = $response->json();
        $modelScore = $this->ratio($raw['model_score'] ?? null);
        $ruleScore = $this->ratio($raw['rule_score'] ?? null);
        $screeningScore = $this->ratio($raw['screening_score'] ?? null);
        $fusedScore = $this->ratio($raw['final_score'] ?? null);

        if ($modelScore === null || $fusedScore === null) {
            throw new RuntimeException('Le service ML a renvoyé un résultat incomplet.');
        }

        // Une règle déterministe confirmée ne doit jamais être diluée par la
        // fusion. Le ML peut maintenir ou augmenter la priorité opérationnelle.
        $operationalScore = max($ruleScore ?? 0.0, $fusedScore) * 100;
        $result = [
            'transaction_id' => $transactionId,
            'client_id' => (int) $payload['transaction']['client_id'],
            'alert_id' => $alertId,
            'engine' => (string) ($raw['engine'] ?? 'cif-risk-mlp-v1'),
            'model_score' => round($modelScore * 100, 2),
            'rule_score' => round(($ruleScore ?? 0.0) * 100, 2),
            'screening_score' => round(($screeningScore ?? 0.0) * 100, 2),
            'fused_score' => round($fusedScore * 100, 2),
            'operational_score' => round($operationalScore, 2),
            'model_label' => $this->labelFromPercentage($modelScore * 100),
            'risk_level' => $this->labelFromPercentage($operationalScore),
            'screening_risk_level' => $raw['screening_risk_level'] ?? null,
            'weights' => $raw['weights'] ?? ['model' => 0.65, 'rules' => 0.20, 'screening' => 0.15],
            'factors' => array_values($raw['model_factors'] ?? []),
            'features' => $raw['model_features'] ?? [],
            'scored_at' => now()->toIso8601String(),
        ];

        $this->persist($result);

        return $result;
    }

    public function latestForTransaction(int $transactionId): ?array
    {
        $prediction = DB::table('predictions')
            ->where('transaction_id', $transactionId)
            ->orderByDesc('predicted_at')
            ->orderByDesc('id')
            ->first();

        if (! $prediction) {
            return null;
        }

        $factors = DB::table('prediction_explanations')
            ->where('prediction_id', $prediction->id)
            ->orderByDesc('importance_score')
            ->get()
            ->map(fn ($factor) => [
                'feature_name' => $factor->feature_name,
                'feature_value' => $factor->feature_value,
                'importance' => ((float) $factor->importance_score) / 100,
            ])
            ->all();

        $riskScoreCandidates = DB::table('risk_scores')
            ->where('client_id', $prediction->client_id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get();
        $riskScore = $riskScoreCandidates->first(function ($candidate) use ($transactionId): bool {
            $candidateMetadata = json_decode((string) $candidate->explanation, true);

            return (int) ($candidateMetadata['transaction_id'] ?? 0) === $transactionId;
        });
        $metadata = $riskScore ? json_decode((string) $riskScore->explanation, true) : null;

        return [
            'prediction_id' => (int) $prediction->id,
            'transaction_id' => (int) $prediction->transaction_id,
            'client_id' => (int) $prediction->client_id,
            'model_score' => (float) $prediction->probability,
            'model_label' => $prediction->predicted_risk,
            'rule_score' => isset($metadata['rule_score']) ? (float) $metadata['rule_score'] : null,
            'screening_score' => isset($metadata['screening_score']) ? (float) $metadata['screening_score'] : null,
            'fused_score' => isset($metadata['fused_score']) ? (float) $metadata['fused_score'] : null,
            'operational_score' => $riskScore ? (float) $riskScore->final_score : null,
            'risk_level' => $riskScore?->risk_decision,
            'engine' => $metadata['engine'] ?? null,
            'weights' => $metadata['weights'] ?? null,
            'factors' => $factors,
            'scored_at' => $prediction->predicted_at,
        ];
    }

    private function persist(array $result): void
    {
        DB::transaction(function () use ($result): void {
            $modelId = $this->ensureModelRecord($result['engine']);
            $prediction = DB::table('predictions')
                ->where('transaction_id', $result['transaction_id'])
                ->orderByDesc('id')
                ->first();
            $predictionValues = [
                'model_id' => $modelId,
                'client_id' => $result['client_id'],
                'transaction_id' => $result['transaction_id'],
                'predicted_risk' => $result['model_label'],
                'probability' => $result['model_score'],
                'predicted_at' => now(),
            ];

            if ($prediction) {
                DB::table('predictions')->where('id', $prediction->id)->update($predictionValues);
                $predictionId = (int) $prediction->id;
                DB::table('prediction_explanations')->where('prediction_id', $predictionId)->delete();
            } else {
                $predictionId = (int) DB::table('predictions')->insertGetId($predictionValues);
            }

            $factors = array_slice($result['factors'], 0, 8);
            $maxImportance = array_reduce(
                $factors,
                fn (float $maximum, array $factor): float => max(
                    $maximum,
                    abs((float) ($factor['importance'] ?? 0))
                ),
                0.0
            );
            foreach ($factors as $factor) {
                $relativeImportance = $maxImportance > 0
                    ? abs((float) ($factor['importance'] ?? 0)) / $maxImportance * 100
                    : 0;
                DB::table('prediction_explanations')->insert([
                    'prediction_id' => $predictionId,
                    'feature_name' => (string) ($factor['feature_name'] ?? 'inconnue'),
                    'feature_value' => (string) ($factor['feature_value'] ?? ''),
                    'importance_score' => round(min(99.99, $relativeImportance), 2),
                ]);
            }

            DB::table('risk_assessments')->updateOrInsert(
                [
                    'transaction_id' => $result['transaction_id'],
                    'risk_type' => 'ML_TRANSACTION_RISK',
                    'source' => 'ML_MODEL',
                ],
                [
                    'client_id' => $result['client_id'],
                    'score' => $result['model_score'],
                    'risk_level' => $result['model_label'],
                    'reason' => 'Prédiction comportementale du modèle '.$result['engine'].'.',
                    'created_at' => now(),
                ]
            );

            $metadata = json_encode([
                'transaction_id' => $result['transaction_id'],
                'alert_id' => $result['alert_id'],
                'engine' => $result['engine'],
                'rule_score' => $result['rule_score'],
                'screening_score' => $result['screening_score'],
                'fused_score' => $result['fused_score'],
                'weights' => $result['weights'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $latestRiskScore = DB::table('risk_scores')
                ->where('client_id', $result['client_id'])
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first();
            $latestMetadata = $latestRiskScore
                ? json_decode((string) $latestRiskScore->explanation, true)
                : null;
            $riskValues = [
                'client_id' => $result['client_id'],
                'pep_score' => $result['screening_score'],
                'transaction_score' => $result['rule_score'],
                'ai_score' => $result['model_score'],
                'final_score' => $result['operational_score'],
                'risk_decision' => $result['risk_level'],
                'explanation' => $metadata,
                'created_at' => now(),
            ];
            if ($latestRiskScore && (int) ($latestMetadata['transaction_id'] ?? 0) === $result['transaction_id']) {
                DB::table('risk_scores')->where('id', $latestRiskScore->id)->update($riskValues);
            } else {
                DB::table('risk_scores')->insert($riskValues);
            }

            if ($result['alert_id']) {
                $alert = DB::table('alerts')->where('id', $result['alert_id'])->first();
                if ($alert) {
                    $score = max((float) ($alert->final_score ?? 0), $result['operational_score']);
                    DB::table('alerts')->where('id', $result['alert_id'])->update([
                        'final_score' => round($score, 2),
                        'priority' => $this->labelFromPercentage($score),
                    ]);
                }
            }
        });
    }

    private function ensureModelRecord(string $engine): int
    {
        $model = DB::table('ai_models')
            ->where('model_name', 'Sentinelle CIF Transaction Risk')
            ->where('version', '1.1.0')
            ->first();

        if ($model) {
            return (int) $model->id;
        }

        return (int) DB::table('ai_models')->insertGetId([
            'model_name' => 'Sentinelle CIF Transaction Risk',
            'algorithm' => 'MLPClassifier',
            'version' => '1.1.0',
            'accuracy' => null,
            'training_date' => null,
            'status' => 'ACTIVE',
            'created_at' => now(),
        ]);
    }

    private function buildPayload(int $transactionId): ?array
    {
        $transactionRow = DB::table('transactions as t')
            ->join('accounts as a', 'a.id', '=', 't.account_id')
            ->leftJoin('clients as c', 'c.id', '=', 'a.client_id')
            ->leftJoin('client_individuals as ci', 'ci.client_id', '=', 'c.id')
            ->leftJoin('client_entities as ce', 'ce.client_id', '=', 'c.id')
            ->where('t.id', $transactionId)
            ->select([
                't.id as transaction_id', 't.amount', 't.transaction_type',
                't.transaction_date', 't.channel', 't.country_from', 't.country_to',
                'a.client_id', 'a.account_number',
                DB::raw("CASE WHEN ci.client_id IS NOT NULL THEN TRIM(CONCAT(COALESCE(ci.first_name,''),' ',COALESCE(ci.last_name,''))) WHEN ce.client_id IS NOT NULL THEN ce.legal_name ELSE c.client_number END as customer_name"),
            ])
            ->first();

        if (! $transactionRow) {
            return null;
        }

        $clientId = (int) $transactionRow->client_id;
        $referenceDate = $transactionRow->transaction_date ?: now()->toDateTimeString();
        $history = fn () => DB::table('transactions as historical')
            ->join('accounts as historical_account', 'historical_account.id', '=', 'historical.account_id')
            ->where('historical_account.client_id', $clientId)
            ->where('historical.transaction_date', '<', $referenceDate);
        $stats30 = $history()
            ->whereRaw('historical.transaction_date >= DATE_SUB(?, INTERVAL 30 DAY)', [$referenceDate])
            ->selectRaw('COUNT(*) as count_30d, COALESCE(SUM(historical.amount),0) as volume_30d, COALESCE(AVG(historical.amount),0) as average_30d, COALESCE(STDDEV_POP(historical.amount),0) as stddev_30d')
            ->first();
        $stats24 = $history()
            ->whereRaw('historical.transaction_date >= DATE_SUB(?, INTERVAL 24 HOUR)', [$referenceDate])
            ->selectRaw('COUNT(*) as count_24h, COALESCE(SUM(historical.amount),0) as volume_24h')
            ->first();
        $countriesFrom = $history()->whereNotNull('historical.country_from')->pluck('historical.country_from');
        $countriesTo = $history()->whereNotNull('historical.country_to')->pluck('historical.country_to');
        $countryCount = $countriesFrom->merge($countriesTo)->unique()->count();
        $inDegree = $history()->whereIn('historical.transaction_type', ['DEPOSIT', 'TRANSFER_IN'])
            ->whereNotNull('historical.country_from')->distinct()->count('historical.country_from');
        $outDegree = $history()->whereIn('historical.transaction_type', ['PAYMENT', 'TRANSFER_OUT', 'WITHDRAWAL'])
            ->whereNotNull('historical.country_to')->distinct()->count('historical.country_to');
        $ruleScore = (float) (DB::table('risk_assessments')
            ->where('transaction_id', $transactionId)
            ->where('source', 'AML_RULE_ENGINE')
            ->max('score') ?? 0);
        $sanctionScore = (float) (DB::table('sanction_matches')
            ->where('client_id', $clientId)
            ->max('match_score') ?? 0);

        $transaction = [
            'transaction_id' => $transactionId,
            'client_id' => $clientId,
            'account_number' => $transactionRow->account_number,
            'amount' => (float) $transactionRow->amount,
            'transaction_type' => $transactionRow->transaction_type,
            'transaction_day_of_week' => $transactionRow->transaction_date
                ? (int) date('N', strtotime($transactionRow->transaction_date))
                : (int) date('N'),
            'channel' => $transactionRow->channel,
            'country_from' => $transactionRow->country_from,
            'country_to' => $transactionRow->country_to,
            'previous_transaction_count_30d' => (int) ($stats30->count_30d ?? 0),
            'previous_volume_30d' => (float) ($stats30->volume_30d ?? 0),
            'average_transaction_amount_30d' => (float) ($stats30->average_30d ?? 0),
            'previous_transaction_average_30d' => (float) ($stats30->average_30d ?? 0),
            'transaction_amount_std_30d' => (float) ($stats30->stddev_30d ?? 0),
            'previous_transaction_count_24h' => (int) ($stats24->count_24h ?? 0),
            'previous_volume_24h' => (float) ($stats24->volume_24h ?? 0),
            'country_count_30d' => $countryCount,
            'aml_rule_score' => $ruleScore,
            'max_sanction_match_score' => $sanctionScore,
            'sanction_match_flag' => $sanctionScore > 0 ? 1 : 0,
            'in_degree' => $inDegree,
            'out_degree' => $outDegree,
        ];

        return [
            'transaction' => $transaction,
            'client_name' => $transactionRow->customer_name,
        ];
    }

    private function ratio(mixed $value): ?float
    {
        if ($value === null || ! is_numeric($value)) {
            return null;
        }

        return max(0.0, min(1.0, (float) $value));
    }

    private function labelFromPercentage(float $score): string
    {
        return match (true) {
            $score >= 80 => 'CRITICAL',
            $score >= 60 => 'HIGH',
            $score >= 30 => 'MEDIUM',
            default => 'LOW',
        };
    }
}
