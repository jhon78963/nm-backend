<?php

namespace App\Image\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Config;

class ImageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->getFileName($this->path),
            'path' => $this->generateS3Url($this->path),
            'data' => $this->generateS3Url($this->path),
            'key' => $this->path,
            'isDB' => true,
        ];
    }

    private function generateS3Url(string $path): string
    {
        $aws_url = Config::get('app.aws_url');
        $baseUrl = rtrim($aws_url, '/');
        $cleanPath = ltrim($path, '/');
        return "$baseUrl/$cleanPath";
    }

    private function getFileName(string $path): string
    {
        return basename($path);
    }
}
