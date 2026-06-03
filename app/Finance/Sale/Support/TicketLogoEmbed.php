<?php

namespace App\Finance\Sale\Support;

use Illuminate\Support\Facades\Http;

/**
 * Incrusta el logo del tenant en el ticket como data URI para impresión y vistas previas
 * (evita CSP del SPA, CORS y hosts externos bloqueados en iframe).
 */
final class TicketLogoEmbed
{
    public static function embedSrc(?string $logoUrl): ?string
    {
        if ($logoUrl === null || $logoUrl === '') {
            return null;
        }

        if (str_starts_with($logoUrl, 'data:')) {
            return $logoUrl;
        }

        if (str_starts_with($logoUrl, '/')) {
            $logoUrl = url($logoUrl);
        }

        try {
            $response = Http::timeout(8)->get($logoUrl);
            if (! $response->successful()) {
                return $logoUrl;
            }

            $mime = self::normalizeMime(
                $response->header('Content-Type'),
                $logoUrl,
            );

            return 'data:'.$mime.';base64,'.base64_encode($response->body());
        } catch (\Throwable) {
            return $logoUrl;
        }
    }

    private static function normalizeMime(?string $contentType, string $url): string
    {
        if ($contentType !== null && $contentType !== '') {
            return strtok($contentType, ';') ?: 'image/png';
        }

        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));

        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            default => 'image/png',
        };
    }
}
