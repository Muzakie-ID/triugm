<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Schedule;
use App\Models\ScheduleOverride;
use App\Models\Subject;
use App\Services\WahaService;
use App\Support\SchedulePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class ScheduleController extends Controller
{
    /**
     * Jadwal mingguan + kelas pengganti minggu ini.
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();

        return response()->json([
            ...SchedulePresenter::forWeek($user),
            'manageable_subjects' => $user->manageableSubjects(),
        ]);
    }

    /**
     * PJ/Admin: override jadwal per tanggal (daring / reschedule / pengganti / batal).
     */
    public function storeOverride(Request $request, WahaService $wahaService): JsonResponse
    {
        $user = Auth::user();

        $validated = $request->validate([
            'schedule_id' => ['required', 'exists:schedules,id'],
            'original_date' => ['required', 'date'],
            'status' => ['required', 'in:NORMAL,RESCHEDULED,MAKEUP_CLASS,CANCELLED,ONLINE'],
            'new_date' => ['nullable', 'date'],
            'new_start_time' => ['nullable', 'date_format:H:i'],
            'new_end_time' => ['nullable', 'date_format:H:i'],
            'new_room' => ['nullable', 'string', 'max:50'],
            'meeting_url' => ['nullable', 'string', 'max:500'],
            'meeting_passcode' => ['nullable', 'string', 'max:50'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'send_waha_blast' => ['nullable', 'boolean'],
        ]);

        $schedule = Schedule::with('subject')->findOrFail($validated['schedule_id']);

        // PJ Kelas hanya boleh meng-override jadwal kelasnya sendiri (matkul bebas).
        if (! $user->canManageTarget($schedule->target_group)) {
            return response()->json([
                'message' => 'Anda hanya dapat mengubah jadwal untuk kelas teori atau kelas praktikum milik kelasmu sendiri.',
            ], 403);
        }

        // Pindah hari / kelas pengganti wajib punya tanggal tujuan.
        if (in_array($validated['status'], ['RESCHEDULED', 'MAKEUP_CLASS'], true) && empty($validated['new_date'])) {
            throw ValidationException::withMessages(['new_date' => 'Tanggal pengganti wajib diisi untuk perubahan ini.']);
        }

        $override = ScheduleOverride::updateOrCreate(
            [
                'schedule_id' => $schedule->id,
                'original_date' => $validated['original_date'],
            ],
            [
                'status' => $validated['status'],
                'new_date' => $validated['new_date'] ?? null,
                'new_start_time' => $validated['new_start_time'] ?? null,
                'new_end_time' => $validated['new_end_time'] ?? null,
                'new_room' => $validated['new_room'] ?? null,
                'meeting_url' => $validated['meeting_url'] ?? null,
                'meeting_passcode' => $validated['meeting_passcode'] ?? null,
                'reason' => $validated['reason'] ?? null,
                'created_by' => $user->id,
            ]
        );

        $blastSent = false;
        if (! empty($validated['send_waha_blast'])) {
            if ($override->status === 'ONLINE') {
                $msg = $wahaService->formatEmergencyOnlineSwitch($schedule, $override);
                $result = $wahaService->blastToTargetGroup($schedule->target_group, $msg, 'EMERGENCY_ONLINE', $override->id);
                $blastSent = (bool) ($result['success'] ?? false);
            } elseif (in_array($override->status, ['RESCHEDULED', 'MAKEUP_CLASS', 'CANCELLED'], true)) {
                $msg = $wahaService->formatRescheduleNotice($schedule, $override);
                $result = $wahaService->blastToTargetGroup($schedule->target_group, $msg, 'RESCHEDULE', $override->id);
                $blastSent = (bool) ($result['success'] ?? false);
            }

            if ($blastSent) {
                $override->update(['is_notified' => true]);
            }
        }

        return response()->json([
            'message' => 'Status jadwal berhasil diperbarui'.($blastSent ? ' dan blast WhatsApp telah dikirim!' : '.'),
            'override' => SchedulePresenter::formatOverride($override->fresh()),
            'waha_blast_sent' => $blastSent,
        ]);
    }

    /**
     * PJ/Admin: tambah jadwal master.
     */
    public function store(Request $request): JsonResponse
    {
        $user = Auth::user();
        $validated = $this->validateSchedule($request);

        // PJ Kelas: matkul bebas, tetapi hanya untuk kelasnya sendiri.
        if (! $user->canManageTarget($validated['target_group'])) {
            return response()->json(['message' => 'Jadwal hanya boleh dibuat untuk kelas teori atau kelas praktikum milik kelasmu sendiri.'], 403);
        }

        if ($error = $this->overlapError($validated)) {
            throw ValidationException::withMessages(['start_time' => $error]);
        }

        $schedule = Schedule::create($validated);

        return response()->json([
            'message' => 'Jadwal berhasil ditambahkan.',
            'schedule' => SchedulePresenter::formatSchedule($schedule->load('subject'), null, true),
        ], 201);
    }

    /**
     * PJ/Admin: ubah jadwal master.
     */
    public function update(Request $request, Schedule $schedule): JsonResponse
    {
        $user = Auth::user();

        // PJ Kelas: matkul bebas, tetapi hanya untuk kelasnya sendiri.
        if (! $user->canManageTarget($schedule->target_group)) {
            return response()->json(['message' => 'Anda hanya dapat mengubah jadwal kelas teori atau kelas praktikum milik kelasmu sendiri.'], 403);
        }

        $validated = $this->validateSchedule($request);

        if ($error = $this->overlapError($validated, $schedule->id)) {
            throw ValidationException::withMessages(['start_time' => $error]);
        }

        $schedule->update($validated);

        return response()->json([
            'message' => 'Jadwal berhasil diperbarui.',
            'schedule' => SchedulePresenter::formatSchedule($schedule->fresh('subject'), null, true),
        ]);
    }

    /**
     * PJ/Admin: hapus jadwal master.
     */
    public function destroy(Request $request, Schedule $schedule): JsonResponse
    {
        $user = Auth::user();

        // PJ Kelas: matkul bebas, tetapi hanya untuk kelasnya sendiri.
        if (! $user->canManageTarget($schedule->target_group)) {
            return response()->json(['message' => 'Anda hanya dapat menghapus jadwal kelas teori atau kelas praktikum milik kelasmu sendiri.'], 403);
        }

        $schedule->delete();

        return response()->json(['message' => 'Jadwal berhasil dihapus.']);
    }

    /**
     * Tolak jika ada jadwal lain di kelas (target_group) & hari yang sama dengan jam tumpang tindih.
     */
    private function overlapError(array $data, ?int $ignoreId = null): ?string
    {
        $overlap = Schedule::where('target_group', $data['target_group'])
            ->where('day_of_week', $data['day_of_week'])
            ->where('start_time', '<', $data['end_time'])
            ->where('end_time', '>', $data['start_time'])
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->first();

        if (! $overlap) {
            return null;
        }

        $dayNames = [1 => 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];

        return sprintf(
            'Bentrok dengan jadwal %s (%s, %s-%s, %s).',
            $overlap->subject->name ?? 'mata kuliah lain',
            $dayNames[$data['day_of_week']] ?? '-',
            substr($overlap->start_time, 0, 5),
            substr($overlap->end_time, 0, 5),
            $overlap->room,
        );
    }

    private function validateSchedule(Request $request): array
    {
        $data = $request->validate([
            'subject_id' => ['required', 'exists:subjects,id'],
            'target_group' => ['required', 'in:BB_THEORY,AA_THEORY,B1_PRACTICUM,B2_PRACTICUM,A1_PRACTICUM,A2_PRACTICUM'],
            'day_of_week' => ['required', 'integer', 'min:1', 'max:7'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'room' => ['required', 'string', 'max:50'],
            'lecturer_name' => ['required', 'string', 'max:100'],
            'meeting_url' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        // target_group harus sesuai jenis mata kuliah:
        // teori → BB_THEORY/AA_THEORY, praktikum → kloter (B1..A2)_PRACTICUM.
        $subject = Subject::find($data['subject_id']);
        $isPracticum = str_ends_with($data['target_group'], '_PRACTICUM');

        if ($subject && $subject->type === 'PRACTICUM' && ! $isPracticum) {
            throw ValidationException::withMessages([
                'target_group' => 'Mata kuliah praktikum hanya boleh dijadwalkan di kelas praktikum (B1/B2/A1/A2).',
            ]);
        }

        if ($subject && $subject->type !== 'PRACTICUM' && $isPracticum) {
            throw ValidationException::withMessages([
                'target_group' => 'Mata kuliah teori hanya boleh dijadwalkan di kelas teori (BB/AA).',
            ]);
        }

        return $data;
    }
}
