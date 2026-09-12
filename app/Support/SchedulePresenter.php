<?php

namespace App\Support;

use App\Models\Schedule;
use App\Models\ScheduleOverride;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Menyusun payload jadwal (hari ini & mingguan) untuk konsumsi API.
 * Dipakai bersama oleh DashboardController dan ScheduleController.
 */
class SchedulePresenter
{
    /**
     * Jadwal hari ini: jadwal master hari ini + kelas pengganti yang jatuh hari ini,
     * masing-masing sudah ditempeli override bila ada.
     */
    public static function forToday(User $user, ?Carbon $today = null): Collection
    {
        $today = $today ?: Carbon::today();
        $dayOfWeek = $today->dayOfWeekIso;

        $masterSchedules = Schedule::with('subject')
            ->whereIn('target_group', $user->allowedTargets())
            ->where('day_of_week', $dayOfWeek)
            ->orderBy('start_time')
            ->get();

        $overrides = self::overridesBetween($today, $today);

        // Perubahan pertemuan hari ini → menempel ke kartu master
        $overridesBySchedule = $overrides
            ->filter(fn ($o) => $o->original_date && $o->original_date->isSameDay($today))
            ->keyBy('schedule_id');

        // Kelas pengganti yang jatuh hari ini → kartu jadwal tambahan
        $makeupOverrides = $overrides->filter(
            fn ($o) => $o->schedule && $o->new_date && $o->new_date->isSameDay($today)
                && ! ($o->original_date && $o->original_date->isSameDay($today))
        );

        $todaySchedules = $masterSchedules->map(
            fn (Schedule $schedule) => self::formatSchedule($schedule, $overridesBySchedule->get($schedule->id))
        );

        $makeupSchedules = $makeupOverrides->map(fn ($o) => self::formatMakeup($o));

        return $todaySchedules->merge($makeupSchedules)->sortBy('start_time')->values();
    }

    /**
     * Jadwal mingguan + kelas pengganti, dikelompokkan per hari (1=Senin ... 6=Sabtu).
     *
     * @return array{weekly: array<int, array>, makeup: array<int, array>, all: array<int, array>, current_day: int}
     */
    public static function forWeek(User $user, ?Carbon $now = null): array
    {
        $now = $now ?: Carbon::now();
        $startOfWeek = $now->copy()->startOfWeek();
        $endOfWeek = $now->copy()->endOfWeek();

        $schedules = Schedule::with('subject')
            ->whereIn('target_group', $user->allowedTargets())
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->get();

        $overrides = self::overridesBetween($startOfWeek, $endOfWeek);

        // Perubahan pertemuan minggu ini → menempel ke kartu master
        $overridesBySchedule = $overrides
            ->filter(fn ($o) => $o->original_date && $o->original_date->between($startOfWeek, $endOfWeek))
            ->keyBy('schedule_id');

        // Kelas pengganti yang jatuh minggu ini → kartu tambahan di hari tujuan
        $makeupOverrides = $overrides->filter(
            fn ($o) => $o->new_date && $o->new_date->between($startOfWeek, $endOfWeek)
        );

        $weekly = [];
        $makeup = [];
        for ($day = 1; $day <= 6; $day++) {
            $weekly[$day] = [];
            $makeup[$day] = [];
        }

        foreach ($schedules as $schedule) {
            if ($schedule->day_of_week < 1 || $schedule->day_of_week > 6) {
                continue;
            }

            $weekly[$schedule->day_of_week][] = self::formatSchedule(
                $schedule,
                $overridesBySchedule->get($schedule->id),
                withDay: true,
            );
        }

        foreach ($makeupOverrides as $o) {
            $schedule = $o->schedule;
            if (! $schedule) {
                continue;
            }

            $day = $o->new_date->dayOfWeekIso;
            if ($day < 1 || $day > 6) {
                continue;
            }

            $makeup[$day][] = self::formatMakeup($o, withDay: true);
        }

        foreach ($makeup as $day => $items) {
            usort($items, fn ($a, $b) => strcmp($a['start_time'], $b['start_time']));
            $makeup[$day] = $items;
        }

        return [
            'weekly' => $weekly,
            'makeup' => $makeup,
            'all' => $schedules->map(fn (Schedule $s) => [
                'id' => $s->id,
                'subject_id' => $s->subject_id,
                'subject_name' => $s->subject->name ?? '',
                'subject_code' => $s->subject->code ?? '',
                'target_group' => $s->target_group,
                'day_of_week' => $s->day_of_week,
                'day_name' => $s->day_name,
                'time_range' => substr($s->start_time, 0, 5).' - '.substr($s->end_time, 0, 5),
            ])->values(),
            'current_day' => $now->dayOfWeekIso,
            'week_start' => $startOfWeek->format('Y-m-d'),
            'week_end' => $endOfWeek->format('Y-m-d'),
        ];
    }

    /**
     * Override yang relevan pada rentang tanggal (original_date ATAU new_date masuk rentang).
     */
    public static function overridesBetween(Carbon $from, Carbon $to): Collection
    {
        return ScheduleOverride::with('schedule.subject')
            ->where(function ($q) use ($from, $to) {
                $q->whereBetween('original_date', [$from->toDateString(), $to->toDateString()])
                    ->orWhereBetween('new_date', [$from->toDateString(), $to->toDateString()]);
            })
            ->get();
    }

    /**
     * Satu baris jadwal master, sudah ditempeli override bila ada.
     */
    public static function formatSchedule(Schedule $schedule, ?ScheduleOverride $override = null, bool $withDay = false): array
    {
        $status = $override ? $override->status : 'NORMAL';
        $startTime = substr($schedule->start_time, 0, 5);
        $endTime = substr($schedule->end_time, 0, 5);
        $room = $schedule->room;
        $meetingUrl = $schedule->meeting_url;
        $meetingPasscode = null;
        $reason = null;

        if ($override) {
            $meetingUrl = $override->meeting_url;
            $meetingPasscode = $override->meeting_passcode;
            $reason = $override->reason;

            if ($override->new_start_time) {
                $startTime = substr($override->new_start_time, 0, 5);
            }
            if ($override->new_end_time) {
                $endTime = substr($override->new_end_time, 0, 5);
            }
            if ($override->new_room) {
                $room = $override->new_room;
            }
        }

        return [
            'id' => $schedule->id,
            'is_makeup' => false,
            'subject_id' => $schedule->subject_id,
            'subject_name' => $schedule->subject->name ?? 'Mata Kuliah',
            'subject_code' => $schedule->subject->code ?? '',
            'type' => $schedule->subject->type ?? 'THEORY',
            'target_group' => $schedule->target_group,
            ...($withDay ? ['day_of_week' => $schedule->day_of_week, 'day_name' => $schedule->day_name] : []),
            'start_time' => $startTime,
            'end_time' => $endTime,
            'original_start_time' => substr($schedule->start_time, 0, 5),
            'original_end_time' => substr($schedule->end_time, 0, 5),
            'room' => $room,
            'original_room' => $schedule->room,
            'lecturer_name' => $schedule->lecturer_name,
            'description' => $schedule->description,
            'status' => $status,
            'meeting_url' => $meetingUrl,
            'meeting_passcode' => $meetingPasscode,
            'reason' => $reason,
            'override' => $override ? self::formatOverride($override) : null,
        ];
    }

    /**
     * Kartu kelas pengganti (new_date ≠ original_date) untuk tanggal tujuan.
     */
    public static function formatMakeup(ScheduleOverride $o, bool $withDay = false): array
    {
        $schedule = $o->schedule;

        return [
            'id' => $schedule->id,
            'is_makeup' => true,
            'subject_id' => $schedule->subject_id,
            'subject_name' => $schedule->subject->name ?? 'Mata Kuliah',
            'subject_code' => $schedule->subject->code ?? '',
            'type' => $schedule->subject->type ?? 'THEORY',
            'target_group' => $schedule->target_group,
            ...($withDay ? ['day_of_week' => $schedule->day_of_week, 'day_name' => $schedule->day_name] : []),
            'start_time' => $o->new_start_time ? substr($o->new_start_time, 0, 5) : substr($schedule->start_time, 0, 5),
            'end_time' => $o->new_end_time ? substr($o->new_end_time, 0, 5) : substr($schedule->end_time, 0, 5),
            'original_start_time' => substr($schedule->start_time, 0, 5),
            'original_end_time' => substr($schedule->end_time, 0, 5),
            'room' => $o->new_room ?? $schedule->room,
            'original_room' => $schedule->room,
            'lecturer_name' => $schedule->lecturer_name,
            'description' => $schedule->description,
            'status' => $o->status,
            'meeting_url' => $o->meeting_url,
            'meeting_passcode' => $o->meeting_passcode,
            'reason' => $o->reason,
            'override' => self::formatOverride($o),
        ];
    }

    public static function formatOverride(ScheduleOverride $o): array
    {
        return [
            'id' => $o->id,
            'schedule_id' => $o->schedule_id,
            'status' => $o->status,
            'original_date' => $o->original_date?->format('Y-m-d'),
            'new_date' => $o->new_date?->format('Y-m-d'),
            'new_start_time' => $o->new_start_time ? substr($o->new_start_time, 0, 5) : null,
            'new_end_time' => $o->new_end_time ? substr($o->new_end_time, 0, 5) : null,
            'new_room' => $o->new_room,
            'meeting_url' => $o->meeting_url,
            'meeting_passcode' => $o->meeting_passcode,
            'reason' => $o->reason,
            'is_notified' => (bool) $o->is_notified,
        ];
    }
}
