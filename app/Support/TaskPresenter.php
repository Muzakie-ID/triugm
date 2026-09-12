<?php

namespace App\Support;

use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Menyusun payload tugas (dengan status checklist personal + tingkat urgensi).
 */
class TaskPresenter
{
    /**
     * Semua tugas yang boleh dilihat user, urut deadline terdekat.
     */
    public static function forUser(User $user, ?Carbon $now = null): Collection
    {
        $now = $now ?: Carbon::now();

        return Task::with(['subject', 'completions' => fn ($q) => $q->where('user_id', $user->id)])
            ->whereIn('target_group', $user->allowedTargets())
            ->orderBy('deadline')
            ->get()
            ->map(fn (Task $task) => self::format($task, $now));
    }

    /**
     * Payload tugas + daftar tugas aktif/selesai terpisah (dipakai halaman Tugas).
     *
     * @return array{tasks: Collection, pending: Collection, completed: Collection, summary: array}
     */
    public static function groupedForUser(User $user, ?Carbon $now = null): array
    {
        $tasks = self::forUser($user, $now);
        $pending = $tasks->where('is_completed', false)->values();
        $completed = $tasks->where('is_completed', true)->values();

        return [
            'tasks' => $tasks,
            'pending' => $pending,
            'completed' => $completed,
            'summary' => [
                'total' => $tasks->count(),
                'pending' => $pending->count(),
                'completed' => $completed->count(),
                'urgent' => $pending->where('urgency', 'urgent')->count(),
                'warning' => $pending->where('urgency', 'warning')->count(),
            ],
        ];
    }

    public static function format(Task $task, ?Carbon $now = null): array
    {
        $now = $now ?: Carbon::now();

        $completion = $task->completions->first();
        $isCompleted = $completion ? (bool) $completion->is_completed : false;

        $diffInHours = $now->diffInHours($task->deadline, false);
        $diffInDays = $now->diffInDays($task->deadline, false);

        $urgency = 'safe';
        if ($diffInHours <= 24) {
            $urgency = 'urgent';
        } elseif ($diffInDays <= 3) {
            $urgency = 'warning';
        }

        return [
            'id' => $task->id,
            'subject_id' => $task->subject_id,
            'subject_name' => $task->subject->name ?? '',
            'subject_code' => $task->subject->code ?? '',
            'target_group' => $task->target_group,
            'title' => $task->title,
            'description' => $task->description,
            'deadline' => $task->deadline->toISOString(),
            'deadline_formatted' => $task->deadline->translatedFormat('d M Y, H:i'),
            'deadline_human' => $task->deadline->diffForHumans(),
            'diff_in_hours' => $diffInHours,
            'is_overdue' => ! $isCompleted && $diffInHours < 0,
            'submission_url' => $task->submission_url,
            'submission_format' => $task->submission_format,
            'is_completed' => $isCompleted,
            'completed_at' => $completion?->completed_at?->translatedFormat('d M Y, H:i'),
            'urgency' => $urgency,
        ];
    }
}
