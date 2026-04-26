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
     *   tardanzaTotalMinutes: int,
     *   tardanza: array{days: int, hours: int, minutes: int}
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
        $tardanzaMinutes = 0;

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

            if ($status === 'TARDE' || $status === 'TOLERANCIA') {
                $tardanzaMinutes += (int) ($row->delay_minutes ?? 0);
            }
        }

        $faltasEquivalentes = $falta + $valdeo;
        $faltasADescontar = max(0, $faltasEquivalentes - $recuperacion);
        $descuentoPorFaltas = round($faltasADescontar * $dailyRate, 2);

        return [
            'falta' => $falta,
            'valdeo' => $valdeo,
            'recuperacion' => $recuperacion,
            'faltasEquivalentes' => $faltasEquivalentes,
            'faltasADescontar' => $faltasADescontar,
            'descuentoPorFaltas' => $descuentoPorFaltas,
            'tardanzaTotalMinutes' => $tardanzaMinutes,
            'tardanza' => $this->splitMinutes($tardanzaMinutes),
        ];
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
