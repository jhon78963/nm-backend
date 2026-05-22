<?php

namespace App\Shared\Foundation\Controllers;

use App\Shared\Foundation\Services\PrivateFileService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PrivateFileController extends Controller
{
    public function __construct(
        protected PrivateFileService $privateFileService,
    ) {
    }

    public function show(string $path): BinaryFileResponse
    {
        return $this->privateFileService->fileResponse($path);
    }
}
