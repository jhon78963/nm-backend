<?php

namespace App\Ai\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AiEngineClient
{
    public function predictPrice(array $payload): array
    {
        return $this->post('/api/v1/predict/price', $payload);
    }

    public function predictDemand(array $payload): array
    {
        return $this->post('/api/v1/predict/demand', $payload);
    }

    /**
     * @param  array{items: list<array<string, mixed>>}  $payload
     * @return array<string, mixed>
     */
    public function predictBulk(array $payload): array
    {
        return $this->post('/api/v1/predict/bulk', $payload, (int) config('ai.bulk_timeout', 120));
    }

    private function post(string $path, array $payload, ?int $timeout = null): array
    {
        $baseUrl = (string) config('ai.engine_url');
        $apiKey = (string) config('ai.api_key');

        if ($baseUrl === '' || $apiKey === '') {
            throw new RuntimeException('Motor de IA no configurado (AI_ENGINE_URL / AI_ENGINE_API_KEY).');
        }

        try {
            $response = Http::timeout($timeout ?? (int) config('ai.timeout', 30))
                ->withHeaders(['X-API-Key' => $apiKey])
                ->acceptJson()
                ->post("{$baseUrl}{$path}", $payload)
                ->throw();
        } catch (RequestException $exception) {
            $message = $exception->response?->json('detail')
                ?? $exception->response?->json('message')
                ?? $exception->getMessage();

            throw new RuntimeException((string) $message, 0, $exception);
        }

        return $response->json() ?? [];
    }
}
