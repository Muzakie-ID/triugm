# Product Requirements Document (PRD)

## Project Overview
* **Project Name:** Class & Lab Smart Schedule Assistant (Portal Kelas & Praktikum)
* **Version:** 1.1.0
* **Target Audience:** Mahasiswa Kelas BB (Teori), Sub-kloter Praktikum B1, Sub-kloter Praktikum B2, Penanggung Jawab (PJ) Kelas, dan Komti (Super Admin).
* **Core Value:** Mengeliminasi misinformasi jadwal silang (teori bersama vs pecahan kloter lab), mengotomatisasi pengalihan kelas daring saat dosen berhalangan hadir fisik, mengirim pengingat H-15 menit via WhatsApp (WAHA) dengan tag `@everyone`, dan mempermudah pelacakan tenggat waktu tugas secara personal.

---

## 1. System Architecture & Tech Stack
* **Frontend:** Inertia.js + React 18 + Tailwind CSS + Lucide Icons + PWA Plugin (Mobile-First Web App).
* **Backend:** Laravel 11 (PHP 8.3) dengan Eloquent ORM, Gate/Policy Authorization, Database Queue, dan Task Scheduling per menit.
* **Database:** PostgreSQL 16.
* **WhatsApp Gateway:** WAHA (WhatsApp HTTP API - Core/Plus) via Docker Container.
* **Deployment & Infrastructure:** Multi-container Docker Compose berjalan di belakang Nginx Host Reverse Proxy (SSL Terminated di Host).

---

## 2. User Roles & Permission Matrix (RBAC)

| Modul Fitur | Mahasiswa (Student) | PJ Kelas (Editor) | Super Admin / Komti |
| :--- | :---: | :---: | :---: |
| Akses Dashboard Personal (Filter Otomatis) | ✅ | ✅ | ✅ |
| Centang Mandiri Status Tugas (*Personal Checklist*) | ✅ | ✅ | ✅ |
| Ubah PIN Akun Sendiri | ✅ | ✅ | ✅ |
| Input Reschedule / Ganti Ruang / Geser Jam | ❌ | ✅ *(Kelas Sendiri)* | ✅ *(Semua Kelas)* |
| Input Mode Darurat Dosen Berhalangan (Switch ke Zoom/Meet) | ❌ | ✅ *(Kelas Sendiri)* | ✅ *(Semua Kelas)* |
| Input & Edit Tugas Kuliah / Praktikum | ❌ | ✅ *(Kelas Sendiri)* | ✅ *(Semua Kelas)* |
| Trigger Manual Blast Notifikasi WhatsApp | ❌ | ✅ *(Kelas Sendiri)* | ✅ *(Bebas)* |
| Kelola Master Jadwal & Ruang Lab Semester | ❌ | ❌ | ✅ |
| Kelola Master Data Mahasiswa & Reset PIN | ❌ | ❌ | ✅ |
| Kelola Konfigurasi WAHA & Session WhatsApp | ❌ | ❌ | ✅ |

---

## 3. Detailed User Stories & Flows

### 3.1 Authentication Flow (NIM + 6-Digit PIN Activation)
1. **Single Entry Step:** Mahasiswa membuka PWA dan memasukkan **NIM**.
2. **Detection Phase:**
   * Jika NIM tidak terdaftar $\rightarrow$ Error alert: *"NIM tidak terdaftar. Hubungi Komti."*
   * Jika NIM terdaftar tapi `pin_hash` masih `NULL` (Akun Baru) $\rightarrow$ Form beralih ke **Mode Aktivasi**: input 6 digit PIN dan konfirmasi PIN.
   * Jika NIM terdaftar dan sudah aktif $\rightarrow$ Form meminta input 6 digit PIN untuk login.
3. **Session Persistence:** State login disimpan via HTTP-only cookie/session Laravel. Preferensi kloter (`B1` / `B2`) otomatis terkunci berdasarkan data mahasiswa.

### 3.2 Dynamic Schedule & Emergency Reschedule Engine
1. **Reguler View:** Mahasiswa melihat kalender kuliah teori (BB) digabung dengan praktikum kloternya (B1 atau B2).
2. **Insidental Dosen Berhalangan Hadir (Emergency Online Switch):**
   * PJ Kelas memilih opsi *"Dosen Berhalangan (Ganti Online)"*.
   * Menginput link pertemuan (Zoom/Google Meet) + Passcode + Catatan instruksi.
   * Bot WAHA langsung mengirim link instan dengan tag `@everyone` ke grup terkait agar mahasiswa tidak perlu datang ke ruang kelas fisik.
   * Jadwal asli di database master tidak berubah permanen (hanya membuat record override untuk tanggal tersebut).
3. **Reschedule / Makeup Class:**
   * PJ Kelas menginput jam/hari/ruang pengganti.
   * Jadwal normal pada tanggal tersebut diberi badge status mencolok (`RESCHEDULED`, `MAKEUP_CLASS`, `CANCELLED`, atau `ONLINE`).

### 3.3 Task & Assignment Tracker
1. **Scope Filtering:**
   * Tugas Teori (`ALL_THEORY`) muncul di seluruh akun mahasiswa.
   * Tugas Praktikum (`B1_PRACTICUM` / `B2_PRACTICUM`) hanya muncul di akun kloter terkait.
2. **Urgency Indicators:**
   * **Merah (Urgent):** $\le 24$ jam menuju deadline (disertai live countdown).
   * **Kuning (Warning):** H-3 hingga H-1 deadline.
   * **Hijau (Safe):** $> 3$ hari.
3. **Personal Checklist:** Mahasiswa dapat menandai tugas sebagai "Selesai" untuk dirinya sendiri tanpa mengubah status tugas bagi mahasiswa lain.

### 3.4 WhatsApp Bot Integration (WAHA Engine)
1. **Smart Routing:**
   * Info Teori $\rightarrow$ Dikirim ke **Grup WA BB**.
   * Info Praktikum B1 $\rightarrow$ Dikirim ke **Grup WA B1**.
   * Info Praktikum B2 $\rightarrow$ Dikirim ke **Grup WA B2**.
2. **H-15 Minute Reminder:**
   * Cron job backend berjalan setiap menit (`* * * * *`).
   * Mencari jadwal kuliah/praktikum hari itu yang waktu mulainya cocok dengan `Current Time + 15 Menit`.
   * Memprioritaskan data `schedule_overrides` jika ada pemindahan jadwal atau link Zoom/Meet.
   * Mengambil daftar partisipan grup via WAHA API dan mengeksekusi blast pesan dengan `@everyone` (*mention all*).
3. **H-1 Task Deadline Reminder:**
   * Cron job harian berjalan setiap pukul 19.00 WIB untuk tugas dengan sisa waktu $\le 24$ jam.

---

## 4. Database Schema Design (PostgreSQL DDL)

```sql
-- ENUM TYPES
CREATE TYPE user_role AS ENUM ('STUDENT', 'PJ', 'ADMIN');
CREATE TYPE practicum_group_type AS ENUM ('B1', 'B2');
CREATE TYPE target_group_type AS ENUM ('ALL_THEORY', 'B1_PRACTICUM', 'B2_PRACTICUM');
CREATE TYPE schedule_status AS ENUM ('NORMAL', 'RESCHEDULED', 'MAKEUP_CLASS', 'CANCELLED', 'ONLINE');

-- 1. USERS TABLE
CREATE TABLE users (
    id BIGSERIAL PRIMARY KEY,
    nim VARCHAR(30) UNIQUE NOT NULL,
    name VARCHAR(100) NOT NULL,
    pin_hash VARCHAR(255) NULL,
    role user_role DEFAULT 'STUDENT' NOT NULL,
    practicum_group practicum_group_type NOT NULL,
    is_active BOOLEAN DEFAULT FALSE NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- 2. SUBJECTS (MATA KULIAH)
CREATE TABLE subjects (
    id BIGSERIAL PRIMARY KEY,
    code VARCHAR(20) NOT NULL,
    name VARCHAR(100) NOT NULL,
    type VARCHAR(20) DEFAULT 'THEORY' NOT NULL, -- THEORY / PRACTICUM
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- 3. COURSE PJS — DIHAPUS: PJ adalah PJ Kelas (bukan per matkul),
--    batas otorisasi = kelas asal PJ (teori + kloter praktikumnya).

-- 4. MASTER SCHEDULES
CREATE TABLE schedules (
    id BIGSERIAL PRIMARY KEY,
    subject_id BIGINT REFERENCES subjects(id) ON DELETE CASCADE,
    target_group target_group_type NOT NULL,
    day_of_week INT NOT NULL, -- 1 (Senin) s/d 7 (Minggu)
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    room VARCHAR(50) NOT NULL,
    meeting_url TEXT NULL,
    lecturer_name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- 5. SCHEDULE OVERRIDES (RESCHEDULE / ONLINE SWITCH / KELAS PENGGANTI)
CREATE TABLE schedule_overrides (
    id BIGSERIAL PRIMARY KEY,
    schedule_id BIGINT REFERENCES schedules(id) ON DELETE CASCADE,
    original_date DATE NOT NULL,
    status schedule_status DEFAULT 'RESCHEDULED' NOT NULL,
    new_date DATE NULL,
    new_start_time TIME NULL,
    new_end_time TIME NULL,
    new_room VARCHAR(50) NULL,
    meeting_url TEXT NULL,
    meeting_passcode VARCHAR(50) NULL,
    reason TEXT NULL,
    is_notified BOOLEAN DEFAULT FALSE NOT NULL,
    created_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- 6. TASKS
CREATE TABLE tasks (
    id BIGSERIAL PRIMARY KEY,
    subject_id BIGINT REFERENCES subjects(id) ON DELETE CASCADE,
    target_group target_group_type NOT NULL,
    title VARCHAR(150) NOT NULL,
    description TEXT NULL,
    deadline TIMESTAMP WITH TIME ZONE NOT NULL,
    submission_url TEXT NULL,
    submission_format VARCHAR(50) NULL,
    created_by BIGINT REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- 7. USER TASK COMPLETIONS (PERSONAL CHECKLIST)
CREATE TABLE user_task_completions (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT REFERENCES users(id) ON DELETE CASCADE,
    task_id BIGINT REFERENCES tasks(id) ON DELETE CASCADE,
    is_completed BOOLEAN DEFAULT TRUE NOT NULL,
    completed_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT unique_user_task UNIQUE (user_id, task_id)
);

-- 8. WAHA CONFIGURATION & LOGS
CREATE TABLE waha_group_configs (
    id BIGSERIAL PRIMARY KEY,
    target_group target_group_type UNIQUE NOT NULL,
    group_jid VARCHAR(100) NOT NULL, -- Format: 120363xxx@g.us
    group_name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE reminder_logs (
    id BIGSERIAL PRIMARY KEY,
    event_type VARCHAR(50) NOT NULL, -- 'H15_SCHEDULE', 'H1_TASK', 'EMERGENCY_ONLINE', 'RESCHEDULE'
    reference_id BIGINT NOT NULL,
    target_group target_group_type NOT NULL,
    sent_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    payload_snapshot JSONB NULL
);

```

---

## 5. API Endpoints Specification

### 5.1 Auth Module

* `POST /api/auth/check-nim` $\rightarrow$ Validasi keberadaan NIM & status aktivasi.
* `POST /api/auth/activate` $\rightarrow$ Setup PIN pertama kali (6 digit).
* `POST /api/auth/login` $\rightarrow$ Login menggunakan NIM & PIN.
* `POST /api/auth/logout` $\rightarrow$ Terminasi sesi.

### 5.2 Dashboard & Schedules Module

* `GET /api/dashboard` $\rightarrow$ Mengambil agenda hari ini & tugas prioritas sesuai profil user.
* `GET /api/schedules/weekly` $\rightarrow$ Mengambil matriks jadwal mingguan (termasuk override aktif).
* `POST /api/schedules/override` *(PJ/Admin Only)* $\rightarrow$ Membuat data reschedule / dialihkan online / kelas pengganti.

### 5.3 Tasks Module

* `GET /api/tasks` $\rightarrow$ Mengambil daftar tugas (filter: pending / selesai).
* `POST /api/tasks` *(PJ/Admin Only)* $\rightarrow$ Menambah tugas baru + trigger WA blast.
* `POST /api/tasks/{id}/toggle-complete` $\rightarrow$ Toggle status checklist personal user.

### 5.4 Admin & WhatsApp Integration

* `POST /api/admin/users/reset-pin` *(Admin Only)* $\rightarrow$ Mereset PIN user menjadi NULL.
* `POST /api/admin/waha/test-blast` *(Admin Only)* $\rightarrow$ Test koneksi & mention all ke grup WA.

---

## 6. WhatsApp Message Templates

### Template 1: H-15 Menit Pengingat Kelas Offline (Mention All)

```text
⏰ @everyone [15 MENIT LAGI MULAI]

📚 Matkul  : {subject_name} ({type})
⏰ Jam     : {start_time} - {end_time} WIB
📍 Ruang   : {room}
👨‍🏫 Pengajar: {lecturer_or_aslab}
{override_note}

Yuk segera bersiap dan menuju ruangan!
🔗 Akses Portal: https://{app_domain}

```

### Template 2: Darurat Dosen Berhalangan Hadir (Ganti Online Zoom/Meet)

```text
🌐 @everyone [PERUBAHAN KELAS: DARING / ONLINE]

📚 Matkul   : {subject_name}
🎯 Target   : {target_group}
⏰ Jam      : {start_time} - {end_time} WIB
💻 Media    : Zoom Meeting / Google Meet
🔗 Link     : {meeting_url}
🔑 Passcode : {meeting_passcode}
📝 Catatan  : {reason}

Mahasiswa tidak perlu hadir fisik ke ruang kelas.
🔗 Cek jadwal lengkap: https://{app_domain}

```

### Template 3: Reschedule / Kelas Pengganti Baru

```text
⚠️ @everyone [PERUBAHAN JADWAL KULIAH/PRAKTIKUM]

📚 Matkul     : {subject_name}
🎯 Target     : {target_group}
📌 Status     : {status_label}

🗓 Tanggal Baru : {new_date}
⏰ Jam Baru     : {new_start_time} - {new_end_time} WIB
📍 Ruang Baru   : {new_room}
📝 Catatan      : {reason}

🔗 Cek kalender lengkap: https://{app_domain}

```

### Template 4: Pengingat H-1 Deadline Tugas (Pukul 19.00 WIB)

```text
📝 @everyone [REMINDER DEADLINE: H-1]

📚 Matkul   : {subject_name}
📌 Tugas    : {task_title}
⏰ Deadline : {deadline_formatted} WIB
📁 Format   : {submission_format}
🔗 Submit   : {submission_url}

Segera selesaikan dan kumpulkan sebelum link ditutup!

```

---

## 7. Deployment Configuration

### `docker-compose.yml`

```yaml
version: '3.8'

services:
  app:
    build:
      context: .
      dockerfile: Dockerfile
    container_name: class_schedule_app
    restart: unless-stopped
    ports:
      - "127.0.0.1:8000:80"
    environment:
      - APP_ENV=production
      - DB_CONNECTION=pgsql
      - DB_HOST=db
      - DB_PORT=5432
      - DB_DATABASE=${DB_DATABASE:-class_db}
      - DB_USERNAME=${DB_USERNAME:-postgres}
      - DB_PASSWORD=${DB_PASSWORD:-secret}
      - WAHA_BASE_URL=http://waha:3000
    depends_on:
      - db
      - waha
    networks:
      - class_network

  db:
    image: postgres:16-alpine
    container_name: class_schedule_db
    restart: unless-stopped
    environment:
      POSTGRES_DB: ${DB_DATABASE:-class_db}
      POSTGRES_USER: ${DB_USERNAME:-postgres}
      POSTGRES_PASSWORD: ${DB_PASSWORD:-secret}
    volumes:
      - pgdata:/var/lib/postgresql/data
    networks:
      - class_network

  waha:
    image: devlikeapro/waha
    container_name: class_schedule_waha
    restart: unless-stopped
    ports:
      - "127.0.0.1:3000:3000"
    environment:
      - WAHA_DASHBOARD_ENABLED=true
      - WAHA_DASHBOARD_USERNAME=${WAHA_USER:-admin}
      - WAHA_DASHBOARD_PASSWORD=${WAHA_PASS:-admin}
    volumes:
      - waha_sessions:/app/.sessions
    networks:
      - class_network

volumes:
  pgdata:
  waha_sessions:

networks:
  class_network:
    driver: bridge

```

---

## 8. UI/UX Wireframe & Screen Specifications

### 8.1 Theme & Design System

* **Style:** Modern Minimalist, Mobile-First PWA.
* **Color Palette:**
* Background: Slate 50 (Light) / Slate 950 (Dark).
* Primary: Blue/Indigo 600.
* Online Meeting: Purple 600 (Badge Zoom/Meet).
* Reschedule/Warning: Amber 500.
* Danger/Urgent: Rose 600 (Deadline < 24 jam / Batal).
* Success: Emerald 600 (Tugas selesai).


* **Typography:** Inter / Plus Jakarta Sans.

---

### 8.2 Screen 1: Auth & PIN Activation (Single Entry Flow)

```text
┌───────────────────────────────────────────────┐
│                    [ LOGO ]                   │
│             Portal Kelas & Lab TRI            │
│      Pantau Jadwal Kuliah, Lab & Tugas        │
├───────────────────────────────────────────────┤
│                                               │
│  Nomor Induk Mahasiswa (NIM)                  │
│  ┌─────────────────────────────────────────┐  │
│  │ 24/12345/SV/67890                     🔍│  │
│  └─────────────────────────────────────────┘  │
│                                               │
│  ─── [STATE A: AKUN BARU / AKTIVASI] ───────  │
│  👋 Halo, Muhammad Adib Muzakki!              │
│  Status: Mahasiswa (Kloter B2)                │
│                                               │
│  Buat PIN Baru (6 Digit Angka)                │
│  ┌─────────────────────────────────────────┐  │
│  │ [ • ] [ • ] [ • ] [ • ] [ • ] [ • ]     │  │
│  └─────────────────────────────────────────┘  │
│                                               │
│  Konfirmasi PIN Baru                          │
│  ┌─────────────────────────────────────────┐  │
│  │ [ • ] [ • ] [ • ] [ • ] [ • ] [ • ]     │  │
│  └─────────────────────────────────────────┘  │
│                                               │
│  ┌─────────────────────────────────────────┐  │
│  │         [ AKTIFKAN AKUN SAYA ]          │  │
│  └─────────────────────────────────────────┘  │
│                                               │
│  ─── [STATE B: AKUN SUDAH AKTIF] ──────────  │
│  👋 Selamat Datang Kembali, Adib!             │
│                                               │
│  Masukkan PIN                                 │
│  ┌─────────────────────────────────────────┐  │
│  │ [ • ] [ • ] [ • ] [ • ] [ • ] [ • ]     │  │
│  └─────────────────────────────────────────┘  │
│                                               │
│  ┌─────────────────────────────────────────┐  │
│  │               [ MASUK ]                 │  │
│  └─────────────────────────────────────────┘  │
│                                               │
│  Lupa PIN? Hubungi Komti Kelas                │
└───────────────────────────────────────────────┘

```

---

### 8.3 Screen 2: Student Main Dashboard (Home View)

Menampilkan jadwal offline, jadwal daring (Zoom), dan ringkasan tugas mendesak.

```text
┌───────────────────────────────────────────────┐
│ 👤 Adib Muzakki                      [🔔 2]   │
│ 🏷️ Kelas BB • Kloter B2             [⚙️ PIN] │
├───────────────────────────────────────────────┤
│ 🗓️ AGENDA HARI INI (Kamis, 27 Ags 2026)       │
│                                               │
│ ┌───────────────────────────────────────────┐ │
│ │ 🟣 DARING (ZOOM MEETING)          08.00   │ │
│ │ Pemrograman Web Lanjut (Teori BB)         │ │
│ │ 👨‍🏫 Dosen: Pak Budi                        │ │
│ │ 💬 Dosen berhalangan fisik, via Zoom      │ │
│ │ [ 🚀 Gabung Zoom Meeting (Pass: 1234) ↗ ] │ │
│ └───────────────────────────────────────────┘ │
│                                               │
│ ┌───────────────────────────────────────────┐ │
│ │ 🔴 RESCHEDULED                    13.15   │ │
│ │ Praktikum Jaringan Komputer               │ │
│ │ 📍 Lab Jaringan 2 (Gedung Vokasi Lt. 2)   │ │
│ │ 👨‍🏫 Aslab: Mas Rangga                     │ │
│ │ 💬 Catatan: Bawa modul & kabel LAN tester │ │
│ │ ⏱️ Dimulai dalam: 45 Menit Lagi           │ │
│ └───────────────────────────────────────────┘ │
├───────────────────────────────────────────────┤
│ 📌 TUGAS PRIORITAS (DEADLINE TERDEKAT)        │
│                                               │
│ ┌───────────────────────────────────────────┐ │
│ │ [ ] Modul 3: Routing OSPF                 │ │
│ │ 📚 Praktikum Jaringan (B2)                │ │
│ │ ⏳ Deadline: Malam Ini, 23.59 WIB         │ │
│ │ 📁 Format: NIM_Nama_Modul3.pdf            │ │
│ │ [ Submit ke Classroom ↗ ] [ Tandai Selesai]│
│ └───────────────────────────────────────────┘ │
├───────────────────────────────────────────────┤
│  [🏠 Home]      [📅 Jadwal]      [📝 Tugas]   │
└───────────────────────────────────────────────┘

```

---

### 8.4 Screen 3: Weekly Matrix Schedule (Tab Kalender)

```text
┌───────────────────────────────────────────────┐
│ 📅 Jadwal Kuliah & Praktikum                  │
│ [Sen]  [Sel]  [Rab]  [👉 Kam]  [Jum]  [Sab]   │
├───────────────────────────────────────────────┤
│ 📍 Kamis, 27 Agustus 2026                     │
│                                               │
│ 08.00 - 09.40 WIB                             │
│ ┌───────────────────────────────────────────┐ │
│ │ 🟣 [DARING] Pemrograman Web Lanjut        │ │
│ │ Zoom Meeting • Dosen: Pak Budi            │ │
│ │ [ Link Zoom ↗ ]                           │ │
│ └───────────────────────────────────────────┘ │
│                                               │
│ 13.15 - 15.45 WIB                             │
│ ┌───────────────────────────────────────────┐ │
│ │ 🔴 [RESCHEDULE - B2] Praktikum Jaringan   │ │
│ │ Lab Jaringan 2 • Aslab: Mas Rangga        │ │
│ │ <s>Jadwal Asli: Selasa 08.00 WIB</s>      │ │
│ └───────────────────────────────────────────┘ │
├───────────────────────────────────────────────┤
│  [🏠 Home]      [📅 Jadwal]      [📝 Tugas]   │
└───────────────────────────────────────────────┘

```

---

### 8.5 Screen 4: Task Tracker Matrix (Tab Tugas)

```text
┌───────────────────────────────────────────────┐
│ 📝 Daftar Tugas Kuliah                        │
│ [ 👉 Belum Selesai (3) ]   [ Selesai (8) ]    │
├───────────────────────────────────────────────┤
│                                               │
│ ┌───────────────────────────────────────────┐ │
│ │ 🔴 Sisa 8 Jam Lagi                        │ │
│ │ Laporan Akhir Modul 3 (Routing OSPF)      │ │
│ │ 📚 Praktikum Jaringan Komputer (Kloter B2)│ │
│ │ 📅 Deadline: 27 Ags 2026, 23.59 WIB       │ │
│ │ 🔗 [https://classroom.google.com/](https://classroom.google.com/)...       │ │
│ │                                           │ │
│ │ [✓ Tandai Sudah Selesai]                  │ │
│ └───────────────────────────────────────────┘ │
│                                               │
│ ┌───────────────────────────────────────────┐ │
│ │ 🟡 H-2 Deadline                           │ │
│ │ Mini Project: API Auth Sanctum            │ │
│ │ 📚 Pemrograman Web Lanjut (Semua Kelas)   │ │
│ │ 📅 Deadline: 29 Ags 2026, 23.59 WIB       │ │
│ │ [✓ Tandai Sudah Selesai]                  │ │
│ └───────────────────────────────────────────┘ │
├───────────────────────────────────────────────┤
│  [🏠 Home]      [📅 Jadwal]      [📝 Tugas]   │
└───────────────────────────────────────────────┘

```

---

### 8.6 Screen 5: PJ Emergency / Reschedule Action Modal

Form cepat untuk mengalihkan kelas ke daring atau melakukan reschedule mendadak.

```text
┌───────────────────────────────────────────────┐
│ ✕ Kelola Jadwal / Emergency Override          │
├───────────────────────────────────────────────┤
│ Mata Kuliah                                   │
│ [ Pemrograman Web Lanjut (Teori BB)        ▼]│
│                                               │
│ Kondisi / Status Pertemuan                    │
│ ( ) Sesuai Jadwal                             │
│ (•) Dosen Berhalangan (Ganti Online / Zoom)   │
│ ( ) Reschedule (Pindah Jam / Ruang)           │
│ ( ) Dibatalkan (Ditiadakan)                   │
│                                               │
│ ─── [KONTEN: JIKA DIPILIH ONLINE / ZOOM] ───  │
│ Link Pertemuan (Zoom / Google Meet)           │
│ [ [https://ugm-id.zoom.us/j/9876543210](https://ugm-id.zoom.us/j/9876543210)       ] │
│                                               │
│ Passcode Meeting (Opsional)                   │
│ [ 123456                                    ] │
│                                               │
│ Catatan / Alasan Dosen                        │
│ [ Dosen bertugas ke luar kota, kuliah via   │
│   Zoom meeting. Wajib on-cam.               ] │
│                                               │
│ ───────────────────────────────────────────── │
│ 📲 Notifikasi WhatsApp                        │
│ [✓] Kirim Blast Instan ke Grup BB             │
│ [✓] Tag Semua Member (@everyone)              │
│                                               │
│ ┌───────────────────────────────────────────┐ │
│ │        [ SIMPAN & BLAST NOTIFIKASI ]      │ │
│ └───────────────────────────────────────────┘ │
```
