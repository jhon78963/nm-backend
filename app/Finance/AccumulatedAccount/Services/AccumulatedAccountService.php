<?php

namespace App\Finance\AccumulatedAccount\Services;

use App\Finance\AccumulatedAccount\Models\AccumulatedAccountSetting;
use App\Finance\CashMovement\Models\CashMovement;
use App\Report\Services\ReportService;
use Illuminate\Auth\AuthenticationException;
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
