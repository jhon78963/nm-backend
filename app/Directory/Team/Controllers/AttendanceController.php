<?php

namespace App\Directory\Team\Controllers;

use App\Directory\Team\Models\Attendance;
use App\Directory\Team\Models\Team;
use App\Shared\Foundation\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    /**
     * Resumen de asistencia de todos los colaboradores para una fecha.
     */
    public function getDailySummary(Request $request): JsonResponse
    {
        $request->validate([
            'date' => 'required|date_format:Y-m-d',
        ]);

        $date = $request->input('date');

        $teams = Team::query()
            ->orderBy('name')
            ->orderBy('surname')
            ->with([
                'attendances' => static fn ($q) => $q->where('date', $date),
            ])
            ->get();

        $rows = $teams->map(static function (Team $team) use ($date) {
            $att = $team->attendances->first();

            return [
                'teamId' => $team->id,
                'name' => $team->name,
                'surname' => $team->surname,
                'date' => $date,
                'attendance' => $att ? [
                    'status' => $att->status,
                    'check_in_time' => $att->check_in_time
                        ? Carbon::parse($att->check_in_time)->format('H:i')
                        : null,
                    'check_out_time' => $att->check_out_time
                        ? Carbon::parse($att->check_out_time)->format('H:i')
                        : null,
                    'delay_minutes' => (int) ($att->delay_minutes ?? 0),
                    'notes' => $att->notes,
                ] : null,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $rows,
        ]);
    }

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
            ->keyBy(fn (Attendance $row) => $row->date->format('Y-m-d'));

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
            'status' => 'required|in:PUNTUAL,TARDE,FALTA,DESCANSO,VACACIONES,RECUPERACION,VALDEO,TOLERANCIA',
            'check_in_time' => 'nullable|date_format:H:i',
            'check_out_time' => 'nullable|date_format:H:i',
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
                'check_out_time' => $data['check_out_time'] ?? null,
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
