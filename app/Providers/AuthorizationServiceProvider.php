<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AuthorizationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::before(function ($user, string $ability) {
            if ($user && method_exists($user, 'hasRole') && $user->hasRole('Super Admin')) {
                return true;
            }

            return null;
        });
    }
}
