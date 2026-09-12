<?php

namespace Tests\Feature;

use App\Models\Subject;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TaskTest extends TestCase
{
    use RefreshDatabase;

    private function student(string $niu = '11111', string $group = 'B1'): User
    {
        return User::create([
            'niu' => $niu,
            'name' => 'Student ' . $niu,
            'pin_hash' => Hash::make('123456'),
            'role' => 'STUDENT',
            'theory_class' => 'BB',
            'practicum_group' => $group,
            'is_active' => true,
        ]);
    }

    private function task(array $overrides = []): Task
    {
        $subject = Subject::create([
            'code' => 'TRI' . random_int(100, 999),
            'name' => 'Pemrograman Web Lanjut',
            'type' => 'THEORY',
        ]);

        return Task::create(array_merge([
            'subject_id' => $subject->id,
            'target_group' => 'BB_THEORY',
            'title' => 'Tugas 1',
            'deadline' => now()->addDays(2),
        ], $overrides));
    }

    public function test_tasks_payload_is_grouped_with_summary(): void
    {
        $user = $this->student();
        $this->task();

        $this->actingAs($user)
            ->getJson('/api/tasks')
            ->assertOk()
            ->assertJsonStructure([
                'tasks',
                'pending',
                'completed',
                'summary' => ['total', 'pending', 'completed', 'urgent', 'warning'],
                'manageable_subjects',
            ]);
    }

    public function test_tasks_requires_authentication(): void
    {
        $this->getJson('/api/tasks')->assertUnauthorized();
    }

    public function test_user_can_toggle_personal_task_completion(): void
    {
        $user1 = $this->student('11111');
        $user2 = $this->student('22222');
        $task = $this->task();

        // User 1 menyelesaikan tugas
        $this->actingAs($user1)
            ->postJson("/api/tasks/{$task->id}/toggle")
            ->assertOk()
            ->assertJsonPath('is_completed', true);

        $this->assertDatabaseHas('user_task_completions', [
            'user_id' => $user1->id,
            'task_id' => $task->id,
            'is_completed' => true,
        ]);

        // User 2 tidak terpengaruh
        $this->assertDatabaseMissing('user_task_completions', [
            'user_id' => $user2->id,
            'task_id' => $task->id,
        ]);

        // User 1 membatalkan penyelesaian
        $this->actingAs($user1)
            ->postJson("/api/tasks/{$task->id}/toggle")
            ->assertOk()
            ->assertJsonPath('is_completed', false);

        $this->assertDatabaseHas('user_task_completions', [
            'user_id' => $user1->id,
            'task_id' => $task->id,
            'is_completed' => false,
        ]);
    }

    public function test_student_of_other_target_cannot_toggle_task(): void
    {
        // B1 student vs task untuk grup AA (kelas teori lain)
        $userB1 = $this->student('11111', 'B1');
        $task = $this->task(['target_group' => 'AA_THEORY']);

        $this->actingAs($userB1)
            ->postJson("/api/tasks/{$task->id}/toggle")
            ->assertForbidden();

        $this->assertDatabaseCount('user_task_completions', 0);
    }

    public function test_toggle_missing_task_returns_404(): void
    {
        $user = $this->student('11111');

        $this->actingAs($user)
            ->postJson('/api/tasks/999999/toggle')
            ->assertNotFound();
    }
}
