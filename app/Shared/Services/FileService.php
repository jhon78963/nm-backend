<?php

namespace App\Shared\Services;

use App\Shared\Requests\FileMultipleUploadRequest;
use Storage;

class FileService
{
    public function upload($request, String $filePath, string $key): ?string
    {
        return ($request->hasFile($key))
            ? $request->file($key)->store($filePath)
            : NULL;
    }

    public function uploadMultiple(FileMultipleUploadRequest $request, String $filePath): array
    {
        $uploadedPaths = [];
        if ($request->hasFile('file')) {
            foreach ($request->file('file') as $file) {
                $uploadedPaths[] = Storage::disk('local')->put($filePath,  $file);

            }
        }
        return $uploadedPaths;
    }

    public function get(string $filePath): ?string
    {
        return Storage::disk('local')->exists($filePath)
            ? Storage::disk('local')->path($filePath)
            : NULL;
    }

    public function delete(string $filePath): ?string
    {
        return Storage::disk('local')->exists($filePath)
            ? Storage::disk('local')->delete($filePath)
            : NULL;
    }
}
