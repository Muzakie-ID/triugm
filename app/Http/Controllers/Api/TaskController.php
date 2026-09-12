<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Subject;
use App\Models\Task;
use App\Models\UserTaskCompletion;
use App\Services\WahaService;
use App\Support\TaskPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class TaskController extends Controller
{
    /**
     * Daftar tugas (aktif & selesai) untuk user yang login.
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();

        return response()->json([
            ...TaskPresenter::groupedForUser($user),
            'manageable_subjects' => $user->manageableSubjects(),
        ]);
    }

    /**
     * PJ/Admin: tambah tugas (opsional blast WhatsApp).
     */
    public function store(Request $request, WahaService $wahaService): JsonResponse
    {
        $user = Auth::user();

        $validated = $request->validate([
            'subject_id' => ['required', 'exists:subjects,id'],
            'target_group' => ['required', 'in:BB_THEORY,AA_THEORY,B1_PRACTICUM,B2_PRACTICUM,A1_PRACTICUM,A2_PRACTICUM'],
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'deadline' => ['required', 'date'],
            'submission_url' => ['nullable', 'url', 'max:500'],
            'submission_format' => ['nullable', 'string', 'max:50'],
            'send_waha_blast' => ['nullable', 'boolean'],
        ]);

        // PJ Kelas: tugas boleh untuk mata kuliah mana pun, tetapi hanya
        // ditujukan ke kelas turunan kelasnya sendiri (teori miliknya /
        // kloter praktikum miliknya).
        if (! $user->canManageTarget($validated['target_group'])) {
            return response()->json([
                'message' => 'Tugas hanya boleh ditujukan ke kelas teori atau kelas praktikum milik kelasmu sendiri.',
            ], 403);
        }

        // target_group harus sesuai jenis mata kuliah:
        // teori → BB_THEORY/AA_THEORY (turunan kelas teori), praktikum → kloter (B1..A2)_PRACTICUM.
        $subject = Subject::find($validated['subject_id']);
        $isPracticum = str_ends_with($validated['target_group'], '_PRACTICUM');

        if ($subject && $subject->type === 'PRACTICUM' && ! $isPracticum) {
            throw ValidationException::withMessages([
                'target_group' => 'Tugas mata kuliah praktikum hanya boleh ditujukan ke kelas praktikum (B1/B2/A1/A2).',
            ]);
        }

        if ($subject && $subject->type !== 'PRACTICUM' && $isPracticum) {
            throw ValidationException::withMessages([
                'target_group' => 'Tugas mata kuliah teori hanya boleh ditujukan ke kelas teori (BB/AA).',
            ]);
        }

        $task = Task::create([
            'subject_id' => $validated['subject_id'],
            'target_group' => $validated['target_group'],
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'deadline' => $validated['deadline'],
            'submission_url' => $validated['submission_url'] ?? null,
            'submission_format' => $validated['submission_format'] ?? null,
            'created_by' => $user->id,
        ]);

        $blastSent = false;
        if (! empty($validated['send_waha_blast'])) {
            $msg = $wahaService->formatH1TaskReminder($task);
            $result = $wahaService->blastToTargetGroup($task->target_group, $msg, 'H1_TASK', $task->id);
            $blastSent = (bool) ($result['success'] ?? false);
        }

        return response()->json([
            'message' => 'Tugas berhasil ditambahkan'.($blastSent ? ' dan notifikasi WhatsApp terkirim!' : '.'),
            'task' => TaskPresenter::format($task->load('subject', 'completions')),
            'waha_blast_sent' => $blastSent,
        ], 201);
    }

    /**
     * Checklist personal: tandai tugas selesai / kembalikan ke belum selesai.
     */
    public function toggleComplete(Request $request, Task $task): JsonResponse
    {
        $user = Auth::user();

        if (! in_array($task->target_group, $user->allowedTargets(), true)) {
            return response()->json(['message' => 'Anda tidak berhak atas tugas ini.'], 403);
        }

        $completion = UserTaskCompletion::where('user_id', $user->id)
            ->where('task_id', $task->id)
            ->first();

        if ($completion) {
            $completion->update([
                'is_completed' => ! $completion->is_completed,
                'completed_at' => ! $completion->is_completed ? now() : null,
            ]);
        } else {
            $completion = UserTaskCompletion::create([
                'user_id' => $user->id,
                'task_id' => $task->id,
                'is_completed' => true,
                'completed_at' => now(),
            ]);
        }

        $isCompleted = (bool) $completion->fresh()->is_completed;

        return response()->json([
            'message' => 'Tugas berhasil '.($isCompleted ? 'ditandai selesai' : 'dikembalikan ke belum selesai').'.',
            'is_completed' => $isCompleted,
            'task' => TaskPresenter::format(
                $task->load(['subject', 'completions' => fn ($q) => $q->where('user_id', $user->id)])
            ),
        ]);
    }
}
