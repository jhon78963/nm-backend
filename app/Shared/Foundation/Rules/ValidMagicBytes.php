<?php

namespace App\Shared\Foundation\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Validates that an uploaded file's actual content matches its declared MIME type
 * by inspecting the leading magic bytes, defeating extension/MIME spoofing attacks.
 *
 * Supported types: jpeg, png, webp, pdf
 */
class ValidMagicBytes implements ValidationRule
{
    /** @var string[] */
    private array $allowed;

    /** @param string[] $allowed Subset of: jpeg, png, webp, pdf */
    public function __construct(array $allowed = ['jpeg', 'png', 'webp', 'pdf'])
    {
        $this->allowed = $allowed;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! ($value instanceof UploadedFile)) {
            return;
        }

        $realPath = $value->getRealPath();

        if ($realPath === false || $realPath === '') {
            $fail('El archivo :attribute no se pudo leer.');

            return;
        }

        $handle = @fopen($realPath, 'rb');

        if ($handle === false) {
            $fail('El archivo :attribute no se pudo abrir para verificación.');

            return;
        }

        // Read the first 12 bytes — enough for all supported signatures
        $header = fread($handle, 12);
        fclose($handle);

        if ($header === false || strlen($header) < 4) {
            $fail('El archivo :attribute está vacío o corrupto.');

            return;
        }

        if (! $this->headerMatchesAllowed($header)) {
            $fail('El contenido real del archivo :attribute no coincide con un tipo permitido (jpeg, png, webp, pdf).');
        }
    }

    private function headerMatchesAllowed(string $header): bool
    {
        foreach ($this->allowed as $type) {
            if ($this->matchesSignature($type, $header)) {
                return true;
            }
        }

        return false;
    }

    private function matchesSignature(string $type, string $header): bool
    {
        return match ($type) {
            // FF D8 FF
            'jpeg' => str_starts_with($header, "\xFF\xD8\xFF"),
            // 89 50 4E 47 0D 0A 1A 0A
            'png' => str_starts_with($header, "\x89PNG\r\n\x1A\n"),
            // RIFF????WEBP  (bytes 0-3 = RIFF, bytes 8-11 = WEBP)
            'webp' => str_starts_with($header, 'RIFF') && substr($header, 8, 4) === 'WEBP',
            // %PDF
            'pdf' => str_starts_with($header, '%PDF'),
            default => false,
        };
    }
}
