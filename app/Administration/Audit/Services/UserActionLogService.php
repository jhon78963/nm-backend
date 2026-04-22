<?php

namespace App\Administration\Audit\Services;

use App\Administration\Audit\Models\UserActionLog;
use App\Administration\User\Models\User;
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
        if (! $user) {
            throw new \InvalidArgumentException('Authenticated user required to log action.');
        }

        return UserActionLog::query()->create([
            'user_id' => $user->id,
            'team_id' => $user->team?->id,
            'warehouse_id' => $user->warehouse_id,
            'action' => $action,
            'description' => $description,
            'metadata' => $metadata,
            'ip_address' => Request::ip(),
        ]);
    }
}
