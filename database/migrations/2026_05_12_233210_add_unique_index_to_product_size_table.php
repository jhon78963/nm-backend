<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            $duplicateGroups = DB::table('product_size')
                ->select(
                    'product_id',
                    'size_id',
                    DB::raw('MIN(id) AS keeper_id'),
                    DB::raw('SUM(stock) AS total_stock'),
                )
                ->groupBy('product_id', 'size_id')
                ->havingRaw('COUNT(*) > 1')
                ->get();

            foreach ($duplicateGroups as $group) {
                $keeperId = (int) $group->keeper_id;
                $productId = (int) $group->product_id;
                $sizeId = (int) $group->size_id;
                $totalStock = (int) $group->total_stock;

                DB::table('product_size')->where('id', $keeperId)->update([
                    'stock' => $totalStock,
                ]);

                $duplicateIds = DB::table('product_size')
                    ->where('product_id', $productId)
                    ->where('size_id', $sizeId)
                    ->where('id', '!=', $keeperId)
                    ->orderBy('id')
                    ->pluck('id');

                foreach ($duplicateIds as $duplicateId) {
                    $duplicateId = (int) $duplicateId;

                    $colorRows = DB::table('product_size_color')
                        ->where('product_size_id', $duplicateId)
                        ->get();

                    foreach ($colorRows as $row) {
                        $colorId = (int) $row->color_id;
                        $rowStock = (int) $row->stock;

                        $existing = DB::table('product_size_color')
                            ->where('product_size_id', $keeperId)
                            ->where('color_id', $colorId)
                            ->first();

                        if ($existing !== null) {
                            DB::table('product_size_color')
                                ->where('product_size_id', $keeperId)
                                ->where('color_id', $colorId)
                                ->update([
                                    'stock' => (int) $existing->stock + $rowStock,
                                ]);
                        } else {
                            DB::table('product_size_color')->insert([
                                'product_size_id' => $keeperId,
                                'color_id' => $colorId,
                                'stock' => $rowStock,
                            ]);
                        }
                    }

                    DB::table('purchase_lines')
                        ->where('product_size_id', $duplicateId)
                        ->update(['product_size_id' => $keeperId]);

                    DB::table('product_size')->where('id', $duplicateId)->delete();
                }
            }

            Schema::table('product_size', function (Blueprint $table) {
                $table->unique(['product_id', 'size_id']);
            });
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_size', function (Blueprint $table) {
            $table->dropUnique(['product_id', 'size_id']);
        });
    }
};
