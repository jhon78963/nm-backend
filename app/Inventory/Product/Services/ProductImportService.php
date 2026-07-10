<?php

namespace App\Inventory\Product\Services;

use App\Inventory\Color\Models\Color;
use App\Inventory\InventoryLedger\DTOs\InventoryMovementDTO;
use App\Inventory\InventoryLedger\Enums\InventoryMovementDirection;
use App\Inventory\InventoryLedger\Enums\InventoryMovementType;
use App\Inventory\InventoryLedger\Services\InventoryMovementService;
use App\Inventory\Product\Models\ProductSize;
use App\Inventory\Warehouse\Models\Warehouse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ProductImportService
{
    // Column indices (1-based) matching the export format
    private const COL_TALLA        = 1;  // A
    private const COL_BARCODE      = 2;  // B
    private const COL_PURCHASE     = 3;  // C
    private const COL_SALE         = 4;  // D
    private const COL_MIN_SALE     = 5;  // E
    private const COL_STOCK_TALLAS = 6;  // F
    private const COL_COLOR_CODE   = 7;  // G – Id del color (Código Color)
    private const COL_COLOR        = 8;  // H – Nombre del color
    private const COL_STOCK_ACTUAL = 9;  // I
    private const COL_STOCK_NUEVO  = 10; // J
    private const COL_PS_ID        = 11; // K (hidden)
    private const COL_COLOR_ID     = 12; // L (hidden, compatibilidad)

    public function __construct(
        private readonly InventoryMovementService $inventoryMovementService,
        private readonly ProductSizeColorService $productSizeColorService,
    ) {}

    /**
     * @return array{updated: int, skipped: int, errors: list<string>}
     */
    public function import(UploadedFile $file, int $warehouseId): array
    {
        Warehouse::query()->findOrFail($warehouseId);

        $spreadsheet = IOFactory::load($file->getPathname());
        $sheet       = $spreadsheet->getActiveSheet();

        $updated = 0;
        $skipped = 0;
        $errors  = [];

        $highestRow      = $sheet->getHighestDataRow();
        $lastSizeId      = 0; // carry-forward: last valid product_size_id seen
        $lastProductSize = null;

        for ($row = 1; $row <= $highestRow; $row++) {
            if ($this->isMetaRow($sheet, $row)) {
                if ($this->isProductTitleRow($sheet, $row)) {
                    $lastSizeId      = 0;
                    $lastProductSize = null;
                }
                continue;
            }

            $productSizeId = (int) $this->cellValue($sheet, $row, self::COL_PS_ID);
            $stockNuevo    = $this->cellValue($sheet, $row, self::COL_STOCK_NUEVO);

            // When the hidden column K is filled, update the carry-forward tracker
            if ($productSizeId > 0) {
                $lastSizeId      = $productSizeId;
                $lastProductSize = null; // reset cache for new size
            } else {
                // Fila agregada manualmente (sin ID oculto): usa la última talla vista
                $colorName = trim((string) $this->cellValue($sheet, $row, self::COL_COLOR));
                $colorCode = $this->cellValue($sheet, $row, self::COL_COLOR_CODE);
                $hasPayload = ($colorName !== '' || $colorCode !== '')
                    && $stockNuevo !== ''
                    && $stockNuevo !== null
                    && is_numeric($stockNuevo);

                if ($lastSizeId > 0 && $hasPayload) {
                    $productSizeId = $lastSizeId;
                } else {
                    continue;
                }
            }

            if ($stockNuevo === '' || $stockNuevo === null) {
                $skipped++;
                continue;
            }

            if (! is_numeric($stockNuevo) || (int) $stockNuevo < 0) {
                continue;
            }

            $physicalQty = (int) $stockNuevo;

            try {
                if ($lastProductSize === null || (int) $lastProductSize->id !== $productSizeId) {
                    $lastProductSize = ProductSize::query()->with('product.warehouse')->findOrFail($productSizeId);
                }
                $productSize = $lastProductSize;

                $colorId = $this->resolveColorId(
                    $this->cellValue($sheet, $row, self::COL_COLOR_CODE),
                    $this->cellValue($sheet, $row, self::COL_COLOR),
                    $this->cellValue($sheet, $row, self::COL_COLOR_ID),
                );

                if ($colorId !== null) {
                    $this->productSizeColorService->set(
                        $productSize,
                        $colorId,
                        ['stock' => $physicalQty],
                        true,
                        'Importación Excel',
                    );
                } else {
                    $warehouse = $productSize->product?->warehouse;
                    if ($warehouse === null) {
                        throw new InvalidArgumentException('No se encontró el almacén del producto.');
                    }

                    $dto = new InventoryMovementDTO(
                        tenantId:        (int) $warehouse->tenant_id,
                        warehouseId:     $warehouseId,
                        productSizeId:   $productSizeId,
                        colorId:         null,
                        direction:       InventoryMovementDirection::In,
                        quantity:        1,
                        movementType:    InventoryMovementType::Reconciliation,
                        createdByUserId: Auth::id(),
                    );

                    $this->inventoryMovementService->reconcileToPhysicalQuantity($dto, $physicalQty);
                }

                $updated++;
            } catch (\Throwable $e) {
                $errors[] = "Fila {$row}: {$e->getMessage()}";
            }
        }

        return compact('updated', 'skipped', 'errors');
    }

    /**
     * Resuelve el color por:
     * 1. Id en columna G (Código Color) o L (oculta)
     * 2. Nombre en columna H (busca en BD)
     * 3. Si no existe y hay nombre → crea el color y lo asigna a la talla
     */
    private function resolveColorId(
        mixed $colorCodeRaw,
        mixed $colorNameRaw,
        mixed $hiddenColorIdRaw,
    ): ?int {
        $colorName = trim((string) ($colorNameRaw ?? ''));

        $colorId = $this->parsePositiveInt($colorCodeRaw);
        if ($colorId === null) {
            $colorId = $this->parsePositiveInt($hiddenColorIdRaw);
        }

        if ($colorId !== null) {
            $color = Color::query()
                ->where('is_deleted', false)
                ->find($colorId);

            if ($color === null) {
                throw new InvalidArgumentException("El color con id {$colorId} no existe.");
            }

            return $colorId;
        }

        if ($colorName === '') {
            return null;
        }

        $existing = Color::query()
            ->where('is_deleted', false)
            ->whereRaw('LOWER(description) = ?', [mb_strtolower($colorName)])
            ->first();

        if ($existing !== null) {
            return (int) $existing->id;
        }

        $created = Color::query()->create([
            'description' => $colorName,
        ]);

        return (int) $created->id;
    }

    private function isMetaRow(Worksheet $sheet, int $row): bool
    {
        if ($this->isProductTitleRow($sheet, $row)) {
            return true;
        }

        $talla = mb_strtolower(trim((string) $this->cellValue($sheet, $row, self::COL_TALLA)));
        if ($talla === 'talla') {
            return true;
        }

        $stockLabel = mb_strtolower(trim((string) $this->cellValue($sheet, $row, self::COL_STOCK_NUEVO)));
        if (in_array($stockLabel, ['stock nuevo', 'stock actual'], true)) {
            return true;
        }

        $colorLabel = mb_strtolower(trim((string) $this->cellValue($sheet, $row, self::COL_COLOR)));
        if ($colorLabel === 'colores') {
            return true;
        }

        $colorCodeLabel = mb_strtolower(trim((string) $this->cellValue($sheet, $row, self::COL_COLOR_CODE)));
        if ($colorCodeLabel === 'código color' || $colorCodeLabel === 'codigo color') {
            return true;
        }

        return false;
    }

    private function isProductTitleRow(Worksheet $sheet, int $row): bool
    {
        if ((int) $this->cellValue($sheet, $row, self::COL_PS_ID) > 0) {
            return false;
        }

        $talla = trim((string) $this->cellValue($sheet, $row, self::COL_TALLA));
        if ($talla === '' || mb_strtolower($talla) === 'talla') {
            return false;
        }

        for ($col = self::COL_BARCODE; $col <= self::COL_STOCK_NUEVO; $col++) {
            if (trim((string) $this->cellValue($sheet, $row, $col)) !== '') {
                return false;
            }
        }

        return true;
    }

    private function parsePositiveInt(mixed $value): ?int
    {
        if ($value === '' || $value === null) {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    private function cellValue(Worksheet $sheet, int $row, int $col): mixed
    {
        $column = Coordinate::stringFromColumnIndex($col);
        $value = $sheet->getCell("{$column}{$row}")->getValue();

        if ($value === null) {
            return '';
        }

        return $value;
    }
}
