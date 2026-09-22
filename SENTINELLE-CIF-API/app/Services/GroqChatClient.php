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

        $url = $this->baseUrl() . '/chat/completions';
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
                if (!$this->shouldTryNextModel($e)) {
                    throw $e;
                }
            }
        }

        throw $last ?? new RuntimeException('Réponse fournisseur indisponible.');
    }

    public function models(): array
    {
        $primary = (string) config('services.groq.model', 'llama-3.3-70b-versatile');
        $fallbacks = config('services.groq.fallback_models', []);
        if (is_string($fallbacks)) {
            $fallbacks = array_filter(array_map('trim', explode(',', $fallbacks)));
        }
        if (!is_array($fallbacks)) {
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
            ->connectTimeout(12)
            ->retry(3, 800, function (Throwable $exception): bool {
                return $exception instanceof ConnectionException;
            });

        $ca = (string) config('services.groq.ca_bundle', storage_path('certificates/cacert.pem'));
        if ($ca !== '' && is_file($ca)) {
            $http = $http->withOptions(['verify' => $ca]);
        }

        return $http;
    }

    private function postWithRetries(string $url, array $payload): array
    {
        $attempts = 4;
        for ($i = 1; $i <= $attempts; $i++) {
            $response = $this->http()->post($url, $payload);
            if ($response->successful()) {
                $json = $response->json();
                if (!is_array($json)) {
                    throw new RuntimeException('Réponse LLM vide ou non conforme.');
                }

                return $json;
            }

            $status = $response->status();
            if (in_array($status, [429, 502, 503], true) && $i < $attempts) {
                $retryAfter = (int) $response->header('Retry-After');
                $wait = $retryAfter > 0 ? min($retryAfter, 12) : min(12, 2 ** $i);
                sleep(max(1, $wait));
                continue;
            }

            throw new RuntimeException('Réponse fournisseur: HTTP ' . $status);
        }

        throw new RuntimeException('Réponse fournisseur: HTTP 429');
    }

    private function shouldTryNextModel(Throwable $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'HTTP 429')
            || str_contains($message, 'HTTP 503')
            || str_contains($message, 'HTTP 502')
            || str_contains($message, 'HTTP 400')
            || str_contains($message, 'HTTP 404')
            || $e instanceof ConnectionException;
    }
}
