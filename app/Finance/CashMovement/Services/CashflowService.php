<?php

namespace App\Finance\CashMovement\Services;

use App\Directory\Team\Models\TeamPayment;
use App\Finance\AccumulatedAccount\Services\AccumulatedAccountService;
use App\Finance\CashMovement\Models\CashMovement;
use App\Finance\CashMovement\Models\CashMovementVoucher;
use App\Finance\Sale\Models\Sale;
use App\Shared\Foundation\Services\NodeUploaderService;
use Carbon\Carbon;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\UploadedFile;

class CashflowService
{
    public function __construct(
        protected NodeUploaderService $nodeUploaderService,
        protected AccumulatedAccountService $accumulatedAccountService,
    ) {
    }

    public function getDailyReport(string $date, array $activeFilters = ['CASH', 'YAPE', 'CARD'])
    {
        $startOfDay = Carbon::parse($date)->startOfDay();
        $endOfDay = Carbon::parse($date)->endOfDay();

        $sales = Sale::whereBetween('creation_time', [$startOfDay, $endOfDay])
            ->where('status', 'COMPLETED')
            ->where('is_deleted', false)
            ->with(['payments', 'details'])
            ->orderBy('creation_time', 'desc')
            ->get()
            ->map(function ($sale) use ($activeFilters) {
                $filteredAmount = $sale->payments
                    ->whereIn('method', $activeFilters)
                    ->sum('amount');

                if ($filteredAmount <= 0) {
                    return null;
                }

                $itemsDescription = $sale->details->map(function ($detail) {
                    return "{$detail->product_name_snapshot} | {$detail->size_name_snapshot} | {$detail->color_name_snapshot}";
                })->implode(' + ');

                return [
                    'id' => $sale->id,
                    'type' => 'SALE',
                    'time' => $sale->creation_time->format('H:i A'),
                    'description' => "{$sale->code} | {$itemsDescription}",
                    'method' => $sale->payment_method,
                    'amount' => (float) $filteredAmount,
                ];
            })->filter()->values();

        $movementModels = CashMovement::query()
            ->whereBetween('date', [$startOfDay, $endOfDay])
            ->where('is_deleted', false)
            ->where('category', CashMovement::CATEGORY_STORE)
            ->whereIn('payment_method', $activeFilters)
            ->orderBy('date', 'desc')
            ->with('vouchers')
            ->get();

        $incomes = $movementModels->where('type', 'INCOME')->values();
        $expenses = $movementModels->where('type', 'EXPENSE')->values();

        $totalSales = $sales->sum('amount');
        $totalIncomes = $incomes->sum('amount');
        $totalExpenses = $expenses->sum('amount');

        return [
            'summary' => [
                'opening_balance' => 100.00,
                'total_sales' => $totalSales,
                'total_incomes' => $totalIncomes,
                'total_expenses' => $totalExpenses,
            ],
            'lists' => [
                'sales' => $sales,
                'incomes' => $incomes,
                'expenses' => $expenses,
            ],
        ];
    }

    public function getMonthlyAdminExpenses(string $month)
    {
        $date = Carbon::parse($month);
        $year = $date->year;
        $monthNum = $date->month;

        $accountingMonth = $date->format('Y-m');

        $expenses = CashMovement::query()
            ->administrativeExpenses()
            ->where(function ($query) use ($accountingMonth, $year, $monthNum): void {
                $query->where('accounting_month', $accountingMonth)
                    ->orWhere(function ($legacy) use ($year, $monthNum): void {
                        $legacy->whereNull('accounting_month')
                            ->whereYear('date', $year)
                            ->whereMonth('date', $monthNum);
                    });
            })
            ->where('is_deleted', false)
            ->with('vouchers')
            ->orderBy('date', 'desc')
            ->get();

        return [
            'month' => $date->format('F Y'),
            'total_monthly_admin' => $expenses->sum('amount'),
            'expenses' => $expenses,
        ];
    }

    public function getMonthlyAccumulatedExpenses(string $month)
    {
        $date = Carbon::parse($month);
        $startOfMonth = $date->copy()->startOfMonth()->startOfDay();
        $endOfMonth = $date->copy()->endOfMonth()->endOfDay();

        $expenses = CashMovement::query()
            ->accumulatedExpenses()
            ->whereBetween('date', [$startOfMonth, $endOfMonth])
            ->where('is_deleted', false)
            ->with('vouchers')
            ->orderBy('date', 'desc')
            ->get();

        return [
            'month' => $date->format('F Y'),
            'total_monthly_accumulated' => $expenses->sum('amount'),
            'expenses' => $expenses,
        ];
    }

    /**
     * Registra un movimiento manual. Acepta uno o varios vouchers.
     *
     * @param array<UploadedFile>|UploadedFile|null $images
     */
    public function registerMovement(array $data, array|UploadedFile|null $images = null): CashMovement
    {
        $imageArray = $this->normalizeImages($images);

        // Para el primer voucher mantenemos compatibilidad con voucher_path en cash_movements
        $firstPath = null;
        if (! empty($imageArray)) {
            $firstPath = $this->uploadToNode($imageArray[0]);
        }

        $movement = CashMovement::create([
            'type' => $data['type'],
            'category' => $data['category'],
            'amount' => $data['amount'],
            'description' => $data['description'],
            'voucher_path' => $data['voucher_path'] ?? $firstPath,
            'payment_method' => $data['payment_method'] ?? 'CASH',
            'purchase_id' => $data['purchase_id'] ?? null,
            'creator_user_id' => $this->resolveAuthenticatedUserId(),
            'date' => $data['date'],
            'accounting_month' => $data['accounting_month'] ?? null,
            'payroll_period' => $data['payroll_period'] ?? null,
        ]);

        // Guardar todos los vouchers en la tabla dedicada
        $paths = [];
        if ($firstPath !== null) {
            $paths[] = $firstPath;
        }
        foreach (array_slice($imageArray, 1) as $extra) {
            $paths[] = $this->uploadToNode($extra);
        }

        foreach ($paths as $order => $path) {
            CashMovementVoucher::create([
                'cash_movement_id' => $movement->id,
                'voucher_path' => $path,
                'sort_order' => $order,
            ]);
        }

        if ($movement->category === CashMovement::CATEGORY_ACCUMULATED) {
            $this->accumulatedAccountService->applyExpenseToBalance($movement);
        }

        return $movement->load('vouchers');
    }

    public function registerAdministrativeExpense(array $data, array|UploadedFile|null $images = null): CashMovement
    {
        $data['type'] = CashMovement::TYPE_EXPENSE;
        $data['category'] = CashMovement::CATEGORY_ADMINISTRATIVE;

        return $this->registerMovement($data, $images);
    }

    public function registerAccumulatedExpense(array $data, array|UploadedFile|null $images = null): CashMovement
    {
        $data['type'] = CashMovement::TYPE_EXPENSE;
        $data['category'] = CashMovement::CATEGORY_ACCUMULATED;

        return $this->registerMovement($data, $images);
    }

    /**
     * Actualiza un movimiento. Si se pasan nuevas imágenes se agregan (no reemplazan).
     *
     * @param array<UploadedFile>|UploadedFile|null $newImages
     */
    public function updateMovement(int $id, array $data, array|UploadedFile|null $newImages = null): CashMovement
    {
        $movement = CashMovement::with('vouchers')->findOrFail($id);

        $wasAccumulatedApplied = $movement->category === CashMovement::CATEGORY_ACCUMULATED
            && $movement->accumulated_balance_applied;

        if ($wasAccumulatedApplied) {
            $this->accumulatedAccountService->reverseExpenseFromBalance($movement);
            $movement->refresh();
        }

        $imageArray = $this->normalizeImages($newImages);

        if (! empty($imageArray)) {
            $currentCount = $movement->vouchers->count();

            foreach ($imageArray as $order => $file) {
                $path = $this->uploadToNode($file);
                CashMovementVoucher::create([
                    'cash_movement_id' => $movement->id,
                    'voucher_path' => $path,
                    'sort_order' => $currentCount + $order,
                ]);

                // Actualizar el campo legacy para el primer voucher nuevo si aún no tiene
                if ($currentCount === 0 && $order === 0 && $movement->voucher_path === null) {
                    $data['voucher_path'] = $path;
                }
            }
        }

        $movement->update([
            'type' => $data['type'] ?? $movement->type,
            'category' => $data['category'] ?? $movement->category,
            'amount' => $data['amount'] ?? $movement->amount,
            'description' => $data['description'] ?? $movement->description,
            'voucher_path' => $data['voucher_path'] ?? $movement->voucher_path,
            'payment_method' => $data['payment_method'] ?? $movement->payment_method,
            'last_modifier_user_id' => $this->resolveAuthenticatedUserId(),
            'last_modification_time' => now(),
            'date' => $data['date'] ?? $movement->date,
            'accounting_month' => $data['accounting_month'] ?? $movement->accounting_month,
            'payroll_period' => array_key_exists('payroll_period', $data)
                ? $data['payroll_period']
                : $movement->payroll_period,
        ]);

        $movement = $movement->fresh(['vouchers']);

        if ($movement->category === CashMovement::CATEGORY_ACCUMULATED && ! $movement->is_deleted) {
            $this->accumulatedAccountService->applyExpenseToBalance($movement);
        }

        return $movement;
    }

    public function deleteMovement(int $id): void
    {
        $movement = CashMovement::with('vouchers')->findOrFail($id);

        if ($movement->is_deleted) {
            throw new \Exception('Movimiento no encontrado.');
        }

        if ($movement->purchase_id !== null
            || $movement->category === CashMovement::CATEGORY_INVENTORY_PURCHASE) {
            throw new \Exception('No se puede eliminar un movimiento vinculado a una compra.');
        }

        if (TeamPayment::query()
            ->where('cash_movement_id', $movement->id)
            ->where('is_deleted', false)
            ->exists()) {
            throw new \Exception('No se puede eliminar: está vinculado a un pago de nómina.');
        }

        if ($movement->category === CashMovement::CATEGORY_ACCUMULATED
            && $movement->accumulated_balance_applied) {
            $this->accumulatedAccountService->reverseExpenseFromBalance($movement);
            $movement->refresh();
        }

        $movement->forceFill([
            'is_deleted' => true,
            'deleter_user_id' => $this->resolveAuthenticatedUserId(),
            'deletion_time' => now(),
        ])->save();
    }

    public function streamVoucher(string $path): array
    {
        if (str_contains($path, '..')) {
            abort(403, 'Path no permitido.');
        }

        if (! preg_match('#^/uploads/vouchers/[a-f0-9\\-]+\\.(jpe?g|png|webp|pdf)$#i', $path)) {
            abort(403, 'Path no válido.');
        }

        // Buscar primero en la tabla dedicada de vouchers (nueva estructura)
        $voucherRecord = CashMovementVoucher::query()
            ->where('voucher_path', $path)
            ->first();

        if ($voucherRecord !== null) {
            $movement = CashMovement::query()
                ->where('id', $voucherRecord->cash_movement_id)
                ->where('is_deleted', false)
                ->first();
        } else {
            // Fallback: voucher_path en la propia tabla cash_movements (registros legacy)
            $movement = CashMovement::query()
                ->where('voucher_path', $path)
                ->where('is_deleted', false)
                ->first();
        }

        if ($movement === null) {
            abort(404, 'Comprobante no encontrado.');
        }

        $this->authorizeVoucherAccess($movement);

        $response = $this->nodeUploaderService->fetch($path);

        return [
            'body' => $response->body(),
            'content_type' => $this->resolveVoucherContentType($path, $response->header('Content-Type')),
            'filename' => basename($path),
        ];
    }

    private function resolveVoucherContentType(string $path, ?string $header): string
    {
        if (is_string($header) && $header !== '' && ! str_contains(strtolower($header), 'octet-stream')) {
            return trim(explode(';', $header)[0]);
        }

        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };
    }

    private function authorizeVoucherAccess(CashMovement $movement): void
    {
        if ($movement->category === CashMovement::CATEGORY_ADMINISTRATIVE) {
            $user = auth()->user();

            if ($user === null) {
                abort(403, 'Acceso denegado.');
            }

            $linkedToTeamPayment = TeamPayment::query()
                ->where('cash_movement_id', $movement->id)
                ->where('is_deleted', false)
                ->exists();

            $canViewAdmin = $user->can('cashflow.getAdminMonthlyReport');
            $canViewPayrollVoucher = $linkedToTeamPayment && $user->can('team.getPaymentByMonth');

            if (! $canViewAdmin && ! $canViewPayrollVoucher) {
                abort(403, 'Acceso denegado.');
            }
        }

        if ($movement->category === CashMovement::CATEGORY_ACCUMULATED) {
            $user = auth()->user();

            if ($user === null || ! $user->can('cashflow.getAccumulatedExpensesReport')) {
                abort(403, 'Acceso denegado.');
            }
        }

        if ($movement->category === CashMovement::CATEGORY_INVENTORY_PURCHASE) {
            $user = auth()->user();

            if ($user === null || ! $user->can('purchase.get')) {
                abort(403, 'Acceso denegado.');
            }
        }
    }

    private function uploadToNode(UploadedFile $file): string
    {
        return $this->nodeUploaderService->upload($file, 'vouchers');
    }

    private function deleteFromNode(string $path): void
    {
        $this->nodeUploaderService->delete($path);
    }

    /**
     * Normaliza el parámetro de imágenes a un array de UploadedFile.
     *
     * @param array<UploadedFile>|UploadedFile|null $input
     * @return array<UploadedFile>
     */
    private function normalizeImages(array|UploadedFile|null $input): array
    {
        if ($input === null) {
            return [];
        }

        if ($input instanceof UploadedFile) {
            return [$input];
        }

        return array_values(array_filter($input, static fn ($f) => $f instanceof UploadedFile));
    }

    private function resolveAuthenticatedUserId(): int
    {
        $userId = auth()->id();

        if ($userId === null) {
            throw new AuthenticationException('No autorizado.');
        }

        return (int) $userId;
    }
}
