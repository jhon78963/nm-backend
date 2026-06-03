<?php

namespace App\Directory\Team\Controllers;

use App\Administration\Audit\Services\UserActionLogService;
use App\Administration\Audit\Support\AuditActions;
use App\Directory\Team\Models\Attendance;
use App\Directory\Team\Models\Team;
use App\Directory\Team\Models\TeamPayment;
use App\Directory\Team\Requests\TeamPaymentStoreRequest;
use App\Directory\Team\Requests\TeamPaymentUpdateRequest;
use App\Finance\CashMovement\Models\CashMovement;
use App\Finance\CashMovement\Services\CashflowService;
use App\Shared\Foundation\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    /** Cierre oficial del día (entrada nominal 8:00, salida nominal 19:30). */
    private const OFFICIAL_END_HOUR = 19;

    private const OFFICIAL_END_MINUTE = 30;

    /** Minutos después de las 8:00 sin contar como «deuda de entrada» (8:01–8:10). */
    private const ENTRY_TOLERANCE_MINUTES = 10;

    /** Jornada nominal 8:00–19:30 para prorratear descuento por minutos no cumplidos. */
    private const MINUTES_NOMINAL_SHIFT = 11 * 60 + 30;

    /** Días fijos del mes para valor día, descuentos por falta y prorrateos de nómina. */
    private const PAYROLL_DAYS_IN_MONTH = 30;
    /**
     * Resumen de movimientos (adelantos, pagos quincenales, descuentos) por mes.
     */
    public function getByMonth(Request $request): JsonResponse
    {
        $month = (int) $request->query('month', (int) date('n'));
        $year = (int) $request->query('year', (int) date('Y'));
        $teamId = $request->query('team_id');

        $teams = Team::query()
            ->where('is_deleted', false)
            ->when($teamId, static fn ($query, $id) => $query->where('id', $id))
            ->with(['payments' => static function ($query) use ($month, $year): void {
                $query->whereMonth('date', $month)
                    ->whereYear('date', $year)
                    ->where('is_deleted', false);
            }])
            ->get()
            ->map(static function (Team $team) {
                $advances = (float) $team->payments->where('type', 'ADVANCE')->sum('amount');
                $deductions = (float) $team->payments->where('type', 'DEDUCTION')->sum('amount');
                $payments = (float) $team->payments->where('type', 'PAYMENT')->sum('amount');
                $balance = (float) $team->salary - $deductions - $advances - $payments;

                return [
                    'id' => $team->id,
                    'dni' => $team->dni,
                    'full_name' => "{$team->name} {$team->surname}",
                    'base_salary' => (float) $team->salary,
                    'advances' => $advances,
                    'deductions' => $deductions,
                    'paid' => $payments,
                    'balance' => $balance,
                ];
            });

        return response()->json($teams);
    }

    /**
     * Nómina / vista de pagos: asistencia (faltas + valdeo − recuperación), balance de tiempo
     * (oficial 8:00–19:30), movimientos del mes y estimado a pagar a fin de mes.
     */
    public function getPayroll(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'team_id' => 'required|integer|exists:teams,id',
            'month' => 'required|integer|min:1|max:12',
            'year' => 'required|integer|min:2000|max:2100',
            'period' => 'nullable|in:full,q1,q2',
        ]);

        $teamId = (int) $validated['team_id'];
        $month = (int) $validated['month'];
        $year = (int) $validated['year'];
        $period = $validated['period'] ?? 'full';

        $team = Team::query()
            ->where('is_deleted', false)
            ->whereKey($teamId)
            ->firstOrFail();

        $calendarDaysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
        $payrollDaysInMonth = self::PAYROLL_DAYS_IN_MONTH;
        $salary = round((float) $team->salary, 2);
        $dailyRate = round($salary / $payrollDaysInMonth, 4);

        $attendances = Attendance::query()
            ->where('team_id', $teamId)
            ->whereYear('date', $year)
            ->whereMonth('date', $month)
            ->get();

        $payments = TeamPayment::query()
            ->with(['cashMovement' => static function ($query): void {
                $query->where('is_deleted', false)->with('vouchers');
            }])
            ->where('team_id', $teamId)
            ->where('is_deleted', false)
            ->where(function ($query) use ($year, $month): void {
                $this->applyAccountingMonthFilter($query, $year, $month);
            })
            ->orderByDesc('date')
            ->get();

        $scopeVista = $this->attendanceBreakdown($attendances, $year, $month, $period, $dailyRate);
        $scopeMes = $this->attendanceBreakdown($attendances, $year, $month, 'full', $dailyRate);

        $movMes = $this->paymentSumsForPeriod($payments, null);
        $movQ1 = $this->paymentSumsForPeriod($payments, 'q1');
        $movQ2 = $this->paymentSumsForPeriod($payments, 'q2');
        $movPeriodo = match ($period) {
            'q1' => $movQ1,
            'q2' => $movQ2,
            default => $movMes,
        };

        $descuentoAsistenciaMes = $scopeMes['descuentoPorFaltas'];
        $trasFaltas = round($salary - $descuentoAsistenciaMes, 2);
        $estimadoFinMes = round(
            $salary
                - $descuentoAsistenciaMes
                - $movMes['DEDUCTION']
                - $movMes['ADVANCE']
                - $movMes['PAYMENT'],
            2
        );

        $daysInPeriod = match ($period) {
            'q1', 'q2' => intdiv($payrollDaysInMonth, 2),
            default => $payrollDaysInMonth,
        };
        // Pago quincenal: base fija = 50 % del salario (como «referencia media quincena»),
        // no prorrateo por días del mes (15/28–31).
        $proporcionPeriodo = match ($period) {
            'q1', 'q2' => round($salary / 2, 2),
            default => round($salary, 2),
        };
        $descuentoVista = $scopeVista['descuentoPorFaltas'];
        $netoTrasFaltasPeriodo = round($proporcionPeriodo - $descuentoVista, 2);
        $totalSalidaPeriodo = round(
            $movPeriodo['ADVANCE'] + $movPeriodo['PAYMENT'] + $movPeriodo['DEDUCTION'],
            2
        );
        $restanteAlCierre = round($netoTrasFaltasPeriodo - $totalSalidaPeriodo, 2);

        $cierreDia = match ($period) {
            'q1' => min(15, $calendarDaysInMonth),
            default => $calendarDaysInMonth,
        };
        $cierreLegible = $this->spanishLongDate($year, $month, $cierreDia);

        return response()->json([
            'success' => true,
            'data' => [
                'team' => [
                    'id' => $team->id,
                    'name' => $team->name,
                    'surname' => $team->surname,
                    'dni' => $team->dni,
                    'salary' => $salary,
                ],
                'calendar' => [
                    'month' => $month,
                    'year' => $year,
                    'daysInMonth' => $payrollDaysInMonth,
                    'period' => $period,
                    'periodLabel' => $this->periodLabel($period, $calendarDaysInMonth),
                ],
                'rates' => [
                    'dailyRate' => $dailyRate,
                    'halfMonthReference' => round($salary / 2, 2),
                ],
                'attendanceVista' => $scopeVista,
                'attendanceMesCompleto' => $scopeMes,
                'movementsMonth' => [
                    'advances' => $movMes['ADVANCE'],
                    'payments' => $movMes['PAYMENT'],
                    'deductions' => $movMes['DEDUCTION'],
                ],
                'movementsQuincena1' => [
                    'advances' => $movQ1['ADVANCE'],
                    'payments' => $movQ1['PAYMENT'],
                    'deductions' => $movQ1['DEDUCTION'],
                ],
                'movementsQuincena2' => [
                    'advances' => $movQ2['ADVANCE'],
                    'payments' => $movQ2['PAYMENT'],
                    'deductions' => $movQ2['DEDUCTION'],
                ],
                'movementsVistaPeriodo' => [
                    'advances' => $movPeriodo['ADVANCE'],
                    'payments' => $movPeriodo['PAYMENT'],
                    'deductions' => $movPeriodo['DEDUCTION'],
                ],
                'paymentItems' => $this->formatPaymentItems($payments, $period, $payrollDaysInMonth),
                'estimates' => [
                    'salarioBase' => $salary,
                    'descuentoAsistenciaMesCompleto' => $descuentoAsistenciaMes,
                    'salarioTrasDescuentoFaltas' => $trasFaltas,
                    'estimadoAPagarFinMes' => $estimadoFinMes,
                    'nota' => 'Incluye descuentos por ausencias (Falta/Valdeo netas), proporcional al tiempo no cumplido (retraso desde las 8:00 si el estado es TARDE, o tras tolerancia en otros casos, y salida antes de 19:30), más adelantos, pagos quincenales y descuentos manuales del mes.',
                ],
                'liquidacionPeriodo' => [
                    'period' => $period,
                    'diasEnPeriodo' => $daysInPeriod,
                    'proporcionSalarioPeriodo' => $proporcionPeriodo,
                    'descuentoAsistenciaEnAmbito' => $descuentoVista,
                    'descuentoPorAusenciasEnAmbito' => round((float) $scopeVista['descuentoPorAusencias'], 2),
                    'descuentoPorTiempoNoCumplidoEnAmbito' => round((float) $scopeVista['descuentoPorTiempoNoCumplido'], 2),
                    'netoTrasFaltasPeriodo' => $netoTrasFaltasPeriodo,
                    'adelantosPeriodo' => $movPeriodo['ADVANCE'],
                    'pagosRegistradosPeriodo' => $movPeriodo['PAYMENT'],
                    'descuentosManualesPeriodo' => $movPeriodo['DEDUCTION'],
                    'totalMovimientosSalida' => $totalSalidaPeriodo,
                    'restanteEstimadoAlCierre' => $restanteAlCierre,
                    'fechaCierreLegible' => $cierreLegible,
                ],
            ],
        ]);
    }

    public function store(TeamPaymentStoreRequest $request, CashflowService $cashflowService): JsonResponse
    {
        $validated = $request->validated();

        $validated['payment_method'] = $this->normalizePaymentMethod(
            (string) $validated['payment_method'],
        );

        $syncCash = filter_var(
            $request->input('sync_cash_movement', false),
            FILTER_VALIDATE_BOOLEAN
        );

        $receiptImage = $request->file('images') ?: null;

        $team = Team::query()
            ->where('is_deleted', false)
            ->whereKey((int) $validated['team_id'])
            ->firstOrFail();

        $movement = DB::transaction(function () use (
            $validated,
            $syncCash,
            $team,
            $cashflowService,
            $receiptImage,
        ) {
            $teamPayment = TeamPayment::create([
                'team_id' => (int) $validated['team_id'],
                'type' => $validated['type'],
                'amount' => $validated['amount'],
                'date' => $validated['date'],
                'payroll_period' => $validated['payroll_period'],
                'accounting_month' => $validated['accounting_month'],
                'payment_method' => $validated['payment_method'],
                'description' => $validated['description'] ?? null,
                'creator_user_id' => auth()->id(),
            ]);

            if ($syncCash) {
                $typeLabel = match ($validated['type']) {
                    'ADVANCE' => 'Adelanto',
                    'PAYMENT' => 'Pago quincenal',
                    'DEDUCTION' => 'Descuento manual',
                };
                $description = 'Nómina personal: '
                    . $team->name
                    . ' '
                    . $team->surname
                    . ' — '
                    . $typeLabel;
                if (! empty($validated['description'])) {
                    $description .= ' — ' . $validated['description'];
                }

                $cashMovement = $cashflowService->registerMovement([
                    'type' => CashMovement::TYPE_EXPENSE,
                    'category' => CashMovement::CATEGORY_ADMINISTRATIVE,
                    'amount' => (float) $validated['amount'],
                    'description' => $description,
                    'date' => $validated['date'],
                    'accounting_month' => $validated['accounting_month'],
                    'payroll_period' => $validated['payroll_period'],
                    'payment_method' => $validated['payment_method'],
                ], $receiptImage);

                $teamPayment->cash_movement_id = $cashMovement->id;
                $teamPayment->save();
            }

            return $teamPayment->fresh(['cashMovement']);
        });

        UserActionLogService::log(
            AuditActions::TEAM_PAYMENT_CREATED,
            metadata: [
                'team_payment_id' => $movement->id,
                'team_id' => (int) $validated['team_id'],
            ],
        );

        return response()->json([
            'message' => 'Movimiento registrado correctamente',
            'data' => $this->formatPaymentItem($movement),
        ]);
    }

    /**
     * Actualiza fecha, tipo, monto o descripción de un pago registrado.
     * Si está vinculado a un cash_movement, sincroniza fecha y monto en ambas tablas.
     */
    public function update(TeamPaymentUpdateRequest $request, TeamPayment $teamPayment): JsonResponse
    {
        if ($teamPayment->is_deleted) {
            abort(404, 'Movimiento no encontrado.');
        }

        $validated = $request->validated();

        $validated['payment_method'] = $this->normalizePaymentMethod(
            (string) $validated['payment_method'],
        );

        DB::transaction(function () use ($teamPayment, $validated): void {
            $teamPayment->date = $validated['date'];
            $teamPayment->payroll_period = $validated['payroll_period'];
            $teamPayment->accounting_month = $validated['accounting_month'];
            $teamPayment->type = $validated['type'];
            $teamPayment->amount = $validated['amount'];
            $teamPayment->payment_method = $validated['payment_method'];
            $teamPayment->description = $validated['description'] ?? null;
            $teamPayment->save();

            // Sincronizar el cash_movement vinculado si existe
            if ($teamPayment->cash_movement_id !== null) {
                $movement = CashMovement::find($teamPayment->cash_movement_id);
                if ($movement && ! $movement->is_deleted) {
                    $movement->date = $validated['date'];
                    $movement->amount = $validated['amount'];
                    $movement->payment_method = $validated['payment_method'];
                    $movement->accounting_month = $validated['accounting_month'];
                    $movement->payroll_period = $validated['payroll_period'];
                    $movement->save();
                }
            }
        });

        UserActionLogService::log(
            AuditActions::TEAM_PAYMENT_UPDATED,
            metadata: [
                'team_payment_id' => $teamPayment->id,
                'team_id' => $teamPayment->team_id,
            ],
        );

        $teamPayment->load(['cashMovement' => static function ($query): void {
            $query->where('is_deleted', false)->with('vouchers');
        }]);

        return response()->json([
            'success' => true,
            'message' => 'Movimiento actualizado correctamente.',
            'data' => $this->formatPaymentItem($teamPayment),
        ]);
    }

    /**
     * Elimina (soft delete) un pago de nómina y, si aplica, el gasto administrativo vinculado.
     */
    public function destroy(TeamPayment $teamPayment): JsonResponse
    {
        $this->authorize('delete', $teamPayment);

        if ($teamPayment->is_deleted) {
            abort(404, 'Movimiento no encontrado.');
        }

        $teamPaymentId = $teamPayment->id;
        $teamId = $teamPayment->team_id;

        DB::transaction(function () use ($teamPayment): void {
            if ($teamPayment->cash_movement_id !== null) {
                $movement = CashMovement::query()
                    ->whereKey($teamPayment->cash_movement_id)
                    ->where('is_deleted', false)
                    ->first();

                if ($movement !== null) {
                    $movement->is_deleted = true;
                    $movement->deleter_user_id = auth()->id();
                    $movement->deletion_time = now();
                    $movement->save();
                }
            }

            $teamPayment->is_deleted = true;
            $teamPayment->deleter_user_id = auth()->id();
            $teamPayment->deletion_time = now();
            $teamPayment->save();
        });

        UserActionLogService::log(
            AuditActions::TEAM_PAYMENT_DELETED,
            metadata: [
                'team_payment_id' => $teamPaymentId,
                'team_id' => $teamId,
            ],
        );

        return response()->json([
            'success' => true,
            'message' => 'Movimiento eliminado correctamente.',
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function formatPaymentItems(Collection $payments, string $period, int $daysInMonth): array
    {
        return $payments
            ->filter(fn (TeamPayment $payment) => $this->paymentBelongsToViewPeriod($payment, $period))
            ->sortByDesc(static fn (TeamPayment $payment) => Carbon::parse($payment->date)->timestamp)
            ->map(fn (TeamPayment $payment) => $this->formatPaymentItem($payment))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function formatPaymentItem(TeamPayment $payment): array
    {
        $cashMovement = $payment->relationLoaded('cashMovement')
            ? $payment->cashMovement
            : null;

        // Obtener todos los vouchers del movimiento de caja (nueva tabla)
        $voucherPaths = [];
        if ($cashMovement !== null) {
            if ($cashMovement->relationLoaded('vouchers')) {
                $voucherPaths = $cashMovement->vouchers->pluck('voucher_path')->filter()->values()->all();
            } elseif ($cashMovement->voucher_path) {
                // Fallback legacy
                $voucherPaths = [$cashMovement->voucher_path];
            }
        }

        return [
            'id' => $payment->id,
            'type' => $payment->type,
            'typeLabel' => $this->paymentTypeLabel((string) $payment->type),
            'amount' => (float) $payment->amount,
            'date' => Carbon::parse($payment->date)->format('Y-m-d H:i:s'),
            'payrollPeriod' => $this->resolvePayrollPeriod($payment),
            'payrollPeriodLabel' => $this->payrollPeriodLabel($this->resolvePayrollPeriod($payment)),
            'accountingMonth' => $payment->accounting_month,
            'accountingPeriodLabel' => $this->accountingPeriodLabel(
                $payment->accounting_month,
                $this->resolvePayrollPeriod($payment),
            ),
            'description' => $payment->description,
            'syncedToAdmin' => $payment->cash_movement_id !== null,
            'cashMovementId' => $payment->cash_movement_id,
            'paymentMethod' => $payment->payment_method
                ?? $cashMovement?->payment_method
                ?? 'CASH',
            'voucherPath' => $cashMovement?->voucher_path,
            'voucherPaths' => $voucherPaths,
            'adminExpenseDescription' => $cashMovement?->description,
        ];
    }

    private function paymentTypeLabel(string $type): string
    {
        return match ($type) {
            'ADVANCE' => 'Adelanto',
            'PAYMENT' => 'Pago quincenal',
            'DEDUCTION' => 'Descuento manual',
            default => $type,
        };
    }

    /**
     * Suma movimientos por quincena de nómina (payroll_period), no por fecha de pago.
     *
     * @return array{ADVANCE: float, PAYMENT: float, DEDUCTION: float}
     */
    private function paymentSumsForPeriod(Collection $payments, ?string $period): array
    {
        $filtered = $payments->filter(
            fn (TeamPayment $p) => $this->paymentBelongsToViewPeriod($p, $period ?? 'full')
        );

        return [
            'ADVANCE' => round((float) $filtered->where('type', 'ADVANCE')->sum('amount'), 2),
            'PAYMENT' => round((float) $filtered->where('type', 'PAYMENT')->sum('amount'), 2),
            'DEDUCTION' => round((float) $filtered->where('type', 'DEDUCTION')->sum('amount'), 2),
        ];
    }

    private function paymentBelongsToViewPeriod(TeamPayment $payment, string $period): bool
    {
        if ($period === 'full') {
            return true;
        }

        return $this->resolvePayrollPeriod($payment) === $period;
    }

    private function resolvePayrollPeriod(TeamPayment $payment): string
    {
        if (in_array($payment->payroll_period, ['q1', 'q2'], true)) {
            return $payment->payroll_period;
        }

        // Fallback legacy: inferir desde el día de la fecha
        $day = (int) Carbon::parse($payment->date)->format('j');

        return $day <= 15 ? 'q1' : 'q2';
    }

    private function payrollPeriodLabel(string $period): string
    {
        return match ($period) {
            'q1' => 'Cierre 1–15',
            'q2' => 'Cierre 16–fin de mes',
            default => $period,
        };
    }

    private function accountingPeriodLabel(?string $accountingMonth, string $payrollPeriod): ?string
    {
        if ($accountingMonth === null || $accountingMonth === '') {
            return null;
        }

        try {
            $monthName = Carbon::createFromFormat('Y-m', $accountingMonth)
                ->locale('es')
                ->translatedFormat('F Y');
        } catch (\Throwable) {
            $monthName = $accountingMonth;
        }

        return $monthName.' · '.$this->payrollPeriodLabel($payrollPeriod);
    }

    private function applyAccountingMonthFilter($query, int $year, int $month): void
    {
        $accountingMonth = sprintf('%04d-%02d', $year, $month);

        $query->where('accounting_month', $accountingMonth)
            ->orWhere(function ($legacy) use ($year, $month): void {
                $legacy->whereNull('accounting_month')
                    ->whereYear('date', $year)
                    ->whereMonth('date', $month);
            });
    }

    private function normalizePaymentMethod(string $method): string
    {
        $normalized = strtoupper(trim($method));

        return in_array($normalized, ['CASH', 'YAPE', 'CARD', 'TRANSFER'], true)
            ? $normalized
            : 'CASH';
    }

    /**
     * @return array{
     *   falta: int,
     *   faltaInjustificada: int,
     *   valdeo: int,
     *   recuperacion: int,
     *   faltasEquivalentes: int,
     *   faltasADescontar: int,
     *   descuentoPorFaltas: float,
     *   deudaEntradaTardeMinutos: int,
     *   deudaSalidaAnticipadaMinutos: int,
     *   deudaTiempoTotalMinutos: int,
     *   favorLlegadaTempranaTotalMinutos: int,
     *   favorSalidaTardeTotalMinutos: int,
     *   favorTiempoTotalMinutos: int,
     *   saldoTiempoNetoMinutos: int,
     *   saldoTiempoNetoSentido: 'favor'|'debe'|'cero',
     *   saldoTiempoNetoMagnitud: array{days: int, hours: int, minutes: int},
     *   deudaEntradaTarde: array{days: int, hours: int, minutes: int},
     *   deudaSalidaAnticipada: array{days: int, hours: int, minutes: int},
     *   deudaTiempo: array{days: int, hours: int, minutes: int},
     *   favorLlegadaTemprana: array{days: int, hours: int, minutes: int},
     *   favorSalidaTarde: array{days: int, hours: int, minutes: int},
     *   deudaPorDia: list<array{
     *     date: string,
     *     status: string,
     *     checkIn: string|null,
     *     checkOut: string|null,
     *     deudaEntradaTardeMinutos: int,
     *     deudaSalidaAnticipadaMinutos: int,
     *     favorLlegadaTempranaMinutos: int,
     *     favorSalidaTardeMinutos: int,
     *     saldoNetoMinutos: int,
     *     saldoNetoSentido: 'favor'|'debe'|'cero'
     *   }>
     * }
     */
    private function attendanceBreakdown(
        Collection $attendances,
        int $year,
        int $month,
        string $period,
        float $dailyRate,
    ): array {
        $lastDay = cal_days_in_month(CAL_GREGORIAN, $month, $year);

        $inPeriod = static function (int $day) use ($period, $lastDay): bool {
            return match ($period) {
                'q1' => $day >= 1 && $day <= 15,
                'q2' => $day >= 16 && $day <= $lastDay,
                default => true,
            };
        };

        $falta = 0;
        $faltaInjustificada = 0;
        $valdeo = 0;
        $recuperacion = 0;
        $sumEntradaTarde = 0;
        $sumSalidaAnticipada = 0;
        $sumFavorLlegada = 0;
        $sumFavorSalida = 0;
        /** @var list<array{date: string, status: string, checkIn: ?string, checkOut: ?string, deudaEntradaTardeMinutos: int, deudaSalidaAnticipadaMinutos: int, favorLlegadaTempranaMinutos: int, favorSalidaTardeMinutos: int, saldoNetoMinutos: int, saldoNetoSentido: string}> $deudaPorDia */
        $deudaPorDia = [];

        $diasConRetraso = 0;

        foreach ($attendances as $row) {
            /** @var Attendance $row */
            $carbon = Carbon::parse($row->date);
            if ((int) $carbon->format('n') !== $month || (int) $carbon->format('Y') !== $year) {
                continue;
            }
            $day = (int) $carbon->format('j');
            if (! $inPeriod($day)) {
                continue;
            }

            $status = (string) $row->status;
            if ($status === 'FALTA') {
                $falta++;
            } elseif ($status === 'FALTA_INJUSTIFICADA') {
                $faltaInjustificada++;
            } elseif ($status === 'VALDEO') {
                $valdeo++;
            } elseif ($status === 'RECUPERACION') {
                $recuperacion++;
            }

            $dateOnly = $carbon->copy()->startOfDay();
            $bal = $this->computeRowTimeBalance($row, $dateOnly, $status);
            if ($bal['deudaEntrada'] > 0) {
                $diasConRetraso++;
            }
            $sumEntradaTarde += $bal['deudaEntrada'];
            $sumSalidaAnticipada += $bal['deudaSalida'];
            $sumFavorLlegada += $bal['favorLlegada'];
            $sumFavorSalida += $bal['favorSalida'];

            $entryRaw = $row->check_in_time;
            $exitRaw = $row->check_out_time;
            $inFmt = $this->formatTimeHm($entryRaw);
            $outFmt = $this->formatTimeHm($exitRaw);

            if ($this->statusUsesShiftExit($status) && $inFmt !== null && $outFmt !== null) {
                $deudaPorDia[] = [
                    'date' => $dateOnly->format('Y-m-d'),
                    'status' => $status,
                    'checkIn' => $inFmt,
                    'checkOut' => $outFmt,
                    'deudaEntradaTardeMinutos' => $bal['deudaEntrada'],
                    'deudaSalidaAnticipadaMinutos' => $bal['deudaSalida'],
                    'favorLlegadaTempranaMinutos' => $bal['favorLlegada'],
                    'favorSalidaTardeMinutos' => $bal['favorSalida'],
                    'saldoNetoMinutos' => $bal['saldoNeto'],
                    'saldoNetoSentido' => $this->saldoSentido($bal['saldoNeto']),
                ];
            }
        }

        usort($deudaPorDia, static fn (array $a, array $b): int => strcmp($a['date'], $b['date']));

        $totalDeudaMin = $sumEntradaTarde + $sumSalidaAnticipada;
        // FALTA_INJUSTIFICADA cuenta doble (= 2 días descontados por cada ocurrencia).
        $faltasEquivalentes = $falta + ($faltaInjustificada * 2) + $valdeo;
        $faltasADescontar = max(0, $faltasEquivalentes - $recuperacion);
        $descuentoPorAusencias = round($faltasADescontar * $dailyRate, 2);
        // Siempre valor día = sueldo ÷ 30 y jornada nominal 690 min, también en vista quincenal
        // (solo cambia qué días entran en minutos/faltas; evita desbalance 15 vs 16 días).
        $descuentoPorTiempoNoCumplido = self::MINUTES_NOMINAL_SHIFT > 0
            ? round(($totalDeudaMin / self::MINUTES_NOMINAL_SHIFT) * $dailyRate, 2)
            : 0.0;
        $descuentoPorFaltas = round($descuentoPorAusencias + $descuentoPorTiempoNoCumplido, 2);
        $totalFavorMin = $sumFavorLlegada + $sumFavorSalida;
        $saldoNeto = $sumFavorLlegada + $sumFavorSalida - $sumEntradaTarde - $sumSalidaAnticipada;

        return [
            'falta' => $falta,
            'faltaInjustificada' => $faltaInjustificada,
            'valdeo' => $valdeo,
            'recuperacion' => $recuperacion,
            'faltasEquivalentes' => $faltasEquivalentes,
            'faltasADescontar' => $faltasADescontar,
            'descuentoPorAusencias' => $descuentoPorAusencias,
            'descuentoPorTiempoNoCumplido' => $descuentoPorTiempoNoCumplido,
            'descuentoPorFaltas' => $descuentoPorFaltas,
            'diasConRetraso' => $diasConRetraso,
            'deudaEntradaTardeMinutos' => $sumEntradaTarde,
            'deudaSalidaAnticipadaMinutos' => $sumSalidaAnticipada,
            'deudaTiempoTotalMinutos' => $totalDeudaMin,
            'favorLlegadaTempranaTotalMinutos' => $sumFavorLlegada,
            'favorSalidaTardeTotalMinutos' => $sumFavorSalida,
            'favorTiempoTotalMinutos' => $totalFavorMin,
            'saldoTiempoNetoMinutos' => $saldoNeto,
            'saldoTiempoNetoSentido' => $this->saldoSentido($saldoNeto),
            'saldoTiempoNetoMagnitud' => $this->splitMinutes(abs($saldoNeto)),
            'deudaEntradaTarde' => $this->splitMinutes($sumEntradaTarde),
            'deudaSalidaAnticipada' => $this->splitMinutes($sumSalidaAnticipada),
            'deudaTiempo' => $this->splitMinutes($totalDeudaMin),
            'favorLlegadaTemprana' => $this->splitMinutes($sumFavorLlegada),
            'favorSalidaTarde' => $this->splitMinutes($sumFavorSalida),
            'deudaPorDia' => $deudaPorDia,
        ];
    }

    /**
     * @return array{
     *   deudaEntrada: int,
     *   deudaSalida: int,
     *   favorLlegada: int,
     *   favorSalida: int,
     *   saldoNeto: int
     * }
     */
    private function computeRowTimeBalance(Attendance $row, Carbon $dateOnly, string $status): array
    {
        $deudaEntrada = 0;
        $deudaSalida = 0;
        $favorLlegada = 0;
        $favorSalida = 0;

        $entry = $this->combineDateAndTime($dateOnly, $row->check_in_time);
        $exit = $this->combineDateAndTime($dateOnly, $row->check_out_time);
        $limit8 = $dateOnly->copy()->setTime(8, 0, 0);

        if ($this->statusUsesEntryRules($status) && $entry !== null) {
            if ($status === 'TARDE') {
                if ($entry > $limit8) {
                    $deudaEntrada = $this->minutesLateAfter($limit8, $entry);
                }
            } else {
                $toleranceEnd = $limit8->copy()->addMinutes(self::ENTRY_TOLERANCE_MINUTES);
                if ($entry > $toleranceEnd) {
                    $deudaEntrada = $this->minutesLateAfter($toleranceEnd, $entry);
                }
            }
        }

        if ($this->statusCreditsEarlyArrival($status) && $entry !== null && $entry < $limit8) {
            $favorLlegada = $this->minutesLateAfter($entry, $limit8);
        }

        if ($this->statusUsesShiftExit($status) && $entry !== null && $exit !== null) {
            $officialEnd = $dateOnly->copy()->setTime(self::OFFICIAL_END_HOUR, self::OFFICIAL_END_MINUTE, 0);
            if ($exit < $officialEnd) {
                $deudaSalida = $this->minutesLateAfter($exit, $officialEnd);
            } elseif ($exit > $officialEnd) {
                $favorSalida = $this->minutesLateAfter($officialEnd, $exit);
            }
        }

        $saldoNeto = $favorLlegada + $favorSalida - $deudaEntrada - $deudaSalida;

        return [
            'deudaEntrada' => $deudaEntrada,
            'deudaSalida' => $deudaSalida,
            'favorLlegada' => $favorLlegada,
            'favorSalida' => $favorSalida,
            'saldoNeto' => $saldoNeto,
        ];
    }

    private function statusCreditsEarlyArrival(string $status): bool
    {
        return in_array($status, ['PUNTUAL', 'TARDE', 'TOLERANCIA', 'RECUPERACION'], true);
    }

    private function saldoSentido(int $saldoNeto): string
    {
        if ($saldoNeto > 0) {
            return 'favor';
        }
        if ($saldoNeto < 0) {
            return 'debe';
        }

        return 'cero';
    }

    private function statusUsesEntryRules(string $status): bool
    {
        return in_array($status, ['PUNTUAL', 'TARDE', 'TOLERANCIA'], true);
    }

    private function statusUsesShiftExit(string $status): bool
    {
        return in_array($status, ['PUNTUAL', 'TARDE', 'TOLERANCIA', 'RECUPERACION'], true);
    }

    private function combineDateAndTime(Carbon $dateOnly, mixed $raw): ?Carbon
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $t = Carbon::parse($raw);

        return $dateOnly->copy()->setTime((int) $t->format('H'), (int) $t->format('i'), (int) $t->format('s'));
    }

    private function formatTimeHm(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $t = Carbon::parse($raw);

        return $t->format('H:i');
    }

    /** Minutos entre $anchor y $actual cuando $actual es después de $anchor (retraso a la entrada o tiempo no cumplido antes de salir). */
    private function minutesLateAfter(Carbon $anchor, Carbon $actual): int
    {
        if ($actual <= $anchor) {
            return 0;
        }

        return (int) floor(($actual->getTimestamp() - $anchor->getTimestamp()) / 60);
    }

    /**
     * @return array{days: int, hours: int, minutes: int}
     */
    private function splitMinutes(int $total): array
    {
        $total = max(0, $total);
        $days = intdiv($total, 1440);
        $rem = $total % 1440;
        $hours = intdiv($rem, 60);
        $minutes = $rem % 60;

        return [
            'days' => $days,
            'hours' => $hours,
            'minutes' => $minutes,
        ];
    }

    private function periodLabel(string $period, int $lastDay): string
    {
        return match ($period) {
            'q1' => '1.ª quincena (días 1–15)',
            'q2' => sprintf('2.ª quincena (días 16–%d)', $lastDay),
            default => 'Mes completo',
        };
    }

    private function spanishLongDate(int $year, int $month, int $day): string
    {
        $meses = [
            1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
            5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
            9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
        ];

        return sprintf('%d de %s de %d', $day, $meses[$month] ?? (string) $month, $year);
    }
}
