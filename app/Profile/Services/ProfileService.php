<?php

namespace App\Profile\Services;

use App\Administration\User\Models\User;
use App\Auth\Services\AuthService;
use App\Inventory\WooCommerce\Support\ProductMediaUrlResolver;
use App\Profile\Requests\UpdateProfileRequest;
use App\Shared\Foundation\Services\NodeUploaderService;
use Illuminate\Http\UploadedFile;

class ProfileService
{
    public function __construct(
        protected NodeUploaderService $nodeUploaderService,
        protected ProductMediaUrlResolver $mediaUrlResolver,
        protected AuthService $authService,
    ) {
    }

    public function getProfile(User $user): User
    {
        return $user->loadMissing('warehouse');
    }

    public function updateProfile(User $user, UpdateProfileRequest $request): User
    {
        $validated = $request->validated();
        $nameParts = preg_split('/\s+/', trim((string) $validated['name']), 2) ?: [];

        $user->name = $nameParts[0] ?? trim((string) $validated['name']);
        $user->surname = $nameParts[1] ?? '';
        $user->email = $validated['email'];
        $user->phone = $this->normalizePhone($validated['phone'] ?? null);
        $user->save();

        return $user->fresh(['warehouse']);
    }

    public function updatePassword(User $user, string $newPassword): User
    {
        $this->authService->changePassword($user, $newPassword);

        return $user->fresh(['warehouse']);
    }

    public function uploadAvatar(User $user, UploadedFile $file): User
    {
        $path = $this->nodeUploaderService->upload($file, 'avatars');

        $previousPath = (string) ($user->profile_picture ?? '');
        if ($previousPath !== '' && str_starts_with($previousPath, '/uploads/')) {
            $this->nodeUploaderService->delete($previousPath);
        }

        $user->profile_picture = $path;
        $user->save();

        return $user->fresh(['warehouse']);
    }

    public function avatarUrl(User $user): ?string
    {
        return $this->mediaUrlResolver->absoluteUrl((string) ($user->profile_picture ?? ''));
    }

    private function normalizePhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '51') && strlen($digits) === 11) {
            return substr($digits, 2);
        }

        return $digits;
    }
}
