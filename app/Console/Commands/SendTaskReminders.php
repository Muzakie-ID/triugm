<?php

namespace App\Console\Commands;

use App\Models\ReminderLog;
use App\Models\Task;
use App\Services\WahaService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendTaskReminders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reminder:tasks {--force : Force send reminder ignoring duplicate log}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Kirim notifikasi blast H-1 tenggat waktu tugas kuliah/praktikum (pukul 19.00 WIB) via WAHA';

    /**
     * Execute the console command.
     */
    public function handle(WahaService $wahaService): int
    {
        $now = Carbon::now();
        $tomorrowEnd = $now->copy()->addHours(24);
        $today = Carbon::today();

        $this->info("Scanning upcoming tasks due within 24 hours (until {$tomorrowEnd->format('Y-m-d H:i')} WIB)...");

        // Ambil tugas yang deadline-nya antara sekarang sampai 24 jam ke depan
        $tasks = Task::with('subject')
            ->whereBetween('deadline', [$now, $tomorrowEnd])
            ->get();

        if ($tasks->isEmpty()) {
            $this->info('No tasks due within 24 hours.');
            return self::SUCCESS;
        }

        foreach ($tasks as $task) {
            // Cek apakah reminder H-1 untuk task ini sudah pernah dikirim hari ini
            if (!$this->option('force')) {
                $alreadySent = ReminderLog::where('event_type', 'H1_TASK')
                    ->where('reference_id', $task->id)
                    ->whereDate('sent_at', $today)
                    ->exists();

                if ($alreadySent) {
                    $this->info("H-1 reminder for task ID {$task->id} ({$task->title}) already sent today.");
                    continue;
                }
            }

            $message = $wahaService->formatH1TaskReminder($task);
            $result = $wahaService->blastToTargetGroup($task->target_group, $message, 'H1_TASK', $task->id);

            if ($result['success']) {
                $this->info("Successfully sent H-1 reminder for task: {$task->title} ({$task->target_group})");
            } else {
                $this->warn("Failed to send H-1 reminder for task: {$task->title} - " . ($result['error'] ?? ''));
            }
        }

        return self::SUCCESS;
    }
}
