<?php

namespace App\Ai\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AiEngineService
{
    /**
     * Solicita optimización de precio al microservicio nm_ai_engine (Python).
     *
     * @param  array{product_id: int, current_cost: float, category: string, sales_last_month: int}  $payload
     * @return array<string, mixed>
     */
    public function optimizePrice(array $payload): array
    {
        return $this->post('/api/v1/predict/price', $payload);
    }

    /**
     * Solicita predicción de restock al microservicio nm_ai_engine (Python).
     *
     * @param  array{product_id: int, current_stock: int, horizon_days?: int}  $payload
     * @return array<string, mixed>
     */
    public function predictDemand(array $payload): array
    {
        return $this->post('/api/v1/predict/demand', $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload): array
    {
        $baseUrl = config('services.ai_engine.url');
        $apiKey = config('services.ai_engine.key');

        if (! is_string($baseUrl) || $baseUrl === '') {
            throw new RuntimeException('El motor de IA no está configurado (AI_ENGINE_URL).');
        }

        if (! is_string($apiKey) || $apiKey === '') {
            throw new RuntimeException('La clave del motor de IA no está configurada (AI_ENGINE_API_KEY).');
        }

        $url = rtrim($baseUrl, '/').$path;
        $timeout = (int) config('services.ai_engine.timeout', 30);

        $response = Http::withHeaders(['X-API-Key' => $apiKey])
            ->acceptJson()
            ->timeout($timeout)
            ->post($url, $payload);

        if (! $response->successful()) {
            Log::warning('AI engine request failed', [
                'url' => $url,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new RuntimeException(sprintf(
                'Error al comunicarse con el motor de IA (HTTP %s).',
                $response->status(),
            ));
        }

        return $this->decodeJsonResponse($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonResponse(Response $response): array
    {
        $data = $response->json();

        if (! is_array($data)) {
            throw new RuntimeException('El motor de IA devolvió una respuesta inválida.');
        }

        return $data;
    }
}
