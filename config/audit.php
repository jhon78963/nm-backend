<?php

declare(strict_types=1);

use App\Modules\Shared\Infrastructure\Auditing\AuditUserResolver;
use App\Modules\Shared\Infrastructure\Persistence\Eloquent\Audit;

return [

    'enabled' => env('AUDITING_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Audit Implementation
    |--------------------------------------------------------------------------
    |
    | Custom model adds tenant_id (BelongsToTenant + TenantContext) on each audit row.
    |
    */

    'implementation' => Audit::class,

    /*
    |--------------------------------------------------------------------------
    | User Morph prefix & Guards
    |--------------------------------------------------------------------------
    |
    | Sanctum must be checked before web so API-token requests resolve the auditor.
    | Tenant identity for each row comes from TenantContext when the Audit is created.
    |
    */

    'user' => [
        'morph_prefix' => 'user',
        'guards' => [
            'sanctum',
            'web',
        ],
        'resolver' => AuditUserResolver::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit Resolvers
    |--------------------------------------------------------------------------
    |
    */
    'resolvers' => [
        'ip_address' => OwenIt\Auditing\Resolvers\IpAddressResolver::class,
        'user_agent' => OwenIt\Auditing\Resolvers\UserAgentResolver::class,
        'url' => OwenIt\Auditing\Resolvers\UrlResolver::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit Events
    |--------------------------------------------------------------------------
    |
    */
    'events' => [
        'created',
        'updated',
        'deleted',
        'restored',
    ],

    /*
    |--------------------------------------------------------------------------
    | Strict Mode
    |--------------------------------------------------------------------------
    |
    */
    'strict' => false,

    /*
    |--------------------------------------------------------------------------
    | Global exclude
    |--------------------------------------------------------------------------
    |
    */
    'exclude' => [],

    /*
    |--------------------------------------------------------------------------
    | Empty Values
    |--------------------------------------------------------------------------
    |
    */
    'empty_values' => true,
    'allowed_empty_values' => [
        'retrieved',
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed Array Values
    |--------------------------------------------------------------------------
    |
    */
    'allowed_array_values' => false,

    /*
    |--------------------------------------------------------------------------
    | Audit Timestamps
    |--------------------------------------------------------------------------
    |
    */
    'timestamps' => false,

    /*
    |--------------------------------------------------------------------------
    | Audit Threshold
    |--------------------------------------------------------------------------
    |
    */
    'threshold' => 0,

    /*
    |--------------------------------------------------------------------------
    | Audit Driver
    |--------------------------------------------------------------------------
    |
    */
    'driver' => 'database',

    /*
    |--------------------------------------------------------------------------
    | Audit Driver Configurations
    |--------------------------------------------------------------------------
    |
    */
    'drivers' => [
        'database' => [
            'table' => 'audits',
            'connection' => null,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit Queue Configurations
    |--------------------------------------------------------------------------
    |
    */
    'queue' => [
        'enable' => false,
        'connection' => 'sync',
        'queue' => 'default',
        'delay' => 0,
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit Console
    |--------------------------------------------------------------------------
    |
    | When true, Artisan runs (including Pest via `php artisan test`) record audits.
    | Set AUDITING_CONSOLE=true in phpunit.xml; keep false in deployed .env unless
    | you want every CLI change audited.
    |
    */
    'console' => env('AUDITING_CONSOLE', false),

];