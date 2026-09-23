<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Client Groq unique : TLS, retries 429/réseau, et bascule de modèle
 * (les quotas TPM Groq sont séparés par modèle).
 */
class GroqChatClient
{
    public function complete(array $payload, ?string $forcedModel = null): array
    {
        $apiKey = $this->apiKey();
        if ($apiKey === '') {
            throw new RuntimeException('GROQ_API_KEY absente.');
        }

        $url = $this->baseUrl().'/chat/completions';
        $models = $forcedModel ? [$forcedModel] : $this->models();
        $last = null;

        foreach ($models as $model) {
            $payload['model'] = $model;
            try {
                $json = $this->postWithRetries($url, $payload);
                $json['_used_model'] = $model;

                return $json;
            } catch (Throwable $e) {
                $last = $e;
                if (! $this->shouldTryNextModel($e)) {
                    throw $e;
                }
            }
        }

        throw $last ?? new RuntimeException('Réponse fournisseur indisponible.');
    }

    public function models(): array
    {
        $primary = (string) config('services.groq.model', 'openai/gpt-oss-120b');
        $fallbacks = config('services.groq.fallback_models', []);
        if (is_string($fallbacks)) {
            $fallbacks = array_filter(array_map('trim', explode(',', $fallbacks)));
        }
        if (! is_array($fallbacks)) {
            $fallbacks = [];
        }

        return array_values(array_unique(array_filter([$primary, ...$fallbacks])));
    }

    public function apiKey(): string
    {
        return (string) config('services.groq.key', '');
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.groq.base_url', 'https://api.groq.com/openai/v1'), '/');
    }

    private function http(): PendingRequest
    {
        $http = Http::withToken($this->apiKey())
            ->acceptJson()
            ->timeout(45)
            ->connectTimeout(8);

        $ca = (string) config('services.groq.ca_bundle', storage_path('certificates/cacert.pem'));
        if ($ca !== '' && is_file($ca)) {
            $http = $http->withOptions(['verify' => $ca]);
        }

        return $http;
    }

    private function postWithRetries(string $url, array $payload): array
    {
        // Retry once on transient failures. A rate limit is handled faster by
        // switching to the next model, whose Groq quota is independent.
        $attempts = 2;
        for ($i = 1; $i <= $attempts; $i++) {
            try {
                $response = $this->http()->post($url, $payload);
            } catch (ConnectionException $e) {
                if ($i < $attempts) {
                    usleep(500_000);

                    continue;
                }

                throw $e;
            }

            if ($response->successful()) {
                $json = $response->json();
                if (! is_array($json) || ! isset($json['choices'][0]['message'])) {
                    throw new RuntimeException('Réponse LLM vide ou non conforme.');
                }
                $message = $json['choices'][0]['message'];
                $hasContent = trim((string) ($message['content'] ?? '')) !== '';
                $hasToolCalls = is_array($message['tool_calls'] ?? null)
                    && $message['tool_calls'] !== [];
                if (! $hasContent && ! $hasToolCalls) {
                    throw new RuntimeException('Réponse LLM vide ou non conforme.');
                }
                if (($json['choices'][0]['finish_reason'] ?? '') === 'length') {
                    throw new RuntimeException('Réponse LLM tronquée.');
                }

                return $json;
            }

            $status = $response->status();
            if (in_array($status, [502, 503, 504], true) && $i < $attempts) {
                $retryAfter = (int) $response->header('Retry-After');
                $wait = $retryAfter > 0 ? min($retryAfter, 2) : 1;
                sleep(max(1, $wait));

                continue;
            }

            // Le corps peut répéter le prompt ou des données CIF. On ne
            // remonte que le statut et le code technique du fournisseur.
            $code = preg_replace(
                '/[^a-zA-Z0-9_-]/',
                '',
                (string) $response->json('error.code', '')
            );
            throw new RuntimeException(
                'Réponse fournisseur: HTTP '.$status.($code !== '' ? ' code='.$code : '')
            );
        }

        throw new RuntimeException('Réponse fournisseur indisponible.');
    }

    private function shouldTryNextModel(Throwable $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'HTTP 429')
            || str_contains($message, 'HTTP 503')
            || str_contains($message, 'HTTP 502')
            || str_contains($message, 'HTTP 504')
            || str_contains($message, 'code=tool_use_failed')
            || str_contains($message, 'HTTP 404')
            || str_contains($message, 'Réponse LLM vide')
            || str_contains($message, 'Réponse LLM tronquée');
    }
}
