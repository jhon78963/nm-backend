<?php

namespace App\Shared\Foundation\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class NodeUploaderService
{
    public function upload(UploadedFile $file, string $context = 'products'): string
    {
        $uploaderUrl = config('services.uploader.url');
        $apiKey = config('services.uploader.api_key');

        $response = Http::withHeaders(['X-API-KEY' => $apiKey])
            ->attach(
                'files',
                file_get_contents($file->getRealPath()),
                $file->getClientOriginalName(),
            )
            ->post(rtrim((string) $uploaderUrl, '/').'/api/upload', ['context' => $context]);

        if (! $response->successful()) {
            throw new RuntimeException('No se pudo subir el archivo al servicio de almacenamiento.');
        }

        $path = $response->json('files.0.url');

        if (! is_string($path) || $path === '') {
            throw new RuntimeException('El servicio de almacenamiento no devolvió una ruta válida.');
        }

        return $path;
    }
}
