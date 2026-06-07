<?php

namespace App\Finance\AccumulatedAccount\Services;

use App\Finance\AccumulatedAccount\Models\AccumulatedAccountSetting;
use App\Finance\AccumulatedAccount\Models\AccumulatedAccountTransfer;
use App\Finance\CashMovement\Models\CashMovement;
use App\Report\Services\ReportService;
use Carbon\Carbon;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccumulatedAccountService
{
    public function __construct(
        protected ReportService $reportService,
    ) {
    }

    /**
     * @return array{
     *     cash_balance: float,
     *     digital_balance: float,
     *     initial_cash: float,
     *     initial_digital: float,
     *     is_initialized: bool,
     *     tracking_start_month: string|null,
     *     current_cash: float,
     *     current_digital: float,
     *     current_total: float
     * }
     */
    public function getSettings(): array
    {
        $setting = AccumulatedAccountSetting::query()->first();

        if ($setting !== null && $setting->is_initialized) {
            $this->syncPendingExpenseDeductions($setting);
            $setting->refresh();
        }

        $cash = (float) ($setting?->cash_balance ?? 0);
        $digital = (float) ($setting?->digital_balance ?? 0);

        return [
            'cash_balance' => $cash,
            'digital_balance' => $digital,
            'initial_cash' => (float) ($setting?->initial_cash ?? 0),
            'initial_digital' => (float) ($setting?->initial_digital ?? 0),
            'is_initialized' => (bool) ($setting?->is_initialized ?? false),
            'tracking_start_month' => $setting?->tracking_start_month,
            'current_cash' => $cash,
            'current_digital' => $digital,
            'current_total' => $cash + $digital,
        ];
    }

    /**
     * Descuenta un egreso ACCUMULATED del saldo vivo de la Cuenta Acumulada.
     */
    public function applyExpenseToBalance(CashMovement $movement): void
    {
        if ($movement->category !== CashMovement::CATEGORY_ACCUMULATED || $movement->is_deleted) {
            return;
        }

        if ($movement->accumulated_balance_applied) {
            return;
        }

        $setting = AccumulatedAccountSetting::query()->first();

        if ($setting === null || ! $setting->is_initialized) {
            return;
        }

        $this->adjustBalanceByPaymentMethod(
            $setting,
            (string) $movement->payment_method,
            -(float) $movement->amount,
        );

        $setting->last_modification_time = now();
        $setting->last_modifier_user_id = $this->resolveAuthenticatedUserId();
        $setting->save();

        $movement->accumulated_balance_applied = true;
        $movement->save();
    }

    /**
     * Revierte un egreso previamente descontado (edición o eliminación).
     */
    public function reverseExpenseFromBalance(CashMovement $movement): void
    {
        if ($movement->category !== CashMovement::CATEGORY_ACCUMULATED) {
            return;
        }

        if (! $movement->accumulated_balance_applied) {
            return;
        }

        $setting = AccumulatedAccountSetting::query()->first();

        if ($setting === null || ! $setting->is_initialized) {
            return;
        }

        $this->adjustBalanceByPaymentMethod(
            $setting,
            (string) $movement->payment_method,
            (float) $movement->amount,
        );

        $setting->last_modification_time = now();
        $setting->last_modifier_user_id = $this->resolveAuthenticatedUserId();
        $setting->save();

        $movement->accumulated_balance_applied = false;
        $movement->save();
    }

    /**
     * Aplica deducciones pendientes (p. ej. egresos históricos antes del deploy).
     */
    public function syncPendingExpenseDeductions(?AccumulatedAccountSetting $setting = null): void
    {
        $setting ??= AccumulatedAccountSetting::query()->first();

        if ($setting === null || ! $setting->is_initialized) {
            return;
        }

        $pending = CashMovement::query()
            ->accumulatedExpenses()
            ->where('is_deleted', false)
            ->where('accumulated_balance_applied', false)
            ->orderBy('id')
            ->get();

        foreach ($pending as $movement) {
            $this->applyExpenseToBalance($movement);
            $setting->refresh();
        }
    }

    private function adjustBalanceByPaymentMethod(
        AccumulatedAccountSetting $setting,
        string $paymentMethod,
        float $delta,
    ): void {
        if ($this->isDigitalPaymentMethod($paymentMethod)) {
            $setting->digital_balance = round((float) $setting->digital_balance + $delta, 2);

            return;
        }

        $setting->cash_balance = round((float) $setting->cash_balance + $delta, 2);
    }

    private function isDigitalPaymentMethod(string $paymentMethod): bool
    {
        return in_array(strtoupper($paymentMethod), ['YAPE', 'PLIN', 'CARD', 'TRANSFER'], true);
    }

    public function initializeSettings(array $data): AccumulatedAccountSetting
    {
        $existing = AccumulatedAccountSetting::query()->first();

        if ($existing !== null && $existing->is_initialized) {
            throw ValidationException::withMessages([
                'initial_cash' => 'La Cuenta Acumulada ya fue inicializada.',
            ]);
        }

        $initialCash = (float) $data['initial_cash'];
        $initialDigital = (float) $data['initial_digital'];

        if ($existing === null) {
            $existing = new AccumulatedAccountSetting([
                'creation_time' => now(),
                'creator_user_id' => $this->resolveAuthenticatedUserId(),
            ]);
        }

        $existing->fill([
            'initial_cash' => $initialCash,
            'initial_digital' => $initialDigital,
            'cash_balance' => $initialCash,
            'digital_balance' => $initialDigital,
            'tracking_start_month' => $data['tracking_start_month'],
            'is_initialized' => true,
            'last_modification_time' => now(),
            'last_modifier_user_id' => $this->resolveAuthenticatedUserId(),
        ]);
        $existing->save();

        return $existing;
    }

    public function updateSettings(array $data): AccumulatedAccountSetting
    {
        $setting = AccumulatedAccountSetting::query()->first();

        if ($setting === null || ! $setting->is_initialized) {
            throw ValidationException::withMessages([
                'cash_balance' => 'Primero debes inicializar la Cuenta Acumulada.',
            ]);
        }

        $setting->fill([
            'cash_balance' => $data['cash_balance'],
            'digital_balance' => $data['digital_balance'],
            'tracking_start_month' => $data['tracking_start_month'] ?? $setting->tracking_start_month,
            'last_modification_time' => now(),
            'last_modifier_user_id' => $this->resolveAuthenticatedUserId(),
        ]);
        $setting->save();

        return $setting;
    }

    /**
     * Vista previa del traspaso de excedente operativo → Cuenta Acumulada.
     *
     * @return array{
     *     month: string,
     *     month_label: string,
     *     operational: array{cash: float, digital: float, total: float},
     *     suggested: array{cash: float, digital: float, total: float},
     *     already_transferred: bool,
     *     existing_transfer: array<string, mixed>|null,
     *     balances: array{
     *         current: array{cash: float, digital: float, total: float},
     *         after_suggested: array{cash: float, digital: float, total: float}
     *     }
     * }
     */
    public function getMonthEndTransferPreview(string $month): array
    {
        $this->assertInitialized();

        $month = $this->normalizeMonthKey($month);
        $operational = $this->resolveOperationalMonthRow($month);
        $settings = $this->getSettings();
        $existing = AccumulatedAccountTransfer::query()
            ->where('transfer_month', $month)
            ->first();

        $operationalCash = (float) ($operational['efectivo'] ?? 0);
        $operationalDigital = (float) ($operational['bancos'] ?? 0);

        $suggestedCash = max(0, round($operationalCash, 2));
        $suggestedDigital = max(0, round($operationalDigital, 2));

        $currentCash = (float) $settings['current_cash'];
        $currentDigital = (float) $settings['current_digital'];

        return [
            'month' => $month,
            'month_label' => $this->formatMonthLabel($month),
            'operational' => [
                'cash' => round($operationalCash, 2),
                'digital' => round($operationalDigital, 2),
                'total' => round($operationalCash + $operationalDigital, 2),
            ],
            'suggested' => [
                'cash' => $suggestedCash,
                'digital' => $suggestedDigital,
                'total' => round($suggestedCash + $suggestedDigital, 2),
            ],
            'already_transferred' => $existing !== null,
            'existing_transfer' => $existing !== null ? $this->formatTransfer($existing) : null,
            'balances' => [
                'current' => [
                    'cash' => $currentCash,
                    'digital' => $currentDigital,
                    'total' => round($currentCash + $currentDigital, 2),
                ],
                'after_suggested' => [
                    'cash' => round($currentCash + $suggestedCash, 2),
                    'digital' => round($currentDigital + $suggestedDigital, 2),
                    'total' => round($currentCash + $currentDigital + $suggestedCash + $suggestedDigital, 2),
                ],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listMonthEndTransfers(?string $month = null, int $limit = 12): array
    {
        $query = AccumulatedAccountTransfer::query()
            ->orderByDesc('transfer_month')
            ->limit($limit);

        if ($month !== null && $month !== '') {
            $query->where('transfer_month', $this->normalizeMonthKey($month));
        }

        return $query->get()
            ->map(fn (AccumulatedAccountTransfer $transfer) => $this->formatTransfer($transfer))
            ->values()
            ->all();
    }

    /**
     * @param  array{transfer_month: string, cash_amount: float|int, digital_amount: float|int, note?: string|null}  $data
     */
    public function recordMonthEndTransfer(array $data): AccumulatedAccountTransfer
    {
        $this->assertInitialized();

        $month = $this->normalizeMonthKey($data['transfer_month']);
        $cashAmount = round((float) $data['cash_amount'], 2);
        $digitalAmount = round((float) $data['digital_amount'], 2);

        if ($cashAmount <= 0 && $digitalAmount <= 0) {
            throw ValidationException::withMessages([
                'cash_amount' => 'Indica al menos un monto en efectivo o digital para traspasar.',
            ]);
        }

        if (AccumulatedAccountTransfer::query()->where('transfer_month', $month)->exists()) {
            throw ValidationException::withMessages([
                'transfer_month' => "Ya registraste un traspaso para {$this->formatMonthLabel($month)}.",
            ]);
        }

        $operational = $this->resolveOperationalMonthRow($month);

        return DB::transaction(function () use ($month, $cashAmount, $digitalAmount, $data, $operational): AccumulatedAccountTransfer {
            $setting = AccumulatedAccountSetting::query()->firstOrFail();

            $setting->cash_balance = round((float) $setting->cash_balance + $cashAmount, 2);
            $setting->digital_balance = round((float) $setting->digital_balance + $digitalAmount, 2);
            $setting->last_modification_time = now();
            $setting->last_modifier_user_id = $this->resolveAuthenticatedUserId();
            $setting->save();

            $transfer = AccumulatedAccountTransfer::create([
                'transfer_month' => $month,
                'cash_amount' => $cashAmount,
                'digital_amount' => $digitalAmount,
                'operational_cash_snapshot' => (float) ($operational['efectivo'] ?? 0),
                'operational_digital_snapshot' => (float) ($operational['bancos'] ?? 0),
                'note' => isset($data['note']) ? trim((string) $data['note']) : null,
                'creation_time' => now(),
                'creator_user_id' => $this->resolveAuthenticatedUserId(),
            ]);

            return $transfer;
        });
    }

    private function assertInitialized(): void
    {
        $setting = AccumulatedAccountSetting::query()->first();

        if ($setting === null || ! $setting->is_initialized) {
            throw ValidationException::withMessages([
                'transfer_month' => 'Primero debes inicializar la Cuenta Acumulada.',
            ]);
        }
    }

    /**
     * @return array{efectivo?: float, bancos?: float, sort_month?: string, fecha?: string}
     */
    private function resolveOperationalMonthRow(string $month): array
    {
        $row = collect($this->reportService->getAllTimeMonthlyReport())
            ->firstWhere('sort_month', $month);

        return is_array($row) ? $row : [
            'sort_month' => $month,
            'efectivo' => 0.0,
            'bancos' => 0.0,
        ];
    }

    private function normalizeMonthKey(string $month): string
    {
        return Carbon::createFromFormat('Y-m', $month)->format('Y-m');
    }

    private function formatMonthLabel(string $month): string
    {
        return Carbon::createFromFormat('Y-m', $month)
            ->locale('es')
            ->translatedFormat('F Y');
    }

    /**
     * @return array<string, mixed>
     */
    private function formatTransfer(AccumulatedAccountTransfer $transfer): array
    {
        return [
            'id' => $transfer->id,
            'transferMonth' => $transfer->transfer_month,
            'monthLabel' => $this->formatMonthLabel((string) $transfer->transfer_month),
            'cashAmount' => (float) $transfer->cash_amount,
            'digitalAmount' => (float) $transfer->digital_amount,
            'totalAmount' => $transfer->totalAmount(),
            'operationalCashSnapshot' => (float) $transfer->operational_cash_snapshot,
            'operationalDigitalSnapshot' => (float) $transfer->operational_digital_snapshot,
            'note' => $transfer->note,
            'createdAt' => $transfer->creation_time?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @return array{
     *     rows: list<array<string, float|string>>,
     *     opening: array{cash: float, digital: float, total: float},
     *     current: array{cash: float, digital: float, total: float}
     * }
     */
    public function getMonthlyGrowthReport(): array
    {
        $setting = AccumulatedAccountSetting::query()->first();
        $growth = $this->buildMonthlyGrowthRows($setting);
        $manual = $this->getSettings();

        $growth['current'] = [
            'cash' => $manual['current_cash'],
            'digital' => $manual['current_digital'],
            'total' => $manual['current_total'],
        ];

        return $growth;
    }

    /**
     * @return array{
     *     rows: list<array<string, float|string>>,
     *     opening: array{cash: float, digital: float, total: float},
     *     current: array{cash: float, digital: float, total: float}
     * }
     */
    private function buildMonthlyGrowthRows(?AccumulatedAccountSetting $setting): array
    {
        if ($setting === null || ! $setting->is_initialized) {
            return [
                'rows' => [],
                'opening' => ['cash' => 0, 'digital' => 0, 'total' => 0],
                'current' => ['cash' => 0, 'digital' => 0, 'total' => 0],
            ];
        }

        $openingCash = (float) $setting->initial_cash;
        $openingDigital = (float) $setting->initial_digital;
        $trackingStart = $setting->tracking_start_month;

        $operationalRows = collect($this->reportService->getAllTimeMonthlyReport());
        $accumulatedOutflows = $this->getAccumulatedExpensesByMonth();

        $runningCash = $openingCash;
        $runningDigital = $openingDigital;
        $rows = [];

        foreach ($operationalRows as $row) {
            $sortMonth = $row['sort_month'] ?? null;

            if ($sortMonth === null) {
                continue;
            }

            if ($trackingStart !== null && $trackingStart !== '' && $sortMonth < $trackingStart) {
                continue;
            }

            $expenseRow = $accumulatedOutflows->get($sortMonth);
            $cashOut = (float) ($expenseRow->cash_out ?? 0);
            $digitalOut = (float) ($expenseRow->digital_out ?? 0);

            $netCash = (float) $row['efectivo'] - $cashOut;
            $netDigital = (float) $row['bancos'] - $digitalOut;
            $totalMensual = $netCash + $netDigital;

            $runningCash += $netCash;
            $runningDigital += $netDigital;

            $rows[] = [
                'fecha' => $row['fecha'],
                'sort_month' => $sortMonth,
                'efectivo' => $netCash,
                'bancos' => $netDigital,
                'total_mensual' => $totalMensual,
                'saldo_efectivo' => $runningCash,
                'saldo_bancos' => $runningDigital,
                'saldo_total' => $runningCash + $runningDigital,
            ];
        }

        return [
            'rows' => $rows,
            'opening' => [
                'cash' => $openingCash,
                'digital' => $openingDigital,
                'total' => $openingCash + $openingDigital,
            ],
            'current' => [
                'cash' => (float) $setting->cash_balance,
                'digital' => (float) $setting->digital_balance,
                'total' => (float) $setting->cash_balance + (float) $setting->digital_balance,
            ],
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<string, object>
     */
    private function getAccumulatedExpensesByMonth()
    {
        $bancosMethods = "'YAPE','PLIN','CARD','TRANSFER'";

        return CashMovement::query()
            ->accumulatedExpenses()
            ->where('is_deleted', false)
            ->selectRaw("
                TO_CHAR(date, 'YYYY-MM') as sort_month,
                SUM(CASE WHEN payment_method = 'CASH' THEN amount ELSE 0 END) as cash_out,
                SUM(CASE WHEN payment_method IN ({$bancosMethods}) THEN amount ELSE 0 END) as digital_out
            ")
            ->groupByRaw("TO_CHAR(date, 'YYYY-MM')")
            ->get()
            ->keyBy('sort_month');
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
