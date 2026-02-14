<?php

namespace App\Directory\Team\Controllers;

use App\Directory\Team\Models\Attendance;
use App\Shared\Foundation\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    public function getByMonth(Request $request, $teamId): JsonResponse
    {
        $request->validate([
            'month' => 'required|integer|min:1|max:12',
            'year' => 'required|integer'
        ]);

        $data = Attendance::where('team_id', $teamId)
            ->whereMonth('date', $request->month)
            ->whereYear('date', $request->year)
            ->get()
            ->keyBy('date'); // Clave por fecha para fácil acceso en frontend

        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'team_id' => 'required|exists:teams,id',
            'date' => 'required|date',
            'status' => 'required|in:PUNTUAL,TARDE,FALTA,DESCANSO,VACACIONES',
            'check_in_time' => 'nullable|date_format:H:i',
            'delay_minutes' => 'nullable|integer',
            'notes' => 'nullable|string'
        ]);

        // Busca si ya existe registro ese día para ese empleado
        $attendance = Attendance::updateOrCreate(
            [
                'team_id' => $data['team_id'],
                'date' => $data['date']
            ],
            [
                'status' => $data['status'],
                'check_in_time' => $data['check_in_time'] ?? null,
                'delay_minutes' => $data['delay_minutes'] ?? 0,
                'notes' => $data['notes'] ?? null
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Asistencia registrada',
            'data' => $attendance
        ]);
    }
}
