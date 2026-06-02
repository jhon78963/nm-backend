<?php

namespace App\Finance\Sale\Support;

/**
 * Enmascara PII (RUC, DNI, nombres, direcciones) en payloads persistidos o logueados de SUNAT.
 */
final class SunatLogRedactor
{
    /** @var list<string> */
    private const PII_KEYS = [
        'document_number',
        'numero',
        'dni',
        'ruc',
        'nombre',
        'nombres',
        'name',
        'razonSocial',
        'razon_social',
        'apellidoPaterno',
        'apellidoMaterno',
        'address',
        'direccion',
        'email',
        'phone',
        'description',
        'error_msg',
    ];

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>|null
     */
    public static function redactPayload(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        /** @var array<string, mixed> $redacted */
        $redacted = self::walk($payload);

        return $redacted;
    }

    public static function redactString(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        $masked = self::maskDocumentNumbersInText($value);

        if (mb_strlen($masked) > 4 && preg_match('/\p{L}/u', $masked)) {
            return mb_substr($masked, 0, 1).'***';
        }

        return $masked;
    }

    /**
     * @param  array<string|int, mixed>  $data
     * @return array<string|int, mixed>
     */
    private static function walk(array $data): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && self::isPiiKey($key)) {
                $out[$key] = self::maskScalar($value, $key);

                continue;
            }

            if (is_array($value)) {
                $out[$key] = self::walk($value);

                continue;
            }

            if (is_string($value)) {
                $out[$key] = self::maskDocumentNumbersInText($value);

                continue;
            }

            $out[$key] = $value;
        }

        return $out;
    }

    private static function isPiiKey(string $key): bool
    {
        $normalized = strtolower($key);

        foreach (self::PII_KEYS as $piiKey) {
            if ($normalized === strtolower($piiKey)) {
                return true;
            }
        }

        return false;
    }

    private static function maskScalar(mixed $value, string $key): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $normalized = strtolower($key);

        if (in_array($normalized, ['document_number', 'numero', 'dni', 'ruc'], true)) {
            return self::maskDocumentNumber($value);
        }

        return self::redactString($value);
    }

    private static function maskDocumentNumber(string $doc): string
    {
        $digits = preg_replace('/\D/', '', $doc) ?? '';

        if (strlen($digits) === 11) {
            return substr($digits, 0, 2).'*******'.substr($digits, -2);
        }

        if (strlen($digits) === 8) {
            return substr($digits, 0, 2).'****'.substr($digits, -1);
        }

        if (strlen($digits) >= 4) {
            return substr($digits, 0, 2).'***'.substr($digits, -1);
        }

        return '***';
    }

    private static function maskDocumentNumbersInText(string $text): string
    {
        $text = preg_replace_callback('/\b\d{11}\b/', static fn (array $m): string => self::maskDocumentNumber($m[0]), $text) ?? $text;
        $text = preg_replace_callback('/\b\d{8}\b/', static fn (array $m): string => self::maskDocumentNumber($m[0]), $text) ?? $text;

        return $text;
    }
}
