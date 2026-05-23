<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_movements', function (Blueprint $table): void {
            $table->string('expense_category')->nullable()->after('category');
            $table->string('reference_code')->nullable()->after('expense_category');
            $table->unsignedBigInteger('legacy_expense_id')->nullable()->unique()->after('reference_code');
        });

        if (! Schema::hasTable('expenses')) {
            return;
        }

        $expenses = DB::table('expenses')
            ->where('is_deleted', false)
            ->orderBy('id')
            ->get();

        foreach ($expenses as $expense) {
            $alreadyMigrated = DB::table('cash_movements')
                ->where('legacy_expense_id', $expense->id)
                ->exists();

            if ($alreadyMigrated) {
                continue;
            }

            DB::table('cash_movements')->insert([
                'type' => 'EXPENSE',
                'category' => 'ADMINISTRATIVE',
                'expense_category' => $expense->category,
                'date' => $expense->expense_date,
                'amount' => $expense->amount,
                'description' => $expense->description,
                'payment_method' => $this->normalizePaymentMethod($expense->payment_method),
                'reference_code' => $expense->reference_code,
                'legacy_expense_id' => $expense->id,
                'warehouse_id' => $expense->warehouse_id ?? null,
                'creator_user_id' => $expense->user_id ?? $expense->creator_user_id,
                'creation_time' => $expense->creation_time,
                'last_modification_time' => $expense->last_modification_time,
                'last_modifier_user_id' => $expense->last_modifier_user_id,
                'is_deleted' => false,
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('cash_movements', 'legacy_expense_id')) {
            DB::table('cash_movements')
                ->whereNotNull('legacy_expense_id')
                ->delete();
        }

        Schema::table('cash_movements', function (Blueprint $table): void {
            $table->dropUnique(['legacy_expense_id']);
            $table->dropColumn(['expense_category', 'reference_code', 'legacy_expense_id']);
        });
    }

    private function normalizePaymentMethod(?string $method): string
    {
        $normalized = strtoupper(trim((string) $method));

        return match ($normalized) {
            'EFECTIVO', 'CASH' => 'CASH',
            'YAPE' => 'YAPE',
            'CARD', 'TARJETA', 'TRANSFER', 'TRANSFERENCIA' => 'CARD',
            default => $normalized !== '' ? $normalized : 'CASH',
        };
    }
};
