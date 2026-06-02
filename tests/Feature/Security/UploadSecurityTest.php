<?php

use App\Shared\Foundation\Rules\ValidMagicBytes;
use App\Shared\Foundation\Services\NodeUploaderService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;

describe('ValidMagicBytes rule', function () {

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** Build a fake UploadedFile whose content starts with $header bytes. */
    function fakeFileWithHeader(string $header, string $clientName = 'test.jpg', string $mime = 'image/jpeg'): UploadedFile
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'sec_test_');
        file_put_contents($tmpPath, $header.str_repeat("\x00", 20));

        return new UploadedFile(
            path: $tmpPath,
            originalName: $clientName,
            mimeType: $mime,
            error: UPLOAD_ERR_OK,
            test: true,
        );
    }

    // ------------------------------------------------------------------
    // Valid files pass
    // ------------------------------------------------------------------

    it('accepts a real JPEG (FF D8 FF header)', function () {
        $file = fakeFileWithHeader("\xFF\xD8\xFF\xE0", 'photo.jpg', 'image/jpeg');

        $validator = Validator::make(
            ['file' => $file],
            ['file' => [new ValidMagicBytes(['jpeg'])]],
        );

        expect($validator->passes())->toBeTrue();
    });

    it('accepts a real PNG (89 PNG header)', function () {
        $file = fakeFileWithHeader("\x89PNG\r\n\x1A\n", 'image.png', 'image/png');

        $validator = Validator::make(
            ['file' => $file],
            ['file' => [new ValidMagicBytes(['png'])]],
        );

        expect($validator->passes())->toBeTrue();
    });

    it('accepts a real WebP (RIFF????WEBP header)', function () {
        $header = 'RIFF'.\pack('V', 100).'WEBP';
        $file = fakeFileWithHeader($header, 'image.webp', 'image/webp');

        $validator = Validator::make(
            ['file' => $file],
            ['file' => [new ValidMagicBytes(['webp'])]],
        );

        expect($validator->passes())->toBeTrue();
    });

    it('accepts a real PDF (%PDF header)', function () {
        $file = fakeFileWithHeader('%PDF-1.4', 'voucher.pdf', 'application/pdf');

        $validator = Validator::make(
            ['file' => $file],
            ['file' => [new ValidMagicBytes(['pdf'])]],
        );

        expect($validator->passes())->toBeTrue();
    });

    // ------------------------------------------------------------------
    // Malicious / mismatched files are rejected
    // ------------------------------------------------------------------

    it('rejects a PHP script disguised as JPEG (no FF D8 header)', function () {
        $file = fakeFileWithHeader("<?php system(\$_GET['cmd']); ?>", 'shell.jpg', 'image/jpeg');

        $validator = Validator::make(
            ['file' => $file],
            ['file' => [new ValidMagicBytes(['jpeg'])]],
        );

        expect($validator->fails())->toBeTrue();
        expect($validator->errors()->first('file'))->toContain('contenido real');
    });

    it('rejects a PNG file submitted with JPEG MIME and .jpg name', function () {
        $file = fakeFileWithHeader("\x89PNG\r\n\x1A\n", 'evil.jpg', 'image/jpeg');

        // Only jpeg is in allowed list → PNG header doesn't match
        $validator = Validator::make(
            ['file' => $file],
            ['file' => [new ValidMagicBytes(['jpeg'])]],
        );

        expect($validator->fails())->toBeTrue();
    });

    it('rejects an HTML file with a double extension (evil.jpg.php → no valid image magic)', function () {
        $file = fakeFileWithHeader('<html><script>alert(1)</script>', 'evil.jpg.php', 'image/jpeg');

        $validator = Validator::make(
            ['file' => $file],
            ['file' => [new ValidMagicBytes(['jpeg', 'png', 'webp', 'pdf'])]],
        );

        expect($validator->fails())->toBeTrue();
    });

    it('rejects an empty / truncated file (header < 4 bytes)', function () {
        $tmpPath = tempnam(sys_get_temp_dir(), 'sec_empty_');
        file_put_contents($tmpPath, "\xFF\xD8");           // only 2 bytes

        $file = new UploadedFile($tmpPath, 'short.jpg', 'image/jpeg', UPLOAD_ERR_OK, true);

        $validator = Validator::make(
            ['file' => $file],
            ['file' => [new ValidMagicBytes(['jpeg'])]],
        );

        expect($validator->fails())->toBeTrue();
    });
});

// ------------------------------------------------------------------
// NodeUploaderService::sanitizeFilename
// ------------------------------------------------------------------

describe('NodeUploaderService::sanitizeFilename', function () {

    /** Helper: UploadedFile with a given client name and server-detected MIME. */
    function fakeUploadedFile(string $clientName, string $mimeType): UploadedFile
    {
        $header = match ($mimeType) {
            'image/jpeg' => "\xFF\xD8\xFF\xE0",
            'image/png' => "\x89PNG\r\n\x1A\n",
            'image/webp' => 'RIFF'.pack('V', 20).'WEBP',
            'application/pdf' => '%PDF-1.4',
            default => 'dummy',
        };

        $tmp = tempnam(sys_get_temp_dir(), 'svc_test_');
        file_put_contents($tmp, $header.str_repeat("\x00", 20));

        return new UploadedFile($tmp, $clientName, $mimeType, UPLOAD_ERR_OK, true);
    }

    it('derives extension from server MIME, not client name', function () {
        $file = fakeUploadedFile('receipt.jpg', 'application/pdf');

        $name = NodeUploaderService::sanitizeFilename($file);

        expect($name)->toEndWith('.pdf')
            ->and($name)->not->toContain('.jpg');
    });

    it('strips path traversal from client original name', function () {
        $file = fakeUploadedFile('../../etc/passwd', 'image/jpeg');

        $name = NodeUploaderService::sanitizeFilename($file);

        expect($name)->not->toContain('..')
            ->and($name)->not->toContain('/')
            ->and($name)->toEndWith('.jpg');
    });

    it('collapses double extension to stem + single MIME-derived extension', function () {
        $file = fakeUploadedFile('evil.php.jpg', 'image/png');

        $name = NodeUploaderService::sanitizeFilename($file);

        // Extension comes from MIME (png), stem has the rest collapsed to safe chars
        expect($name)->toEndWith('.png')
            ->and(substr_count($name, '.'))->toBe(1);
    });

    it('replaces special characters in stem with underscores', function () {
        $file = fakeUploadedFile('my file; rm -rf /.jpg', 'image/jpeg');

        $name = NodeUploaderService::sanitizeFilename($file);

        expect($name)->toMatch('/^[a-zA-Z0-9_\-]+\.jpg$/');
    });

    it('handles a name with only dangerous characters gracefully', function () {
        $file = fakeUploadedFile(';;;.jpg', 'image/webp');

        $name = NodeUploaderService::sanitizeFilename($file);

        expect($name)->toBe('upload.webp');
    });

    it('strips null bytes and control characters from filename', function () {
        $file = fakeUploadedFile("evil\x00name.jpg", 'image/jpeg');

        $name = NodeUploaderService::sanitizeFilename($file);

        expect($name)->not->toContain("\x00");
    });

    it('falls back to bin extension for unknown MIME type', function () {
        $file = fakeUploadedFile('unknown.xyz', 'application/octet-stream');

        $name = NodeUploaderService::sanitizeFilename($file);

        expect($name)->toEndWith('.bin');
    });
});
