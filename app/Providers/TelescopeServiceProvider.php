<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Shared\Domain\TenantContext;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;

class TelescopeServiceProvider extends TelescopeApplicationServiceProvider
{
    public function register(): void
    {
        $this->hideSensitiveRequestDetails();

        $isLocal = $this->app->environment('local');

        Telescope::filter(function (IncomingEntry $entry) use ($isLocal): bool {
            return $isLocal
                || $entry->isReportableException()
                || $entry->isFailedRequest()
                || $entry->isFailedJob()
                || $entry->isScheduledTask()
                || $entry->hasMonitoredTag();
        });

        Telescope::tag(static function (IncomingEntry $entry): array {
            $tenantId = TenantContext::id();

            if ($tenantId === null) {
                return ['tenant:none'];
            }

            return [sprintf('tenant_id:%s', $tenantId)];
        });
    }

    protected function hideSensitiveRequestDetails(): void
    {
        if ($this->app->environment('local')) {
            return;
        }

        Telescope::hideRequestParameters(['_token']);

        Telescope::hideRequestHeaders([
            'cookie',
            'x-csrf-token',
            'x-xsrf-token',
        ]);
    }

    /**
     * Non-local access: only users whose email is listed in config('telescope.allowed_authorizer_emails').
     * Local access remains open per {@see TelescopeApplicationServiceProvider::authorization()}.
     */
    protected function gate(): void
    {
        Gate::define('viewTelescope', function (?Authenticatable $user): bool {
            if (! $user instanceof User) {
                return false;
            }

            /** @var list<string> $allowed */
            $allowed = config('telescope.allowed_authorizer_emails', []);

            return $allowed !== [] && in_array($user->email, $allowed, true);
        });
    }
}
