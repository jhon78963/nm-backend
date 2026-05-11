<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Administration\Domain\Models\Store;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Shared\Domain\TenantContext;
use App\Modules\Shared\Infrastructure\Persistence\Eloquent\Media;
use App\Modules\Tenant\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

afterEach(function (): void {
    TenantContext::forget();
});

test('profile image uploads to fake s3 and media row carries tenant id', function (): void {
    Storage::fake('s3');

    $tenant = Tenant::query()->create([
        'name' => 'Media Tenant',
        'domain' => 'media-tenant.test',
        'is_active' => true,
    ]);

    $store = Store::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Main',
        'address' => 'Addr',
        'is_active' => true,
    ]);

    $user = User::query()->create([
        'name' => 'Uploader',
        'email' => 'u@media-tenant.test',
        'password' => 'secret',
        'tenant_id' => $tenant->id,
        'store_id' => $store->id,
    ]);

    TenantContext::set((int) $tenant->id);

    $file = UploadedFile::fake()->image('avatar.jpg', 300, 300);

    $user->addMedia($file)->toMediaCollection('profile');

    /** @var Media|null $media */
    $media = $user->refresh()->getFirstMedia('profile');

    expect($media)->toBeInstanceOf(Media::class)
        ->and($media->tenant_id)->toBe($tenant->id)
        ->and($media->disk)->toBe('s3');

    Storage::disk('s3')->assertExists($media->getPathRelativeToRoot());
});
