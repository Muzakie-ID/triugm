<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\ReminderLog;
use App\Models\Schedule;
use App\Models\Subject;
use App\Models\User;
use App\Models\WahaGroupConfig;
use App\Services\WahaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminController extends Controller
{
    /**
     * Data panel admin: users, subjects, konfigurasi WAHA, log notifikasi terakhir.
     */
    public function index(Request $request): JsonResponse
    {
        $users = User::orderBy('role')
            ->orderBy('niu')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'niu' => $user->niu,
                'role' => $user->role,
                'theory_class' => $user->theory_class,
                'practicum_group' => $user->practicum_group,
                'has_pin' => $user->pin_hash !== null,
                'is_active' => (bool) $user->is_active,
            ]);

        $recentLogs = ReminderLog::orderByDesc('sent_at')->limit(20)->get()->map(fn (ReminderLog $log) => [
            'id' => $log->id,
            'event_type' => $log->event_type,
            'target_group' => $log->target_group,
            'sent_at' => optional($log->sent_at)?->translatedFormat('d M Y, H:i') ?? '-',
            'payload' => $log->payload_snapshot,
        ]);

        return response()->json([
            'users' => $users,
            'subjects' => Subject::withCount(['schedules', 'tasks'])->orderBy('name')->get(),
            'schedules' => Schedule::with('subject')->orderBy('day_of_week')->orderBy('start_time')->get()
                ->map(fn (Schedule $s) => [
                    'id' => $s->id,
                    'subject_id' => $s->subject_id,
                    'subject_name' => $s->subject->name ?? '-',
                    'subject_type' => $s->subject->type ?? 'THEORY',
                    'target_group' => $s->target_group,
                    'day_of_week' => $s->day_of_week,
                    'day_name' => $s->day_name,
                    'start_time' => substr($s->start_time, 0, 5),
                    'end_time' => substr($s->end_time, 0, 5),
                    'room' => $s->room,
                    'lecturer_name' => $s->lecturer_name,
                ]),
            'waha_configs' => WahaGroupConfig::orderBy('id')->get(['id', 'group_name', 'target_group', 'group_jid']),
            'waha_settings' => [
                'waha_base_url' => AppSetting::get('waha_base_url', 'http://localhost:3000'),
                'waha_session' => AppSetting::get('waha_session', 'default'),
                'waha_api_key' => AppSetting::get('waha_api_key'),
            ],
            'recent_logs' => $recentLogs,
        ]);
    }

    // ---------------- Mata kuliah ----------------

    public function storeSubject(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:20', 'unique:subjects,code'],
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', 'in:THEORY,PRACTICUM'],
        ]);

        $subject = Subject::create($validated);

        return response()->json([
            'message' => 'Mata pelajaran berhasil ditambahkan.',
            'subject' => $subject,
        ], 201);
    }

    public function updateSubject(Request $request, Subject $subject): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:20', 'unique:subjects,code,'.$subject->id],
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', 'in:THEORY,PRACTICUM'],
        ]);

        $subject->update($validated);

        return response()->json([
            'message' => 'Mata pelajaran berhasil diperbarui.',
            'subject' => $subject->fresh(),
        ]);
    }

    public function destroySubject(Request $request, Subject $subject): JsonResponse
    {
        if ($subject->schedules()->exists() || $subject->tasks()->exists()) {
            return response()->json([
                'message' => 'Tidak dapat dihapus: masih ada jadwal atau tugas yang memakai mata pelajaran ini.',
            ], 409);
        }

        $subject->delete();

        return response()->json(['message' => 'Mata pelajaran berhasil dihapus.']);
    }

    // ---------------- Mahasiswa ----------------

    public function storeUser(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'niu' => ['required', 'string', 'max:30', 'unique:users,niu'],
            'name' => ['required', 'string', 'max:100'],
            'role' => ['required', 'in:STUDENT,PJ,ADMIN'],
            'practicum_group' => ['required', 'in:B1,B2,A1,A2'],
            'theory_class' => ['required', 'in:BB,AA'],
        ]);

        $user = User::create($validated);

        return response()->json([
            'message' => "Mahasiswa {$user->name} berhasil ditambahkan.",
            'user' => $this->userPayload($user),
        ], 201);
    }

    public function updateUser(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'niu' => ['required', 'string', 'max:30', 'unique:users,niu,'.$user->id],
            'name' => ['required', 'string', 'max:100'],
            'role' => ['required', 'in:STUDENT,PJ,ADMIN'],
            'practicum_group' => ['required', 'in:B1,B2,A1,A2'],
            'theory_class' => ['required', 'in:BB,AA'],
        ]);

        $user->update($validated);

        return response()->json([
            'message' => "Data {$user->name} berhasil diperbarui.",
            'user' => $this->userPayload($user->fresh()),
        ]);
    }

    public function deleteUser(Request $request, User $user): JsonResponse
    {
        if ($user->id === Auth::id()) {
            return response()->json(['message' => 'Anda tidak dapat menghapus akun sendiri.'], 409);
        }

        $name = $user->name;
        $user->delete();

        return response()->json(['message' => "Mahasiswa {$name} berhasil dihapus."]);
    }

    /**
     * Reset PIN: kosongkan pin_hash + nonaktifkan akun → mahasiswa aktivasi ulang.
     */
    public function resetPin(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
        ]);

        $user = User::findOrFail($validated['user_id']);
        $user->update([
            'pin_hash' => null,
            'is_active' => false,
        ]);

        return response()->json([
            'message' => "PIN akun {$user->name} ({$user->niu}) berhasil direset.",
            'user' => $this->userPayload($user->fresh()),
        ]);
    }

    // ---------------- WAHA ----------------

    public function fetchWahaGroups(Request $request, WahaService $wahaService): JsonResponse
    {
        $validated = $request->validate([
            'waha_base_url' => ['required', 'url'],
            'waha_session' => ['nullable', 'string', 'max:100'],
            'waha_api_key' => ['nullable', 'string', 'max:255'],
        ]);

        $result = $wahaService->getAvailableGroupsWithParams(
            $validated['waha_base_url'],
            $validated['waha_session'] ?? null,
            $validated['waha_api_key'] ?? null,
        );

        return response()->json($result);
    }

    public function updateWahaSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'waha_base_url' => ['required', 'url'],
            'waha_session' => ['required', 'string', 'max:100'],
            'waha_api_key' => ['nullable', 'string', 'max:255'],
            'groups' => ['nullable', 'array'],
            'groups.*.id' => ['required', 'integer', 'exists:waha_group_configs,id'],
            'groups.*.group_jid' => ['nullable', 'string', 'max:255'],
        ]);

        foreach ($validated['groups'] ?? [] as $group) {
            WahaGroupConfig::whereKey($group['id'])->update([
                'group_jid' => $group['group_jid'] ?? null,
            ]);
        }

        AppSetting::set('waha_base_url', $validated['waha_base_url']);
        AppSetting::set('waha_session', $validated['waha_session']);
        AppSetting::set('waha_api_key', $validated['waha_api_key'] ?? null);

        return response()->json(['message' => 'Konfigurasi WAHA berhasil disimpan.']);
    }

    public function testBlast(Request $request, WahaService $wahaService): JsonResponse
    {
        $validated = $request->validate([
            'target_group' => ['required', 'in:BB_THEORY,AA_THEORY,B1_PRACTICUM,B2_PRACTICUM,A1_PRACTICUM,A2_PRACTICUM'],
            'message' => ['nullable', 'string', 'max:500'],
        ]);

        $text = $validated['message'] ?: "🔔 @everyone [TEST NOTIFIKASI PORTAL KELAS]\n\nSistem notifikasi pengingat kuliah dan tugas telah terhubung aktif.";
        $result = $wahaService->blastToTargetGroup($validated['target_group'], $text, 'TEST_BLAST', 0);

        return response()->json($result, ($result['success'] ?? false) ? 200 : 502);
    }

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'niu' => $user->niu,
            'role' => $user->role,
            'theory_class' => $user->theory_class,
            'practicum_group' => $user->practicum_group,
            'has_pin' => $user->pin_hash !== null,
            'is_active' => (bool) $user->is_active,
        ];
    }
}
