<?php

namespace Database\Seeders;

use App\Models\Schedule;
use App\Models\ScheduleOverride;
use App\Models\Subject;
use App\Models\Task;
use App\Models\User;
use App\Models\UserTaskCompletion;
use App\Models\WahaGroupConfig;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. WAHA GROUPS
        WahaGroupConfig::create([
            'target_group' => 'BB_THEORY',
            'group_jid' => '120363234567890123@g.us',
            'group_name' => 'Kelas BB - Teori TRI 2024',
        ]);
        WahaGroupConfig::create([
            'target_group' => 'AA_THEORY',
            'group_jid' => '120363234567890126@g.us', // ponytail: placeholder, isi JID asli via halaman Admin
            'group_name' => 'Kelas AA - Teori TRI 2024',
        ]);
        WahaGroupConfig::create([
            'target_group' => 'B1_PRACTICUM',
            'group_jid' => '120363234567890124@g.us',
            'group_name' => 'Praktikum Kloter B1 TRI 2024',
        ]);
        WahaGroupConfig::create([
            'target_group' => 'B2_PRACTICUM',
            'group_jid' => '120363234567890125@g.us',
            'group_name' => 'Praktikum Kloter B2 TRI 2024',
        ]);
        WahaGroupConfig::create([
            'target_group' => 'A1_PRACTICUM',
            'group_jid' => '120363234567890127@g.us', // ponytail: placeholder, isi JID asli via halaman Admin
            'group_name' => 'Praktikum Kloter A1 TRI 2024',
        ]);
        WahaGroupConfig::create([
            'target_group' => 'A2_PRACTICUM',
            'group_jid' => '120363234567890128@g.us', // ponytail: placeholder, isi JID asli via halaman Admin
            'group_name' => 'Praktikum Kloter A2 TRI 2024',
        ]);

        // 2. USERS
        // Admin / Komti (satu-satunya user bawaan; PJ & mahasiswa daftar via halaman Register / input Admin)
        $admin = User::create([
            'niu' => '00001',
            'name' => 'Komti Triyono (Super Admin)',
            'pin_hash' => Hash::make('123456'),
            'role' => 'ADMIN',
            'practicum_group' => 'B1',
            'is_active' => true,
        ]);

        // 3. SUBJECTS (Data riil kelas BB & B2)
        $matdis = Subject::create([
            'code' => 'SVRI261101',
            'name' => 'Matematika Diskrit',
            'type' => 'THEORY',
        ]);
        $pemkom = Subject::create([
            'code' => 'SVRI261102',
            'name' => 'Pemrograman Komputer',
            'type' => 'THEORY',
        ]);
        $kodata = Subject::create([
            'code' => 'SVRI261103',
            'name' => 'Komunikasi Data',
            'type' => 'THEORY',
        ]);
        $ttele = Subject::create([
            'code' => 'SVRI261104',
            'name' => 'Teknik Telekomunikasi',
            'type' => 'THEORY',
        ]);
        $basisdata = Subject::create([
            'code' => 'SVRI261105',
            'name' => 'Basis Data',
            'type' => 'THEORY',
        ]);
        $prakPemkom = Subject::create([
            'code' => 'SVRI261106',
            'name' => 'Praktikum Pemrograman Komputer',
            'type' => 'PRACTICUM',
        ]);
        $prakTekkom = Subject::create([
            'code' => 'SVRI261107',
            'name' => 'Praktikum Teknik Komputer',
            'type' => 'PRACTICUM',
        ]);
        $prakPid = Subject::create([
            'code' => 'SVRI261108',
            'name' => 'Praktikum Pendukung Infrastruktur Digital',
            'type' => 'PRACTICUM',
        ]);
        // Mata kuliah umum RI (belum terjadwal)
        Subject::create([
            'code' => 'UNKU260011',
            'name' => 'Literasi Kesehatan',
            'type' => 'THEORY',
        ]);
        Subject::create([
            'code' => 'UNKU260012',
            'name' => 'Humaniora Digital',
            'type' => 'THEORY',
        ]);

        // 5. MASTER SCHEDULES (Data riil kelas BB & B2)
        // Senin: Praktikum Pendukung Infrastruktur Digital (B2)
        $schPid = Schedule::create([
            'subject_id' => $prakPid->id,
            'target_group' => 'B2_PRACTICUM',
            'day_of_week' => 1,
            'start_time' => '12:15:00',
            'end_time' => '15:55:00',
            'room' => 'G. 103 Layanan Instalasi Listrik Lab TTL',
            'lecturer_name' => 'Achmad Solaeman, S.Tr.T., M.Eng.',
        ]);

        // Selasa: Teknik Telekomunikasi (Teori BB)
        $schTtele = Schedule::create([
            'subject_id' => $ttele->id,
            'target_group' => 'BB_THEORY',
            'day_of_week' => 2,
            'start_time' => '07:15:00',
            'end_time' => '08:55:00',
            'room' => 'R. KULIAH CM 201',
            'lecturer_name' => 'Ir. Budi Bayu Murti, S.T., M.T.',
        ]);

        // Selasa: Praktikum Pemrograman Komputer (B2)
        $schPrakPemkom = Schedule::create([
            'subject_id' => $prakPemkom->id,
            'target_group' => 'B2_PRACTICUM',
            'day_of_week' => 2,
            'start_time' => '12:15:00',
            'end_time' => '15:55:00',
            'room' => 'HS 103 Lab TAJ Layanan Komputasi Awan',
            'lecturer_name' => 'Dr. Ir. Ronald Adrian, S.T., M.Eng., IPM.',
        ]);

        // Rabu: Basis Data (Teori BB)
        $schBasisData = Schedule::create([
            'subject_id' => $basisdata->id,
            'target_group' => 'BB_THEORY',
            'day_of_week' => 3,
            'start_time' => '07:15:00',
            'end_time' => '08:55:00',
            'room' => 'R. KULIAH CU 205',
            'lecturer_name' => 'Achmad Solaeman, S.Tr.T., M.Eng.',
        ]);

        // Rabu: Komunikasi Data (Teori BB)
        $schKodata = Schedule::create([
            'subject_id' => $kodata->id,
            'target_group' => 'BB_THEORY',
            'day_of_week' => 3,
            'start_time' => '09:15:00',
            'end_time' => '10:55:00',
            'room' => 'R. KULIAH HU 208',
            'lecturer_name' => 'Ardhi Wicaksono Santoso, S.Kom., M.Cs.',
        ]);

        // Rabu: Praktikum Teknik Komputer (B2)
        $schPrakTekkom = Schedule::create([
            'subject_id' => $prakTekkom->id,
            'target_group' => 'B2_PRACTICUM',
            'day_of_week' => 3,
            'start_time' => '12:15:00',
            'end_time' => '15:55:00',
            'room' => 'G. 302 Layanan Bengkel Listrik Lab Elektronika',
            'lecturer_name' => 'Dr. Sahirul Alam, S.T., M.Eng.',
        ]);

        // Kamis: Pemrograman Komputer (Teori BB)
        $schPemkom = Schedule::create([
            'subject_id' => $pemkom->id,
            'target_group' => 'BB_THEORY',
            'day_of_week' => 4,
            'start_time' => '07:15:00',
            'end_time' => '08:55:00',
            'room' => 'R. KULIAH HU 207',
            'lecturer_name' => 'Dr. Ir. Ronald Adrian, S.T., M.Eng., IPM.',
        ]);

        // Kamis: Matematika Diskrit (Teori BB)
        $schMatdis = Schedule::create([
            'subject_id' => $matdis->id,
            'target_group' => 'BB_THEORY',
            'day_of_week' => 4,
            'start_time' => '09:15:00',
            'end_time' => '10:55:00',
            'room' => 'R. KULIAH CU 204',
            'lecturer_name' => 'Ir. Yuris Mulya Saputra, S.T., M.Sc., Ph.D.',
        ]);

        // ===== KLOTER B1 (Data riil) =====

        // Senin: Praktikum Pendukung Infrastruktur Digital (B1)
        Schedule::create([
            'subject_id' => $prakPid->id,
            'target_group' => 'B1_PRACTICUM',
            'day_of_week' => 1,
            'start_time' => '07:15:00',
            'end_time' => '10:55:00',
            'room' => 'G. 103 Layanan Instalasi Listrik Lab TTL',
            'lecturer_name' => 'Achmad Solaeman, S.Tr.T., M.Eng.',
        ]);

        // Selasa: Praktikum Teknik Komputer (B1)
        Schedule::create([
            'subject_id' => $prakTekkom->id,
            'target_group' => 'B1_PRACTICUM',
            'day_of_week' => 2,
            'start_time' => '12:15:00',
            'end_time' => '15:55:00',
            'room' => 'G. 302 Layanan Bengkel Listrik Lab Elektronika',
            'lecturer_name' => 'Dr. Sahirul Alam, S.T., M.Eng.',
        ]);

        // Rabu: Praktikum Pemrograman Komputer (B1)
        Schedule::create([
            'subject_id' => $prakPemkom->id,
            'target_group' => 'B1_PRACTICUM',
            'day_of_week' => 3,
            'start_time' => '12:15:00',
            'end_time' => '15:55:00',
            'room' => 'HS 103 Lab TAJ Layanan Komputasi Awan',
            'lecturer_name' => 'Dr. Ir. Ronald Adrian, S.T., M.Eng., IPM.',
        ]);

        // ===== KELAS AA & KLOTER A1 (Data riil) =====

        // Senin: Matematika Diskrit (Teori AA)
        Schedule::create([
            'subject_id' => $matdis->id,
            'target_group' => 'AA_THEORY',
            'day_of_week' => 1,
            'start_time' => '09:15:00',
            'end_time' => '10:55:00',
            'room' => 'R. KULIAH CU 206',
            'lecturer_name' => 'Ir. Yuris Mulya Saputra, S.T., M.Sc., Ph.D.',
        ]);

        // Senin: Praktikum Pemrograman Komputer (A1)
        Schedule::create([
            'subject_id' => $prakPemkom->id,
            'target_group' => 'A1_PRACTICUM',
            'day_of_week' => 1,
            'start_time' => '12:15:00',
            'end_time' => '15:55:00',
            'room' => 'HS 103 Lab TAJ Layanan Komputasi Awan',
            'lecturer_name' => 'Ir. Yuris Mulya Saputra, S.T., M.Sc., Ph.D.',
        ]);

        // Selasa: Praktikum Pendukung Infrastruktur Digital (A1)
        Schedule::create([
            'subject_id' => $prakPid->id,
            'target_group' => 'A1_PRACTICUM',
            'day_of_week' => 2,
            'start_time' => '12:15:00',
            'end_time' => '15:55:00',
            'room' => 'G. 103 Layanan Instalasi Listrik Lab TTL',
            'lecturer_name' => 'Ardhi Wicaksono Santoso, S.Kom., M.Cs.',
        ]);

        // Rabu: Praktikum Teknik Komputer (A1)
        Schedule::create([
            'subject_id' => $prakTekkom->id,
            'target_group' => 'A1_PRACTICUM',
            'day_of_week' => 3,
            'start_time' => '07:15:00',
            'end_time' => '10:55:00',
            'room' => 'G. 301 Layanan Perangkat Keras Komputer',
            'lecturer_name' => 'Dr. Sahirul Alam, S.T., M.Eng.',
        ]);

        // ===== KLOTER A2 (Data riil) =====

        // Senin: Praktikum Teknik Komputer (A2)
        Schedule::create([
            'subject_id' => $prakTekkom->id,
            'target_group' => 'A2_PRACTICUM',
            'day_of_week' => 1,
            'start_time' => '12:15:00',
            'end_time' => '15:55:00',
            'room' => 'G. 301 Layanan Perangkat Keras Komputer',
            'lecturer_name' => 'Dr. Sahirul Alam, S.T., M.Eng.',
        ]);

        // Selasa: Praktikum Pendukung Infrastruktur Digital (A2)
        Schedule::create([
            'subject_id' => $prakPid->id,
            'target_group' => 'A2_PRACTICUM',
            'day_of_week' => 2,
            'start_time' => '07:15:00',
            'end_time' => '10:55:00',
            'room' => 'G. 103 Layanan Instalasi Listrik Lab TTL',
            'lecturer_name' => 'Ardhi Wicaksono Santoso, S.Kom., M.Cs.',
        ]);

        // Rabu: Praktikum Pemrograman Komputer (A2)
        Schedule::create([
            'subject_id' => $prakPemkom->id,
            'target_group' => 'A2_PRACTICUM',
            'day_of_week' => 3,
            'start_time' => '07:15:00',
            'end_time' => '10:55:00',
            'room' => 'HS 103 Lab TAJ Layanan Komputasi Awan',
            'lecturer_name' => 'Ir. Yuris Mulya Saputra, S.T., M.Sc., Ph.D.',
        ]);

        // Rabu: Teknik Telekomunikasi (Teori AA)
        Schedule::create([
            'subject_id' => $ttele->id,
            'target_group' => 'AA_THEORY',
            'day_of_week' => 3,
            'start_time' => '12:15:00',
            'end_time' => '13:55:00',
            'room' => 'R. KULIAH HU 208',
            'lecturer_name' => 'Ir. Budi Bayu Murti, S.T., M.T.',
        ]);

        // Kamis: Pemrograman Komputer (Teori AA)
        Schedule::create([
            'subject_id' => $pemkom->id,
            'target_group' => 'AA_THEORY',
            'day_of_week' => 4,
            'start_time' => '07:15:00',
            'end_time' => '08:55:00',
            'room' => 'R. KULIAH HU 209',
            'lecturer_name' => 'Ir. Yuris Mulya Saputra, S.T., M.Sc., Ph.D.',
        ]);

        // Kamis: Basis Data (Teori AA)
        Schedule::create([
            'subject_id' => $basisdata->id,
            'target_group' => 'AA_THEORY',
            'day_of_week' => 4,
            'start_time' => '09:15:00',
            'end_time' => '10:55:00',
            'room' => 'R. KULIAH HU 208',
            'lecturer_name' => 'Ir. Unan Yusmaniar Oktiawati, S.T., M.Sc., Ph.D.',
        ]);

        // Kamis: Komunikasi Data (Teori AA)
        Schedule::create([
            'subject_id' => $kodata->id,
            'target_group' => 'AA_THEORY',
            'day_of_week' => 4,
            'start_time' => '12:15:00',
            'end_time' => '13:55:00',
            'room' => 'R. KULIAH CU 204',
            'lecturer_name' => 'Dr. Ir. Ronald Adrian, S.T., M.Eng., IPM.',
        ]);

        // 6. SCHEDULE OVERRIDE — dikosongkan (override diinput manual via aplikasi)

        // 7. TASKS — dikosongkan (tugas diinput manual via aplikasi)
    }
}
