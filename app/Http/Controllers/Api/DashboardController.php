<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\SchedulePresenter;
use App\Support\TaskPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    /**
     * Beranda: jadwal hari ini (termasuk override & kelas pengganti) + tugas prioritas.
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();

        $todaySchedules = SchedulePresenter::forToday($user);

        $allTasks = TaskPresenter::forUser($user);

        return response()->json([
            'today_date' => now()->translatedFormat('l, d F Y'),
            'today_schedules' => $todaySchedules,
            // Tugas aktif terdekat untuk kartu "Tugas" di beranda
            'priority_tasks' => $allTasks->where('is_completed', false)->take(5)->values(),
            'summary' => [
                'classes_today' => $todaySchedules->count(),
                'pending_tasks' => $allTasks->where('is_completed', false)->count(),
                'urgent_tasks' => $allTasks->where('is_completed', false)->where('urgency', 'urgent')->count(),
            ],
        ]);
    }
}
