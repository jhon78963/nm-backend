<?php

namespace App\Shared\Foundation\Services;

use App\Administration\User\Models\User;
use App\Finance\CashMovement\Models\CashMovement;
use App\Shared\Image\Models\Image;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PrivateFileService
{
    public function resolveAbsolutePath(string $requestedPath): ?string
    {
        $relativePath = $this->normalizeRelativePath($requestedPath);

        if ($relativePath === null) {
            return null;
        }

        $disk = Storage::disk('local');

        if (! $disk->exists($relativePath)) {
            return null;
        }

        $absolutePath = $disk->path($relativePath);

        return is_file($absolutePath) ? $absolutePath : null;
    }

    public function canAccess(string $requestedPath, ?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        $relativePath = $this->normalizeRelativePath($requestedPath);

        if ($relativePath === null || ! Storage::disk('local')->exists($relativePath)) {
            return false;
        }

        if ($user->hasRole('Super Admin')) {
            return true;
        }

        if ($this->canAccessCashMovementVoucher($relativePath, $user)) {
            return true;
        }

        if ($this->canAccessImage($relativePath, $user)) {
            return true;
        }

        return false;
    }

    public function fileResponse(string $requestedPath): BinaryFileResponse
    {
        $absolutePath = $this->resolveAbsolutePath($requestedPath);

        if ($absolutePath === null) {
            abort(404, 'File not found.');
        }

        if (! $this->canAccess($requestedPath, auth()->user())) {
            abort(403, 'You do not have permission to access this file.');
        }

        return response()->file($absolutePath);
    }

    private function normalizeRelativePath(string $requestedPath): ?string
    {
        $relative = ltrim(str_replace('\\', '/', urldecode($requestedPath)), '/');

        if ($relative === '' || str_contains($relative, "\0") || str_contains($relative, '..')) {
            return null;
        }

        return $relative;
    }

    private function canAccessCashMovementVoucher(string $relativePath, User $user): bool
    {
        if (! $user->can('cashflow.getDaily') && ! $user->can('cashflow.getAdminMonthlyReport')) {
            return false;
        }

        return CashMovement::query()
            ->where('is_deleted', false)
            ->where(function ($query) use ($relativePath): void {
                $query->where('voucher_path', $relativePath)
                    ->orWhere('voucher_path', '/'.$relativePath);
            })
            ->exists();
    }

    private function canAccessImage(string $relativePath, User $user): bool
    {
        if (! $user->can('image.get')) {
            return false;
        }

        $image = Image::query()
            ->where('is_deleted', false)
            ->where(function ($query) use ($relativePath): void {
                $query->where('path', $relativePath)
                    ->orWhere('path', '/'.$relativePath);
            })
            ->first();

        if ($image === null) {
            return false;
        }

        $userWarehouseId = (int) ($user->warehouse_id ?? 0);

        if ($userWarehouseId <= 0) {
            return false;
        }

        $imageWarehouseIds = $image->resolveWarehouseIds();

        if ($imageWarehouseIds === []) {
            return false;
        }

        return in_array($userWarehouseId, $imageWarehouseIds, true);
    }
}
