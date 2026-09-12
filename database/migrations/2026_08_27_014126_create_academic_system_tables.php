<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. SUBJECTS (MATA KULIAH)
        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20);
            $table->string('name', 100);
            $table->string('type', 20)->default('THEORY'); // THEORY, PRACTICUM
            $table->timestamps();
        });

        // 3. MASTER SCHEDULES
        Schema::create('schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subject_id')->constrained('subjects')->onDelete('cascade');
            $table->string('target_group', 30); // ALL_THEORY, B1_PRACTICUM, B2_PRACTICUM
            $table->unsignedTinyInteger('day_of_week'); // 1 (Senin) s/d 7 (Minggu)
            $table->time('start_time');
            $table->time('end_time');
            $table->string('room', 50);
            $table->text('meeting_url')->nullable();
            $table->string('lecturer_name', 100);
            $table->timestamps();

            $table->index(['day_of_week', 'start_time']);
            $table->index('target_group');
        });

        // 4. SCHEDULE OVERRIDES
        Schema::create('schedule_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained('schedules')->onDelete('cascade');
            $table->date('original_date');
            $table->string('status', 30)->default('RESCHEDULED'); // NORMAL, RESCHEDULED, MAKEUP_CLASS, CANCELLED, ONLINE
            $table->date('new_date')->nullable();
            $table->time('new_start_time')->nullable();
            $table->time('new_end_time')->nullable();
            $table->string('new_room', 50)->nullable();
            $table->text('meeting_url')->nullable();
            $table->string('meeting_passcode', 50)->nullable();
            $table->text('reason')->nullable();
            $table->boolean('is_notified')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['original_date', 'schedule_id']);
        });

        // 5. TASKS
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subject_id')->constrained('subjects')->onDelete('cascade');
            $table->string('target_group', 30); // ALL_THEORY, B1_PRACTICUM, B2_PRACTICUM
            $table->string('title', 150);
            $table->text('description')->nullable();
            $table->dateTime('deadline');
            $table->text('submission_url')->nullable();
            $table->string('submission_format', 50)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['deadline', 'target_group']);
        });

        // 6. USER TASK COMPLETIONS (PERSONAL CHECKLIST)
        Schema::create('user_task_completions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('task_id')->constrained('tasks')->onDelete('cascade');
            $table->boolean('is_completed')->default(true);
            $table->timestamp('completed_at')->useCurrent();

            $table->unique(['user_id', 'task_id']);
        });

        // 7. WAHA GROUP CONFIGS
        Schema::create('waha_group_configs', function (Blueprint $table) {
            $table->id();
            $table->string('target_group', 30)->unique(); // ALL_THEORY, B1_PRACTICUM, B2_PRACTICUM
            $table->string('group_jid', 100);
            $table->string('group_name', 100);
            $table->timestamps();
        });

        // 8. REMINDER LOGS
        Schema::create('reminder_logs', function (Blueprint $table) {
            $table->id();
            $table->string('event_type', 50); // H15_SCHEDULE, H1_TASK, EMERGENCY_ONLINE, RESCHEDULE
            $table->unsignedBigInteger('reference_id');
            $table->string('target_group', 30);
            $table->timestamp('sent_at')->useCurrent();
            $table->json('payload_snapshot')->nullable();

            $table->index(['event_type', 'reference_id', 'target_group']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reminder_logs');
        Schema::dropIfExists('waha_group_configs');
        Schema::dropIfExists('user_task_completions');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('schedule_overrides');
        Schema::dropIfExists('schedules');
        Schema::dropIfExists('subjects');
    }
};
