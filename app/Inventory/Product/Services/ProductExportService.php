<?php

namespace App\Inventory\Product\Services;

use App\Inventory\InventoryLedger\Models\InventoryBalance;
use App\Inventory\Product\Models\Product;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductExportService
{
    private const HEADERS = [
        'Talla',
        'Cód. barras',
        'P. Compra',
        'P. Venta',
        'P. Venta Mín.',
        'Stock Tallas',
        'Código Color',
        'Colores',
        'Stock Actual',
        'Stock Nuevo',
    ];

    // Columns A-J visible; K=product_size_id (hidden), L=color_id (hidden)
    private const LAST_VISIBLE_COL = 'J';
    private const COL_PS_ID       = 'K';
    private const COL_COLOR_ID    = 'L';

    public function export(int $warehouseId): StreamedResponse
    {
        $products = Product::query()
            ->where('is_deleted', false)
            ->where('warehouse_id', $warehouseId)
            ->with([
                'productSizes' => fn ($q) => $q->orderBy('id'),
                'productSizes.size',
                'productSizes.productSizeColors',
            ])
            ->get();

        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Productos');

        $row = 1;

        foreach ($products as $product) {
            // ── Row: product name ──────────────────────────────────────────
            $sheet->setCellValue("A{$row}", $product->name);
            $sheet->mergeCells("A{$row}:" . self::LAST_VISIBLE_COL . "{$row}");
            $this->styleProductName($sheet, $row);
            $row++;

            // ── Row: column headers ────────────────────────────────────────
            foreach (self::HEADERS as $colIdx => $header) {
                $col = Coordinate::stringFromColumnIndex($colIdx + 1);
                $sheet->setCellValue("{$col}{$row}", $header);
            }
            $this->styleHeaders($sheet, $row);
            $row++;

            // ── Rows: one per size × color ────────────────────────────────
            foreach ($product->productSizes as $productSize) {
                $sizeName   = $productSize->size?->description ?? '';
                $colors     = $productSize->productSizeColors;

                // Total stock for this size (master balance, color_id IS NULL)
                $sizeStock = (int) InventoryBalance::query()
                    ->where('warehouse_id', $warehouseId)
                    ->where('product_size_id', $productSize->id)
                    ->whereNull('color_id')
                    ->value('quantity');

                if ($colors->isEmpty()) {
                    $this->writeDataRow(
                        $sheet, $row,
                        $sizeName,
                        $productSize->barcode,
                        $productSize->purchase_price,
                        $productSize->sale_price,
                        $productSize->min_sale_price,
                        $sizeStock,
                        '',
                        '',
                        $sizeStock,
                        (int) $productSize->id,
                        0,
                    );
                    $row++;
                } else {
                    $firstRow = $row;

                    foreach ($colors as $colorIndex => $color) {
                        $colorStock = (int) InventoryBalance::query()
                            ->where('warehouse_id', $warehouseId)
                            ->where('product_size_id', $productSize->id)
                            ->where('color_id', $color->id)
                            ->value('quantity');

                        $this->writeDataRow(
                            $sheet, $row,
                            $colorIndex === 0 ? $sizeName : '',
                            $colorIndex === 0 ? $productSize->barcode : '',
                            $colorIndex === 0 ? $productSize->purchase_price : '',
                            $colorIndex === 0 ? $productSize->sale_price : '',
                            $colorIndex === 0 ? $productSize->min_sale_price : '',
                            $colorIndex === 0 ? $sizeStock : '',
                            (int) $color->id,
                            $color->description,
                            $colorStock,
                            (int) $productSize->id,
                            (int) $color->id,
                        );
                        $row++;
                    }

                    // Merge A-F across all rows of the same size
                    if ($colors->count() > 1) {
                        $lastRow = $row - 1;
                        foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $col) {
                            $sheet->mergeCells("{$col}{$firstRow}:{$col}{$lastRow}");
                            $sheet->getStyle("{$col}{$firstRow}:{$col}{$lastRow}")
                                ->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
                        }
                    }
                }
            }

            $row++; // blank row between products
        }

        // ── Column widths ──────────────────────────────────────────────────
        $sheet->getColumnDimension('A')->setWidth(12);
        $sheet->getColumnDimension('B')->setWidth(20);
        $sheet->getColumnDimension('C')->setWidth(12);
        $sheet->getColumnDimension('D')->setWidth(12);
        $sheet->getColumnDimension('E')->setWidth(14);
        $sheet->getColumnDimension('F')->setWidth(14);
        $sheet->getColumnDimension('G')->setWidth(14); // Código Color
        $sheet->getColumnDimension('H')->setWidth(20); // Colores
        $sheet->getColumnDimension('I')->setWidth(14); // Stock Actual
        $sheet->getColumnDimension('J')->setWidth(14); // Stock Nuevo

        // ── Hide helper columns ────────────────────────────────────────────
        $sheet->getColumnDimension(self::COL_PS_ID)->setVisible(false)->setWidth(0);
        $sheet->getColumnDimension(self::COL_COLOR_ID)->setVisible(false)->setWidth(0);

        $writer = new Xlsx($spreadsheet);

        return new StreamedResponse(
            static function () use ($writer): void {
                $writer->save('php://output');
            },
            200,
            [
                'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="productos_' . date('Ymd_His') . '.xlsx"',
                'Cache-Control'       => 'max-age=0',
                'Pragma'              => 'public',
            ],
        );
    }

    private function writeDataRow(
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
        int $row,
        mixed $talla,
        mixed $barcode,
        mixed $purchasePrice,
        mixed $salePrice,
        mixed $minSalePrice,
        mixed $stockTallas,
        mixed $colorCode,
        mixed $colorName,
        mixed $stockActual,
        int $productSizeId,
        int $colorId,
    ): void {
        $sheet->setCellValue("A{$row}", $talla);
        // Force barcode as string so Excel never formats it in scientific notation
        $sheet->setCellValueExplicit("B{$row}", (string) ($barcode ?? ''), DataType::TYPE_STRING);
        $sheet->setCellValue("C{$row}", $purchasePrice);
        $sheet->setCellValue("D{$row}", $salePrice);
        $sheet->setCellValue("E{$row}", $minSalePrice);
        $sheet->setCellValue("F{$row}", $stockTallas);
        $sheet->setCellValue("G{$row}", $colorCode);
        $sheet->setCellValue("H{$row}", $colorName);
        $sheet->setCellValue("I{$row}", $stockActual);
        $sheet->setCellValue("J{$row}", ''); // Stock Nuevo – blank
        $sheet->setCellValue(self::COL_PS_ID . "{$row}", $productSizeId);
        $sheet->setCellValue(self::COL_COLOR_ID . "{$row}", $colorId);

        $sheet->getStyle("A{$row}:" . self::LAST_VISIBLE_COL . "{$row}")
            ->getBorders()
            ->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN);
    }

    private function styleProductName(
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
        int $row,
    ): void {
        $range = "A{$row}:" . self::LAST_VISIBLE_COL . "{$row}";

        $sheet->getStyle($range)->applyFromArray([
            'font' => ['bold' => true, 'size' => 12],
            'fill' => [
                'fillType'   => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FF1E3A5F'],
            ],
            'font'      => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_LEFT,
                'vertical'   => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN],
            ],
        ]);

        $sheet->getRowDimension($row)->setRowHeight(22);
    }

    private function styleHeaders(
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
        int $row,
    ): void {
        $range = "A{$row}:" . self::LAST_VISIBLE_COL . "{$row}";

        $sheet->getStyle($range)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FF1E3A5F']],
            'fill' => [
                'fillType'   => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FFD9E1F2'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical'   => Alignment::VERTICAL_CENTER,
                'wrapText'   => true,
            ],
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN],
            ],
        ]);

        $sheet->getRowDimension($row)->setRowHeight(18);
    }
}
