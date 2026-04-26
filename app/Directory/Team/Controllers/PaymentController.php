<?php

namespace App\Directory\Team\Controllers;

use App\Directory\Team\Models\Attendance;
use App\Directory\Team\Models\Team;
use App\Directory\Team\Models\TeamPayment;
use App\Shared\Foundation\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class PaymentController extends Controller
{
    /** Misma jornada que el front de asistencia (entrada + 11 h 30). */
    private const SHIFT_DURATION_MINUTES = 11 * 60 + 30;
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
     * Nómina / vista de pagos: asistencia (faltas + valdeo − recuperación), tardanzas,
     * movimientos del mes y estimado a pagar a fin de mes.
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

        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
        $salary = round((float) $team->salary, 2);
        $dailyRate = $daysInMonth > 0 ? round($salary / $daysInMonth, 4) : 0.0;

        $attendances = Attendance::query()
            ->where('team_id', $teamId)
            ->whereYear('date', $year)
            ->whereMonth('date', $month)
            ->get();

        $payments = TeamPayment::query()
            ->where('team_id', $teamId)
            ->where('is_deleted', false)
            ->whereYear('date', $year)
            ->whereMonth('date', $month)
            ->get();

        $scopeVista = $this->attendanceBreakdown($attendances, $year, $month, $period, $dailyRate);
        $scopeMes = $this->attendanceBreakdown($attendances, $year, $month, 'full', $dailyRate);

        $movMes = $this->paymentSumsForRange($payments, 1, $daysInMonth);
        $movQ1 = $this->paymentSumsForRange($payments, 1, 15);
        $movQ2 = $this->paymentSumsForRange($payments, 16, $daysInMonth);
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
                    'daysInMonth' => $daysInMonth,
                    'period' => $period,
                    'periodLabel' => $this->periodLabel($period, $daysInMonth),
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
                'estimates' => [
                    'salarioBase' => $salary,
                    'descuentoAsistenciaMesCompleto' => $descuentoAsistenciaMes,
                    'salarioTrasDescuentoFaltas' => $trasFaltas,
                    'estimadoAPagarFinMes' => $estimadoFinMes,
                    'nota' => 'El estimado a fin de mes usa faltas y valdeos del mes completo, más adelantos, pagos quincenales (tipo PAGO) y descuentos manuales registrados en el mes.',
                ],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'team_id' => 'required|exists:teams,id',
            'type' => 'required|in:PAYMENT,ADVANCE,DEDUCTION',
            'amount' => 'required|numeric|min:0.1',
            'date' => 'required|date',
            'description' => 'nullable|string',
        ]);

        $movement = TeamPayment::create([
            'team_id' => $validated['team_id'],
            'type' => $validated['type'],
            'amount' => $validated['amount'],
            'date' => $validated['date'],
            'description' => $validated['description'] ?? null,
        ]);

        return response()->json([
            'message' => 'Movimiento registrado correctamente',
            'data' => $movement,
        ]);
    }

    /**
     * @return array{ADVANCE: float, PAYMENT: float, DEDUCTION: float}
     */
    private function paymentSumsForRange(Collection $payments, int $dayStart, int $dayEnd): array
    {
        $filtered = $payments->filter(static function (TeamPayment $p) use ($dayStart, $dayEnd): bool {
            $day = (int) Carbon::parse($p->date)->format('j');

            return $day >= $dayStart && $day <= $dayEnd;
        });

        return [
            'ADVANCE' => round((float) $filtered->where('type', 'ADVANCE')->sum('amount'), 2),
            'PAYMENT' => round((float) $filtered->where('type', 'PAYMENT')->sum('amount'), 2),
            'DEDUCTION' => round((float) $filtered->where('type', 'DEDUCTION')->sum('amount'), 2),
        ];
    }

    /**
     * @return array{
     *   falta: int,
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
        $valdeo = 0;
        $recuperacion = 0;
        $sumEntradaTarde = 0;
        $sumSalidaAnticipada = 0;
        $sumFavorLlegada = 0;
        $sumFavorSalida = 0;
        /** @var list<array{date: string, status: string, checkIn: ?string, checkOut: ?string, deudaEntradaTardeMinutos: int, deudaSalidaAnticipadaMinutos: int, favorLlegadaTempranaMinutos: int, favorSalidaTardeMinutos: int, saldoNetoMinutos: int, saldoNetoSentido: string}> $deudaPorDia */
        $deudaPorDia = [];

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
            } elseif ($status === 'VALDEO') {
                $valdeo++;
            } elseif ($status === 'RECUPERACION') {
                $recuperacion++;
            }

            $dateOnly = $carbon->copy()->startOfDay();
            $bal = $this->computeRowTimeBalance($row, $dateOnly, $status);
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

        $faltasEquivalentes = $falta + $valdeo;
        $faltasADescontar = max(0, $faltasEquivalentes - $recuperacion);
        $descuentoPorFaltas = round($faltasADescontar * $dailyRate, 2);
        $totalDeudaMin = $sumEntradaTarde + $sumSalidaAnticipada;
        $totalFavorMin = $sumFavorLlegada + $sumFavorSalida;
        $saldoNeto = $sumFavorLlegada + $sumFavorSalida - $sumEntradaTarde - $sumSalidaAnticipada;

        return [
            'falta' => $falta,
            'valdeo' => $valdeo,
            'recuperacion' => $recuperacion,
            'faltasEquivalentes' => $faltasEquivalentes,
            'faltasADescontar' => $faltasADescontar,
            'descuentoPorFaltas' => $descuentoPorFaltas,
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
            $deudaEntrada = $this->minutesLateAfter($limit8, $entry);
        }

        if ($this->statusCreditsEarlyArrival($status) && $entry !== null && $entry < $limit8) {
            $favorLlegada = $this->minutesLateAfter($entry, $limit8);
        }

        if ($this->statusUsesShiftExit($status) && $entry !== null && $exit !== null) {
            $targetExit = $entry->copy()->addMinutes(self::SHIFT_DURATION_MINUTES);
            if ($exit < $targetExit) {
                $deudaSalida = $this->minutesLateAfter($exit, $targetExit);
            } elseif ($exit > $targetExit) {
                $favorSalida = $this->minutesLateAfter($targetExit, $exit);
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
}
