<?php

namespace App\Administrations\ActionLogs\Services;

use App\Administrations\ActionLogs\Models\UserActionLog;
use App\Administrations\Users\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;

class UserActionLogService
{
    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public static function log(
        string $action,
        ?string $description = null,
        ?array $metadata = null,
        ?User $user = null,
    ): UserActionLog {
        $user = $user ?? auth()->user();

        return self::write($action, $description, $metadata, $user);
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public static function logSafely(
        string $action,
        ?string $description = null,
        ?array $metadata = null,
        ?User $user = null,
    ): ?UserActionLog {
        try {
            return self::log($action, $description, $metadata, $user);
        } catch (\Throwable $exception) {
            Log::warning('No se pudo registrar auditoría de usuario.', [
                'action' => $action,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    private static function write(
        string $action,
        ?string $description,
        ?array $metadata,
        ?User $user,
    ): UserActionLog {
        return UserActionLog::query()->create([
            'user_id' => $user?->id,
            'team_id' => $user?->team?->id,
            'warehouse_id' => $user?->warehouse_id,
            'action' => $action,
            'description' => $description,
            'metadata' => $metadata,
            'ip_address' => Request::ip(),
        ]);
    }
}
