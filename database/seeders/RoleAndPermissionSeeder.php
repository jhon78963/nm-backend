<?php

namespace Database\Seeders;

use App\Administration\User\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
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
            'product.create', 'product.update', 'product.delete', 'product.getAll', 'product.get',
            'productSize.add', 'productSize.modify', 'productSize.remove', 'productSize.get',
            'productSizeColor.add', 'productSizeColor.modify', 'productSizeColor.remove',
            'productImage.add', 'productImage.multipleAdd', 'productImage.multipleRemove', 'productImage.remove', 'productImage.getAll',
            'productHistory.index',
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

        $vendedoraDenied = [
            'user.create', 'user.update', 'user.delete', 'user.getAll', 'user.get',
            'tenant.create', 'tenant.update', 'tenant.delete', 'tenant.getAll', 'tenant.get',
            'warehouse.create', 'warehouse.update', 'warehouse.delete',
            'role.create', 'role.update', 'role.delete', 'role.getAll', 'role.get', 'role.syncPermissions', 'role.permissionsIndex',
            'report.index',
            'financialSummary.getSummary',
            'cashflow.getAdminMonthlyReport',
        ];

        $vendedoraPermissions = Permission::query()
            ->where('guard_name', $guard)
            ->whereNotIn('name', $vendedoraDenied)
            ->get();

        $roleVendedora->syncPermissions($vendedoraPermissions);

        $super = User::query()->firstOrCreate(
            ['email' => 'superadmin@test.com'],
            [
                'username' => 'superadmin',
                'name' => 'Super',
                'surname' => 'Admin',
                'password' => 'password',
                'warehouse_id' => 1,
                'tenant_id' => 1,
            ]
        );
        $super->syncRoles([$roleSuper]);

        $vendedora = User::query()->firstOrCreate(
            ['email' => 'vendedora@test.com'],
            [
                'username' => 'vendedora',
                'name' => 'María',
                'surname' => 'Vendedora',
                'password' => 'password',
                'warehouse_id' => 1,
                'tenant_id' => 1,
            ]
        );
        $vendedora->syncRoles([$roleVendedora]);

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
