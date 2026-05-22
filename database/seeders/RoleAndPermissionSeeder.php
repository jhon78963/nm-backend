<?php

namespace Database\Seeders;

use App\Administration\User\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleAndPermissionSeeder extends Seeder
{
    /**
     * @return list<string>
     */
    private function permissionNames(): array
    {
        return [
            'sale.getMonthlyStats', 'sale.delete', 'sale.get', 'sale.getAll', 'sale.update', 'sale.exchange',
            'pos.searchProduct', 'pos.searchCustomer', 'pos.checkout',
            'purchase.registerBulk', 'purchase.getAll', 'purchase.updateLine', 'purchase.deleteLine', 'purchase.get', 'purchase.update', 'purchase.cancel',
            'color.create', 'color.update', 'color.delete', 'color.getAll', 'color.getAllSelectedAttached', 'color.getAllSelected', 'color.getSizes', 'color.getAllAutocomplete', 'color.get',
            'cashflow.getDaily', 'cashflow.getAdminMonthlyReport', 'cashflow.store', 'cashflow.update',
            'order.create', 'order.update', 'order.delete', 'order.getAll', 'order.get',
            'warehouse.create', 'warehouse.update', 'warehouse.delete', 'warehouse.getAll', 'warehouse.get',
            'tenant.create', 'tenant.update', 'tenant.delete', 'tenant.getAll', 'tenant.get',
            'team.create', 'team.update', 'team.delete', 'team.getAll', 'team.get',
            'attendance.getByMonth', 'attendance.getDailySummary', 'attendance.store',
            'payment.getByMonth', 'payment.store',
            'product.create', 'product.update', 'product.delete', 'product.getAll', 'product.get', 'product.view_purchase_price',
            'productSize.add', 'productSize.modify', 'productSize.remove', 'productSize.get',
            'productSizeColor.add', 'productSizeColor.modify', 'productSizeColor.remove',
            'productImage.add', 'productImage.multipleAdd', 'productImage.multipleRemove', 'productImage.remove', 'productImage.getAll',
            'productHistory.index',
            'inventoryKardex.index',
            'inventoryReconciliation.search',
            'inventoryReconciliation.update',
            'inventoryReconciliation.replaceColor',
            'audit.getAll',
            'expense.create', 'expense.update', 'expense.delete', 'expense.getAll', 'expense.get',
            'report.index',
            'financialSummary.getSummary',
            'size.create', 'size.update', 'size.delete', 'size.getAll', 'size.getAllSelected', 'size.getAllAutocomplete', 'size.getAutocomplete', 'size.get', 'size.getSizeType',
            'vendor.create', 'vendor.update', 'vendor.delete', 'vendor.getAll', 'vendor.get',
            'customer.create', 'customer.update', 'customer.delete', 'customer.getAll', 'customer.get',
            'role.create', 'role.update', 'role.delete', 'role.getAll', 'role.get', 'role.syncPermissions', 'role.permissionsIndex',
            'user.create', 'user.update', 'user.delete', 'user.getAll', 'user.get',
            'image.create', 'image.delete', 'image.getAll', 'image.get',
            'gender.getAll', 'gender.get',
        ];
    }

    /**
     * Permisos mínimos del rol de venta en tienda: solo POS y caja diaria.
     *
     * @return list<string>
     */
    private function vendedoraPermissionNames(): array
    {
        return [
            'pos.searchProduct',
            'pos.searchCustomer',
            'pos.checkout',
            'cashflow.getDaily',
            'cashflow.store',
        ];
    }

    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $guard = 'web';

        foreach ($this->permissionNames() as $name) {
            Permission::query()->firstOrCreate(
                ['name' => $name, 'guard_name' => $guard],
                ['name' => $name, 'guard_name' => $guard]
            );
        }

        $roleSuper = Role::query()->firstOrCreate(
            ['name' => 'Super Admin', 'guard_name' => $guard],
            ['name' => 'Super Admin', 'guard_name' => $guard]
        );

        $roleVendedora = Role::query()->firstOrCreate(
            ['name' => 'Vendedora', 'guard_name' => $guard],
            ['name' => 'Vendedora', 'guard_name' => $guard]
        );

        $vendedoraPermissions = Permission::query()
            ->where('guard_name', $guard)
            ->whereIn('name', $this->vendedoraPermissionNames())
            ->get();

        $roleVendedora->syncPermissions($vendedoraPermissions);

        // Alias legacy / manual: mismo alcance que Vendedora (solo POS + caja diaria).
        $roleVendedor = Role::query()->where('name', 'Vendedor')->where('guard_name', $guard)->first();
        if ($roleVendedor !== null) {
            $roleVendedor->syncPermissions($vendedoraPermissions);
        }

        $seedPassword = Hash::make(env('SEEDER_DEFAULT_PASSWORD', Str::random(12)));

        $defaultWarehouseId = (int) (\App\Inventory\Warehouse\Models\Warehouse::query()->orderBy('id')->value('id') ?? 1);
        $defaultTenantId = (int) (\App\Inventory\Warehouse\Models\Warehouse::query()->find($defaultWarehouseId)?->tenant_id ?? 1);

        $super = User::query()->updateOrCreate(
            ['email' => 'superadmin@test.com'],
            [
                'username' => 'superadmin',
                'name' => 'Super',
                'surname' => 'Admin',
            ]
        );
        $super->password = $seedPassword;
        $super->warehouse_id = $defaultWarehouseId;
        $super->tenant_id = $defaultTenantId;
        $super->save();
        $super->syncRoles([$roleSuper]);

        $vendedora = User::query()->updateOrCreate(
            ['email' => 'vendedora@test.com'],
            [
                'username' => 'vendedora',
                'name' => 'María',
                'surname' => 'Vendedora',
            ]
        );
        $vendedora->password = $seedPassword;
        $vendedora->warehouse_id = $defaultWarehouseId;
        $vendedora->tenant_id = $defaultTenantId;
        $vendedora->save();
        $vendedora->syncRoles([$roleVendedora]);

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
