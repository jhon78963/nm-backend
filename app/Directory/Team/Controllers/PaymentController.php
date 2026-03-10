<?php

namespace App\Directory\Team\Controllers;

use App\Directory\Team\Models\Team;
use App\Directory\Team\Models\TeamPayment;
use App\Shared\Foundation\Controllers\Controller;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function getMonthlySummary(Request $request)
    {
        // Parámetros de fecha (por defecto el mes actual)
        $month = $request->query('month', date('m'));
        $year = $request->query('year', date('Y'));
        $teamId = $request->query('team_id');

        $teams = Team::where('is_deleted', false)
            // Si $teamId tiene un valor, Laravel agrega automáticamente este WHERE a la consulta SQL
            ->when($teamId, function ($query, $teamId) {
                return $query->where('id', $teamId);
            })
            ->with(['payments' => function ($query) use ($month, $year) {
                $query->whereMonth('date', $month)
                      ->whereYear('date', $year)
                      ->where('is_deleted', false);
            }])
            ->get()
            ->map(function ($team) {
                $advances = $team->payments->where('type', 'ADVANCE')->sum('amount');
                $deductions = $team->payments->where('type', 'DEDUCTION')->sum('amount');
                $payments = $team->payments->where('type', 'PAYMENT')->sum('amount');

                // Saldo a pagar = Salario base - Descuentos - Adelantos - Lo ya pagado
                $balance = $team->salary - $deductions - $advances - $payments;

                return [
                    'id' => $team->id,
                    'dni' => $team->dni,
                    'full_name' => "{$team->name} {$team->surname}",
                    'base_salary' => (float) $team->salary,
                    'advances' => (float) $advances,
                    'deductions' => (float) $deductions,
                    'paid' => (float) $payments,
                    'balance' => (float) $balance
                ];
            });

        return response()->json($teams);
    }

    public function storeMovement(Request $request)
    {
        $validated = $request->validate([
            'team_id' => 'required|exists:teams,id',
            'type' => 'required|in:PAYMENT,ADVANCE,DEDUCTION',
            'amount' => 'required|numeric|min:0.1',
            'date' => 'required|date',
            'description' => 'nullable|string'
        ]);

        $movement = TeamPayment::create([
            'team_id' => $validated['team_id'],
            'type' => $validated['type'],
            'amount' => $validated['amount'],
            'date' => $validated['date'],
            'description' => $validated['description'],
            // 'creator_user_id' => auth()->id() // Descomentar si usas autenticación
        ]);

        return response()->json(['message' => 'Movimiento registrado correctamente', 'data' => $movement]);
    }
}
