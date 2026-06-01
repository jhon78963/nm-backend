# SEC-001 — RBAC tenant-scope

## Problema

Spatie Permission tenía `teams = false` (config/permission.php), lo que hace que todos los
roles sean **globales**. Un admin de tenant A con el permiso `role.syncPermissions` podía
alterar roles que afectan a tenant B o los roles de sistema del negocio.

## Estrategia elegida: B — tenant_id en tabla roles

Se descartó la Opción A (activar Spatie teams) porque requiere una migración invasiva en
las tablas pivot `model_has_roles` y `model_has_permissions`, con riesgo de regresión en
producción.

La Opción B es mínimamente invasiva:

| Cambio | Descripción |
|--------|-------------|
| `database/migrations/2026_06_01_000001_add_tenant_id_to_roles_table.php` | Agrega `tenant_id` nullable (FK → tenants) a la tabla `roles` |
| `app/Administration/Role/Concerns/GuardsRoleTenantScope.php` | Trait con lógica de verificación tenant-scope |
| `app/Administration/Role/Controllers/RoleController.php` | Usa el trait en create / get / update / delete / syncPermissions |

## Semántica de tenant_id en roles

| tenant_id | Significado | ¿Quién puede modificar? |
|-----------|-------------|------------------------|
| `NULL` | Rol de sistema (Super Admin, Vendedora, etc.) | Solo **Super Admin** |
| `{id}` | Rol custom creado por ese tenant | Admins del mismo tenant + Super Admin |

## Reglas de negocio aplicadas

- **getAll**: no-Super Admin solo ve roles de su propio tenant.
- **create**: el rol nuevo recibe `tenant_id` del actor (Super Admin → `NULL`).
- **get / update / delete / syncPermissions**: 403 si el rol es de otro tenant o es sistema.
- **Super Admin** (via `Gate::before`) siempre pasa la verificación de permisos Spatie y
  la verificación de tenant scope.

## Test de aislamiento

```
tests/Feature/Security/TenantRoleIsolationTest.php
```

Ejecutar:

```bash
php artisan test tests/Feature/Security/TenantRoleIsolationTest.php
```

Casos cubiertos (13 tests):

- Tenant A admin → syncPermissions en rol de tenant B → **403**
- Tenant B admin → syncPermissions en rol de tenant A → **403**
- Tenant A admin → syncPermissions en rol de sistema → **403**
- Tenant A admin → syncPermissions en su propio rol → **200**
- Super Admin → syncPermissions en cualquier rol → **200**
- Tenant A admin → delete rol otro tenant → **403**
- Tenant A admin → delete rol de sistema → **403**
- Tenant A admin → update rol otro tenant → **403**
- Tenant A admin crea rol → `tenant_id` queda = tenant A
- Super Admin crea rol → `tenant_id = null` (sistema)
- getAll tenant A → solo ve roles de tenant A, no ve roles de tenant B ni sistema
- getAll Super Admin → ve todos los roles

## Migrar en producción

```bash
php artisan migrate
```

Los roles de sistema existentes (Super Admin, Vendedora, Vendedor) quedan con `tenant_id = NULL`
automáticamente. Los roles custom existentes necesitan ser asignados manualmente a su tenant
si ya existen en producción — ejecutar el siguiente SQL una vez:

```sql
-- Ejemplo: asignar roles existentes al tenant 1 excepto los de sistema conocidos
UPDATE roles
SET tenant_id = 1
WHERE guard_name = 'web'
  AND tenant_id IS NULL
  AND name NOT IN ('Super Admin', 'Vendedora', 'Vendedor');
```

Ajustar según los datos reales.

## Limitación conocida

Con la restricción `UNIQUE(name, guard_name)` existente, dos tenants no pueden tener roles
con el mismo nombre. Si se necesita eso, se requiere un SEC-001-ext que cambie la constraint
a `UNIQUE(tenant_id, name, guard_name)`.
