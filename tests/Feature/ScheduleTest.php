<?php

namespace Tests\Feature;

use App\Models\Schedule;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ScheduleTest extends TestCase
{
    use RefreshDatabase;

    private function student(string $niu = '11111', string $group = 'B1'): User
    {
        return User::create([
            'niu' => $niu,
            'name' => 'Student ' . $niu,
            'pin_hash' => Hash::make('123456'),
            'role' => 'STUDENT',
            'practicum_group' => $group,
            'is_active' => true,
        ]);
    }

    private function subject(string $code = 'TRI201'): Subject
    {
        return Subject::create([
            'code' => $code,
            'name' => 'Pemrograman Web Lanjut',
            'type' => 'THEORY',
        ]);
    }

    private function schedule(Subject $subject, array $overrides = []): Schedule
    {
        return Schedule::create(array_merge([
            'subject_id' => $subject->id,
            'target_group' => 'BB_THEORY',
            'day_of_week' => 1,
            'start_time' => '08:00:00',
            'end_time' => '09:40:00',
            'room' => 'Ruang 201',
            'lecturer_name' => 'Pak Budi',
        ], $overrides));
    }

    public function test_schedules_payload_contains_week_grid(): void
    {
        $student = $this->student();
        $subject = $this->subject();
        $this->schedule($subject);

        $this->actingAs($student)
            ->getJson('/api/schedules')
            ->assertOk()
            ->assertJsonStructure([
                'weekly' => ['1', '2', '3', '4', '5', '6'],
                'makeup',
                'all',
                'current_day',
                'week_start',
                'week_end',
                'manageable_subjects',
            ]);
    }

    public function test_schedules_requires_authentication(): void
    {
        $this->getJson('/api/schedules')->assertUnauthorized();
    }

    public function test_pj_can_create_emergency_online_override(): void
    {
        $pj = User::create([
            'niu' => '22222',
            'name' => 'PJ Web',
            'pin_hash' => Hash::make('123456'),
            'role' => 'PJ',
            'theory_class' => 'BB',
            'practicum_group' => 'B1',
            'is_active' => true,
        ]);

        $subject = $this->subject();
        $schedule = $this->schedule($subject);

        $this->actingAs($pj)->postJson('/api/schedules/override', [
            'schedule_id' => $schedule->id,
            'original_date' => now()->format('Y-m-d'),
            'status' => 'ONLINE',
            'meeting_url' => 'https://zoom.us/j/123456',
            'meeting_passcode' => '123456',
            'reason' => 'Dosen ke luar kota',
            'send_waha_blast' => false,
        ])
            ->assertOk()
            ->assertJsonStructure(['message']);

        $this->assertDatabaseHas('schedule_overrides', [
            'schedule_id' => $schedule->id,
            'status' => 'ONLINE',
            'meeting_url' => 'https://zoom.us/j/123456',
        ]);
    }

    public function test_unauthorized_user_cannot_override_schedule(): void
    {
        $student = $this->student('33333');
        $subject = $this->subject();
        $schedule = $this->schedule($subject);

        $this->actingAs($student)->postJson('/api/schedules/override', [
            'schedule_id' => $schedule->id,
            'original_date' => now()->format('Y-m-d'),
            'status' => 'ONLINE',
            'meeting_url' => 'https://zoom.us/j/123456',
            'send_waha_blast' => false,
        ])->assertForbidden();

        $this->assertDatabaseCount('schedule_overrides', 0);
    }

    public function test_pj_can_override_schedule_of_any_subject_in_own_class(): void
    {
        // PJ Kelas: tidak ada batasan matkul — matkul apa pun boleh,
        // selama jadwalnya di kelas miliknya sendiri.
        $pj = User::create([
            'niu' => '22222',
            'name' => 'PJ Kelas',
            'pin_hash' => Hash::make('123456'),
            'role' => 'PJ',
            'theory_class' => 'BB',
            'practicum_group' => 'B1',
            'is_active' => true,
        ]);

        $subject = $this->subject('TRI999');
        $schedule = $this->schedule($subject); // BB_THEORY = kelas PJ

        $this->actingAs($pj)->postJson('/api/schedules/override', [
            'schedule_id' => $schedule->id,
            'original_date' => now()->format('Y-m-d'),
            'status' => 'ONLINE',
            'meeting_url' => 'https://zoom.us/j/123456',
            'send_waha_blast' => false,
        ])->assertOk();
    }

    public function test_pj_cannot_create_overlapping_schedule_in_same_group_and_day(): void
    {
        $pj = User::create([
            'niu' => '33333',
            'name' => 'PJ Bentrok',
            'pin_hash' => Hash::make('123456'),
            'role' => 'PJ',
            'theory_class' => 'BB',
            'practicum_group' => 'B1',
            'is_active' => true,
        ]);

        $subject = $this->subject('TRI202');
        $this->schedule($subject);

        $this->actingAs($pj)->postJson('/api/schedules', [
            'subject_id' => $subject->id,
            'target_group' => 'BB_THEORY',
            'day_of_week' => 1,
            'start_time' => '09:00',
            'end_time' => '10:40',
            'room' => 'Ruang 202',
            'lecturer_name' => 'Pak Budi',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('start_time');

        $this->assertDatabaseCount('schedules', 1);
    }

    public function test_pj_can_reset_override_back_to_normal(): void
    {
        $pj = User::create([
            'niu' => '22222',
            'name' => 'PJ Web',
            'pin_hash' => Hash::make('123456'),
            'role' => 'PJ',
            'theory_class' => 'BB',
            'practicum_group' => 'B1',
            'is_active' => true,
        ]);

        $subject = $this->subject();
        $schedule = $this->schedule($subject);
        $date = now()->format('Y-m-d');

        $this->actingAs($pj)->postJson('/api/schedules/override', [
            'schedule_id' => $schedule->id,
            'original_date' => $date,
            'status' => 'CANCELLED',
            'reason' => 'Dosen sakit',
            'send_waha_blast' => false,
        ])->assertOk();

        $this->actingAs($pj)->postJson('/api/schedules/override', [
            'schedule_id' => $schedule->id,
            'original_date' => $date,
            'status' => 'NORMAL',
            'send_waha_blast' => false,
        ])->assertOk();

        $this->assertDatabaseHas('schedule_overrides', [
            'schedule_id' => $schedule->id,
            'original_date' => $date,
            'status' => 'NORMAL',
        ]);
    }

    public function test_pj_cannot_create_schedule_for_other_class(): void
    {
        // PJ mahasiswa BB/B1 hanya mengelola kelas teori BB & praktikum B1.
        $pj = User::create([
            'niu' => '44444',
            'name' => 'PJ BB B1',
            'pin_hash' => Hash::make('123456'),
            'role' => 'PJ',
            'theory_class' => 'BB',
            'practicum_group' => 'B1',
            'is_active' => true,
        ]);

        $subject = $this->subject('TRI204');

        $this->actingAs($pj)->postJson('/api/schedules', [
            'subject_id' => $subject->id,
            'target_group' => 'AA_THEORY',
            'day_of_week' => 2,
            'start_time' => '10:00',
            'end_time' => '11:40',
            'room' => 'Ruang 204',
            'lecturer_name' => 'Pak Budi',
        ])->assertForbidden();

        $this->assertDatabaseCount('schedules', 0);
    }

    public function test_pj_cannot_override_schedule_of_other_class(): void
    {
        $pj = User::create([
            'niu' => '55555',
            'name' => 'PJ BB B1',
            'pin_hash' => Hash::make('123456'),
            'role' => 'PJ',
            'theory_class' => 'BB',
            'practicum_group' => 'B1',
            'is_active' => true,
        ]);

        $subject = $this->subject('TRI205');
        // Jadwal kelas AA, sedangkan PJ dari BB/B1.
        $schedule = $this->schedule($subject, ['target_group' => 'AA_THEORY']);

        $this->actingAs($pj)->postJson('/api/schedules/override', [
            'schedule_id' => $schedule->id,
            'original_date' => now()->format('Y-m-d'),
            'status' => 'ONLINE',
            'meeting_url' => 'https://zoom.us/j/123456',
            'send_waha_blast' => false,
        ])->assertForbidden();

        $this->assertDatabaseCount('schedule_overrides', 0);
    }

    public function test_pj_can_create_schedule_for_own_classes(): void
    {
        $pj = User::create([
            'niu' => '66666',
            'name' => 'PJ BB B1',
            'pin_hash' => Hash::make('123456'),
            'role' => 'PJ',
            'theory_class' => 'BB',
            'practicum_group' => 'B1',
            'is_active' => true,
        ]);

        $subject = $this->subject('TRI206');
        $practicumSubject = Subject::create([
            'code' => 'TRP206',
            'name' => 'Praktikum Pemrograman Web',
            'type' => 'PRACTICUM',
        ]);

        // Kelas teori sendiri (BB_THEORY) → boleh.
        $this->actingAs($pj)->postJson('/api/schedules', [
            'subject_id' => $subject->id,
            'target_group' => 'BB_THEORY',
            'day_of_week' => 3,
            'start_time' => '07:00',
            'end_time' => '08:40',
            'room' => 'Ruang 206',
            'lecturer_name' => 'Pak Budi',
        ])->assertCreated();

        // Kloter praktikum sendiri (B1_PRACTICUM) → boleh.
        $this->actingAs($pj)->postJson('/api/schedules', [
            'subject_id' => $practicumSubject->id,
            'target_group' => 'B1_PRACTICUM',
            'day_of_week' => 4,
            'start_time' => '07:00',
            'end_time' => '08:40',
            'room' => 'Lab 1',
            'lecturer_name' => 'Pak Budi',
        ])->assertCreated();

        $this->assertDatabaseCount('schedules', 2);
    }

    public function test_pj_can_reschedule_class_to_another_day(): void
    {
        // Kasus: dosen infokan H-2, pertemuan Selasa dipindah ke Sabtu,
        // minggu depan pulih normal (jadwal master tidak disentuh).
        $pj = User::create([
            'niu' => '77777',
            'name' => 'PJ Reschedule',
            'pin_hash' => Hash::make('123456'),
            'role' => 'PJ',
            'theory_class' => 'BB',
            'practicum_group' => 'B1',
            'is_active' => true,
        ]);

        $subject = $this->subject('TRI207');
        $schedule = $this->schedule($subject); // BB_THEORY = kelas PJ

        $originalDate = '2026-09-08'; // Selasa
        $newDate = '2026-09-12';      // Sabtu

        $this->actingAs($pj)->postJson('/api/schedules/override', [
            'schedule_id' => $schedule->id,
            'original_date' => $originalDate,
            'status' => 'RESCHEDULED',
            'new_date' => $newDate,
            'reason' => 'Dosen berhalangan, kelas dipindah ke Sabtu.',
            'send_waha_blast' => false,
        ])->assertOk();

        $this->assertDatabaseHas('schedule_overrides', [
            'schedule_id' => $schedule->id,
            'original_date' => $originalDate,
            'status' => 'RESCHEDULED',
            'new_date' => $newDate,
        ]);

        // Minggu depan ( Senin 21 Sep ): tidak ada override aktif → kartu kembali NORMAL.
        $this->travelTo('2026-09-21');
        $viewer = User::create([
            'niu' => '88888',
            'name' => 'Student 88888',
            'pin_hash' => Hash::make('123456'),
            'role' => 'STUDENT',
            'theory_class' => 'BB',
            'practicum_group' => 'B1',
            'is_active' => true,
        ]);
        $response = $this->actingAs($viewer)->getJson('/api/schedules');
        $response->assertOk();

        $card = collect($response->json('weekly.1'))->firstWhere('subject_id', $subject->id);
        $this->assertNotNull($card);
        $this->assertSame('NORMAL', $card['status']);
        $this->assertSame([], $response->json('makeup.6'));
    }

    public function test_reschedule_requires_new_date(): void
    {
        $pj = User::create([
            'niu' => '99999',
            'name' => 'PJ Tanpa Tanggal',
            'pin_hash' => Hash::make('123456'),
            'role' => 'PJ',
            'theory_class' => 'BB',
            'practicum_group' => 'B1',
            'is_active' => true,
        ]);

        $subject = $this->subject('TRI208');
        $schedule = $this->schedule($subject);

        $this->actingAs($pj)->postJson('/api/schedules/override', [
            'schedule_id' => $schedule->id,
            'original_date' => now()->format('Y-m-d'),
            'status' => 'RESCHEDULED',
            'send_waha_blast' => false,
        ])->assertJsonValidationErrors('new_date');

        $this->assertDatabaseCount('schedule_overrides', 0);
    }
}
