<?php

namespace Tests\Feature;

use App\Models\Schedule;
use App\Models\Subject;
use App\Models\User;
use App\Models\WahaGroupConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'niu' => '00001',
            'name' => 'Komti Admin',
            'pin_hash' => Hash::make('123456'),
            'role' => 'ADMIN',
            'theory_class' => 'BB',
            'practicum_group' => 'B1',
            'is_active' => true,
        ]);
    }

    private function student(): User
    {
        return User::create([
            'niu' => '11111',
            'name' => 'Student',
            'pin_hash' => Hash::make('123456'),
            'role' => 'STUDENT',
            'theory_class' => 'BB',
            'practicum_group' => 'B1',
            'is_active' => true,
        ]);
    }

    public function test_admin_can_access_admin_payload(): void
    {
        $admin = $this->admin();

        WahaGroupConfig::create([
            'target_group' => 'BB_THEORY',
            'group_name' => 'Kelas BB (Teori)',
            'group_jid' => '120363001@g.us',
        ]);

        $this->actingAs($admin)
            ->getJson('/api/admin')
            ->assertOk()
            ->assertJsonStructure([
                'users',
                'subjects',
                'waha_configs',
                'waha_settings' => ['waha_base_url', 'waha_session', 'waha_api_key'],
                'recent_logs',
            ]);
    }

    public function test_student_cannot_access_admin_payload(): void
    {
        $this->actingAs($this->student())
            ->getJson('/api/admin')
            ->assertForbidden();
    }

    public function test_admin_can_update_waha_settings(): void
    {
        $admin = $this->admin();
        $cfg = WahaGroupConfig::create([
            'target_group' => 'BB_THEORY',
            'group_name' => 'Kelas BB (Teori)',
            'group_jid' => '120363001@g.us',
        ]);

        $this->actingAs($admin)->postJson('/api/admin/waha/settings', [
            'waha_base_url' => 'http://localhost:3000',
            'waha_session' => 'kelas_tri',
            'waha_api_key' => 'secret123',
            'groups' => [
                ['id' => $cfg->id, 'group_jid' => '120363999@g.us'],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('waha_group_configs', [
            'id' => $cfg->id,
            'group_jid' => '120363999@g.us',
        ]);
        $this->assertDatabaseHas('app_settings', [
            'setting_key' => 'waha_session',
            'setting_value' => 'kelas_tri',
        ]);
    }

    public function test_admin_can_create_update_and_delete_subject(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/api/admin/subjects', [
            'code' => 'TRI201',
            'name' => 'Pemrograman Web Lanjut',
            'type' => 'THEORY',
        ])->assertCreated();

        $subject = Subject::firstOrFail();

        $this->actingAs($admin)
            ->putJson("/api/admin/subjects/{$subject->id}", [
                'code' => 'TRI201',
                'name' => 'Pemrograman Web (Revisi)',
                'type' => 'THEORY',
            ])
            ->assertOk();

        $this->assertDatabaseHas('subjects', ['id' => $subject->id, 'name' => 'Pemrograman Web (Revisi)']);

        $this->actingAs($admin)
            ->deleteJson("/api/admin/subjects/{$subject->id}")
            ->assertOk();

        $this->assertDatabaseMissing('subjects', ['id' => $subject->id]);
    }

    public function test_subject_with_schedules_cannot_be_deleted(): void
    {
        $admin = $this->admin();
        $subject = Subject::create(['code' => 'TRI201', 'name' => 'Web', 'type' => 'THEORY']);
        Schedule::create([
            'subject_id' => $subject->id,
            'target_group' => 'BB_THEORY',
            'day_of_week' => 1,
            'start_time' => '08:00:00',
            'end_time' => '09:40:00',
            'room' => 'Ruang 201',
            'lecturer_name' => 'Pak Budi',
        ]);

        $this->actingAs($admin)
            ->deleteJson("/api/admin/subjects/{$subject->id}")
            ->assertStatus(409);
    }

    public function test_admin_can_create_and_delete_user(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/api/admin/users', [
            'niu' => '77777',
            'name' => 'Mahasiswa Baru',
            'role' => 'STUDENT',
            'theory_class' => 'BB',
            'practicum_group' => 'B2',
        ])->assertCreated();

        $created = User::where('niu', '77777')->firstOrFail();

        $this->actingAs($admin)
            ->deleteJson("/api/admin/users/{$created->id}")
            ->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $created->id]);
    }

    public function test_admin_cannot_delete_self(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->deleteJson("/api/admin/users/{$admin->id}")
            ->assertStatus(409);
    }

    public function test_admin_can_reset_user_pin(): void
    {
        $admin = $this->admin();
        $student = $this->student();

        $this->actingAs($admin)
            ->postJson('/api/admin/users/reset-pin', ['user_id' => $student->id])
            ->assertOk();

        $student->refresh();
        $this->assertNull($student->pin_hash);
        $this->assertFalse($student->is_active);
    }

    public function test_guest_cannot_manage_users(): void
    {
        $this->postJson('/api/admin/users', [
            'niu' => '77777',
            'name' => 'X',
            'role' => 'STUDENT',
        ])->assertUnauthorized();
    }
}
