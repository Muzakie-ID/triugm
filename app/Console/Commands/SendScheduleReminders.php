<?php

namespace App\Console\Commands;

use App\Models\ReminderLog;
use App\Models\Schedule;
use App\Models\ScheduleOverride;
use App\Services\WahaService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendScheduleReminders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reminder:schedule {--force : Force send reminder ignoring duplicate log}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Kirim notifikasi pengingat H-15 menit sebelum kuliah/praktikum dimulai via WAHA';

    /**
     * Execute the console command.
     */
    public function handle(WahaService $wahaService): int
    {
        $now = Carbon::now();
        $targetTime = $now->copy()->addMinutes(15);
        $targetTimeFormatted = $targetTime->format('H:i');
        $today = Carbon::today();
        $dayOfWeek = $today->dayOfWeekIso;

        $this->info("Checking schedules for day: {$dayOfWeek} around {$targetTimeFormatted} WIB...");

        // Ambil jadwal master yang mulai pada H-15
        $schedules = Schedule::with('subject')
            ->where('day_of_week', $dayOfWeek)
            ->where('start_time', 'like', $targetTimeFormatted.'%')
            ->get();

        if ($schedules->isEmpty()) {
            $this->info('No schedules starting in 15 minutes.');

            return self::SUCCESS;
        }

        foreach ($schedules as $schedule) {
            // Cek override hari ini
            $override = ScheduleOverride::where('schedule_id', $schedule->id)
                ->whereDate('original_date', $today)
                ->first();

            // Jika dibatalkan, lewati
            if ($override && $override->status === 'CANCELLED') {
                $this->info("Schedule ID {$schedule->id} is cancelled. Skipping.");

                continue;
            }

            // Cek log duplikasi pengiriman hari ini
            if (! $this->option('force')) {
                $alreadySent = ReminderLog::where('event_type', 'H15_SCHEDULE')
                    ->where('reference_id', $schedule->id)
                    ->whereDate('sent_at', $today)
                    ->exists();

                if ($alreadySent) {
                    $this->info("Reminder for schedule ID {$schedule->id} has already been sent today.");

                    continue;
                }
            }

            $message = $wahaService->formatH15ScheduleReminder($schedule, $override);
            $result = $wahaService->blastToTargetGroup($schedule->target_group, $message, 'H15_SCHEDULE', $schedule->id);

            if ($result['success']) {
                $this->info("Successfully sent H-15 reminder for: {$schedule->subject->name} ({$schedule->target_group})");
            } else {
                $this->warn("Failed to send H-15 reminder for: {$schedule->subject->name} - ".($result['error'] ?? ''));
            }
        }

        return self::SUCCESS;
    }
}
