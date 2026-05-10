<?php

namespace App\Administration\Tenant\Controllers;

use App\Administration\Tenant\Models\Tenant;
use App\Administration\Tenant\Requests\SystemAdminProvisionRequest;
use App\Administration\Tenant\Resources\SystemAdminTenantResource;
use App\Administration\User\Models\User;
use App\Shared\Foundation\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Panel de administración maestro del proveedor SaaS.
 *
 * TODAS las queries usan withoutGlobalScopes() para ver la infraestructura
 * completa sin restricciones de TenantScope.
 */
class SystemAdminTenantController extends Controller
{
    /**
     * Lista todos los tenants con conteo de usuarios.
     */
    public function index(): JsonResponse
    {
        $tenants = Tenant::withoutGlobalScopes()
            ->withCount('users')
            ->orderBy('id')
            ->get();

        return response()->json(SystemAdminTenantResource::collection($tenants));
    }

    /**
     * Provisión atómica: crea Tenant + Rol Admin + Usuario administrador.
     */
    public function store(SystemAdminProvisionRequest $request): JsonResponse
    {
        return DB::transaction(function () use ($request): JsonResponse {
            // 1. Crear el Tenant con los features seleccionados
            $tenant = Tenant::withoutGlobalScopes()->create([
                'name'      => $request->validated('tenant_name'),
                'is_active' => true,
                'features'  => $request->validated('features', []),
            ]);

            // 2. Registrar el equipo correcto en Spatie antes de crear el rol
            app(\Spatie\Permission\PermissionRegistrar::class)
                ->setPermissionsTeamId($tenant->id);

            // 3. Crear el Rol 'Admin' para el nuevo tenant
            $role = Role::create([
                'name'       => 'Admin',
                'guard_name' => 'web',
            ]);

            // 4. Asignar TODOS los permisos del sistema al rol Admin
            $allPermissions = Permission::withoutGlobalScopes()->get();
            $role->syncPermissions($allPermissions);

            // 5. Crear el usuario administrador
            $username = Str::slug($request->validated('admin_name'), '.');

            // Garantizar unicidad del username
            $baseUsername = $username;
            $attempt      = 1;
            while (User::withoutGlobalScopes()->where('username', $username)->exists()) {
                $username = "{$baseUsername}.{$attempt}";
                $attempt++;
            }

            $user = User::withoutGlobalScopes()->create([
                'name'      => $request->validated('admin_name'),
                'surname'   => '',
                'username'  => $username,
                'email'     => $request->validated('admin_email'),
                'password'  => $request->validated('admin_password'),
                'tenant_id' => $tenant->id,
            ]);

            // 6. Asignar el rol al usuario en el equipo correcto
            $user->assignRole($role);

            // Restablecer el equipo al tenant 1 (proveedor) para no contaminar
            app(\Spatie\Permission\PermissionRegistrar::class)
                ->setPermissionsTeamId(1);

            $tenant->loadCount('users');

            return response()->json(new SystemAdminTenantResource($tenant), 201);
        });
    }

    /**
     * Actualiza los features (módulos) de un tenant existente.
     */
    public function updateFeatures(int $id): JsonResponse
    {
        $tenant = Tenant::withoutGlobalScopes()->findOrFail($id);

        $features = request()->validate([
            'features'   => ['required', 'array'],
            'features.*' => ['string'],
        ])['features'];

        $tenant->update(['features' => $features]);

        $tenant->loadCount('users');

        return response()->json(new SystemAdminTenantResource($tenant->fresh()));
    }
}
