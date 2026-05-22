<?php

namespace App\Traits;

use App\Models\Media;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasMedia
{
    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable');
    }

    public function attachMedia(string $path, ?string $name = null): Media
    {
        return $this->media()->create([
            'file_path' => $path,
            'file_name' => $name,
            'file_size' => null,
        ]);
    }

    public function clearMedia(): void
    {
        $this->media()->delete();
    }
}
