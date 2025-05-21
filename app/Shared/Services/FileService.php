<?php

namespace App\Shared\Services;

use App\Shared\Requests\FileUploadRequest;
use Illuminate\Support\Facades\Http;

class FileService
{
    public function upload(FileUploadRequest $request)
    {
        $url = config('zg.url');
        $token = config('zg.token');
        $file = $request->file('file');
        $response = Http::withToken($token)->post(
            $url, ['file' => $file]
        );

        return $response;
    }
}
