<?php

namespace App\Shared\Foundation\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class NodeUploaderService
{
    /**
     * Sanitize an untrusted client-supplied filename before forwarding to the
     * uploader service. Prevents path traversal, double-extension tricks, and
     * characters that could be interpreted by downstream processors.
     *
     * The Node service derives the stored extension from this name, so we must
     * ensure it reflects a single, safe extension that matches the actual file.
     */
    public static function sanitizeFilename(UploadedFile $file): string
    {
        // Derive extension from detected MIME type (magic bytes + finfo), not the client name
        $ext = self::mimeToExtension(self::resolveMimeType($file));

        // Build a safe stem: base name only (no directory parts), remove control chars,
        // replace anything that is not alphanumeric / dash / underscore / dot
        $clientName = basename($file->getClientOriginalName());
        $clientName = preg_replace('/[\x00-\x1f\x7f]/', '', $clientName) ?? '';
        $stem = pathinfo($clientName, PATHINFO_FILENAME);
        $stem = preg_replace('/[^a-zA-Z0-9_-]/', '_', $stem) ?? 'upload';
        $stem = trim($stem, '_') ?: 'upload';

        return $stem.'.'.$ext;
    }

    /**
     * Resolve MIME from magic bytes first, then finfo. Ignores client-supplied MIME.
     */
    private static function resolveMimeType(UploadedFile $file): string
    {
        $path = $file->getRealPath();
        if (is_string($path) && $path !== '') {
            $header = @file_get_contents($path, false, null, 0, 12);
            if (is_string($header) && $header !== '') {
                $fromMagic = self::mimeFromMagicBytes($header);
                if ($fromMagic !== null) {
                    return $fromMagic;
                }
            }
        }

        $detected = $file->getMimeType() ?? '';

        return $detected !== '' ? $detected : ($file->getClientMimeType() ?? '');
    }

    private static function mimeFromMagicBytes(string $header): ?string
    {
        return match (true) {
            str_starts_with($header, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($header, "\x89PNG\r\n\x1A\n") => 'image/png',
            str_starts_with($header, 'RIFF') && strlen($header) >= 12 && substr($header, 8, 4) === 'WEBP' => 'image/webp',
            str_starts_with($header, '%PDF') => 'application/pdf',
            default => null,
        };
    }

    /**
     * Map server-detected MIME types to safe single extensions.
     * Falls back to 'bin' for unknown types (Node will reject via its allowlist).
     */
    private static function mimeToExtension(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            default => 'bin',
        };
    }

    public function upload(UploadedFile $file, string $context = 'products'): string
    {
        $uploaderUrl = config('services.uploader.url');
        $apiKey = config('services.uploader.api_key');

        if (! is_string($uploaderUrl) || $uploaderUrl === '') {
            throw new RuntimeException('El servicio de almacenamiento no está configurado (ZG_URL).');
        }

        $response = Http::withHeaders(['X-API-KEY' => $apiKey])
            ->attach(
                'files',
                file_get_contents($file->getRealPath()),
                self::sanitizeFilename($file),
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

    public function delete(string $path): void
    {
        if ($path === '') {
            return;
        }

        $uploaderUrl = config('services.uploader.url');
        $apiKey = config('services.uploader.api_key');

        if (! is_string($uploaderUrl) || $uploaderUrl === '') {
            return;
        }

        Http::withHeaders(['X-API-KEY' => $apiKey])
            ->delete(rtrim($uploaderUrl, '/').'/api/delete', ['path' => $path]);
    }

    public function fetch(string $path): \Illuminate\Http\Client\Response
    {
        $uploaderUrl = config('services.uploader.url');
        $apiKey = config('services.uploader.api_key');

        if (! is_string($uploaderUrl) || $uploaderUrl === '') {
            throw new RuntimeException('El servicio de almacenamiento no está configurado (ZG_URL).');
        }

        $response = Http::withHeaders(['X-API-KEY' => $apiKey])
            ->get(rtrim((string) $uploaderUrl, '/').$path);

        if (! $response->successful()) {
            throw new RuntimeException('No se pudo obtener el archivo del servicio de almacenamiento.');
        }

        return $response;
    }
}
