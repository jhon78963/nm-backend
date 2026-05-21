<?php

namespace App\Auth\Providers;

use App\Auth\Models\PersonalAccessToken;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class TokenServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        Sanctum::getAccessTokenFromRequestUsing(function (Request $request): ?string {
            $bearerToken = $request->bearerToken();
            if (is_string($bearerToken) && $bearerToken !== '') {
                return $bearerToken;
            }

            $cookieToken = $request->cookie('access_token');

            return is_string($cookieToken) && $cookieToken !== '' ? $cookieToken : null;
        });
    }
}
