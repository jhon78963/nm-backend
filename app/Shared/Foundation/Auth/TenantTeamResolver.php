<?php

namespace App\Shared\Foundation\Auth;

// use Spatie\Permission\Contracts\TeamResolver;

/**
 * Resuelve automáticamente el "team" de Spatie Permission usando el tenant_id
 * del usuario actualmente autenticado vía Sanctum.
 *
 * Con este resolver NO es necesario llamar a setPermissionsTeamId() en el login.
 * Spatie lo invoca en cada verificación de permisos/roles.
 */
// class TenantTeamResolver implements TeamResolver
// {
//     public function getTeamId(): int|string|null
//     {
//         if (! auth()->check()) {
//             return null;
//         }

//         return auth()->user()->tenant_id;
//     }
// }
