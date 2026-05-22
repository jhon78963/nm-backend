<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;

return new class extends Migration
{
    /** @var array<string, string> */
    private array $permissionRenames = [
        'attendance.getByMonth' => 'team.getAttendanceByMonth',
        'attendance.getDailySummary' => 'team.getAttendanceDailySummary',
        'attendance.store' => 'team.storeAttendance',
        'payment.getByMonth' => 'team.getPaymentByMonth',
        'payment.store' => 'team.storePayment',
    ];

    /** @var list<string> */
    private array $orderPermissions = [
        'order.create',
        'order.update',
        'order.delete',
        'order.getAll',
        'order.get',
    ];

    public function up(): void
    {
        if (class_exists(Permission::class)) {
            app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

            foreach ($this->permissionRenames as $oldName => $newName) {
                Permission::query()
                    ->where('guard_name', 'web')
                    ->where('name', $oldName)
                    ->update(['name' => $newName]);
            }

            Permission::query()
                ->where('guard_name', 'web')
                ->whereIn('name', $this->orderPermissions)
                ->delete();

            app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        }

        if (Schema::hasTable('inventory_movements')) {
            DB::table('inventory_movements')
                ->where('reference_type', 'App\\Finance\\Order\\Models\\Order')
                ->delete();
        }

        Schema::dropIfExists('order_details');
        Schema::dropIfExists('orders');
    }

    public function down(): void
    {
        // Sin rollback: orders quedó reemplazado por compras (purchases).
    }
};
