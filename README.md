# Sistem Kuliah — Schedule & Task Reminder

Sistem manajemen jadwal kuliah dan tugas untuk Program Studi Teknologi Rekayasa Internet (TRI), dilengkapi notifikasi otomatis via WhatsApp (WAHA) ke grup kelas.

## Fitur

- **Autentikasi PIN** — login menggunakan NIU + PIN 6 digit (tanpa email/password)
- **Aktivasi akun** — mahasiswa baru mengaktifkan akun dengan PIN sendiri
- **Jadwal kuliah mingguan** — master jadwal per mata kuliah, grup teori & praktikum (kloter B1/B2)
- **Schedule Override** — penggantian kelas (daring/Zoom, libur, ganti ruang/waktu) dengan notifikasi
- **Manajemen tugas** — deadline, format pengumpulan, link submission, countdown timer
- **Checklist tugas personal** — mahasiswa menandai tugas yang sudah selesai
- **Notifikasi WhatsApp** — reminder jadwal & tugas via WAHA ke grup kelas
- **Panel Admin** — kelola user, mata kuliah, jadwal, tugas, grup WAHA, dan konfigurasi WAHA

## Teknologi

| Komponen | Teknologi |
|---|---|
| Backend | Laravel 13, PHP 8.3 |
| Frontend | React 19 + Inertia.js, Tailwind CSS 4, Vite |
| Database | MySQL (produksi) / SQLite (lokal) |
| Notifikasi | WAHA (WhatsApp HTTP API) |
| Testing | PHPUnit |

## Instalasi Lokal (Laragon / XAMPP)

### 1. Clone & dependensi

```bash
git clone https://github.com/Muzakie-ID/sistem-kuliah-redesaign.git
cd sistem-kuliah-redesaign

composer install
npm install
```

### 2. Konfigurasi environment

```bash
cp .env.example .env
php artisan key:generate
```

Secara default `.env.example` memakai **SQLite** (tanpa setup tambahan). Untuk MySQL, ubah di `.env`:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=sistem_kuliah
DB_USERNAME=root
DB_PASSWORD=
```

### 3. Migrasi & seed

```bash
php artisan migrate --seed
```

Atau dari awal bersih:

```bash
php artisan migrate:fresh --seed
```

### 4. Jalankan aplikasi

```bash
# Terminal 1 — backend
php artisan serve

# Terminal 2 — frontend (hot reload)
npm run dev
```

Buka `http://localhost:8000`.

Untuk build produksi:

```bash
npm run build
```

## Instalasi via Docker

```bash
# Buat network eksternal (sekali saja)
docker network create webnet

# Build & jalankan
docker compose up -d --build

# Migrasi + seed di dalam container
docker compose exec app php artisan migrate --seed
```

Aplikasi berjalan di `http://127.0.0.1:8000`.

Variabel environment Docker (opsional, via file `.env` di root):

```env
DB_DATABASE=app
DB_USERNAME=app
DB_PASSWORD=app123
WAHA_BASE_URL=https://waha.domainkamu.com
WAHA_API_KEY=secret
```

## Akun Seeder

Setelah `php artisan migrate --seed`, login dengan NIU + PIN `123456`:

| NIU | Nama | Role | Kloter | Keterangan |
|---|---|---|---|---|
| `00001` | Komti Triyono | ADMIN | B1 | Super Admin |
| `11111` | Ahmad Fauzi | PJ | B1 | PJ Pemrograman Web Lanjut |
| `22222` | Siti Nurhaliza | PJ | B2 | PJ Praktikum Jaringan |
| `53411` | Muhammad Adib Muzakki | STUDENT | B1 | Mahasiswa aktif |
| `53412` | Fajar Pratama | STUDENT | B2 | **Belum aktivasi** (uji alur aktivasi PIN) |
| `53413` | Rizky Ramadhan | STUDENT | B2 | Mahasiswa aktif |

Seeder juga membuat: 3 konfigurasi grup WAHA, 5 mata kuliah, 7 jadwal mingguan, 1 contoh schedule override (kelas Web Lanjut daring via Zoom), 3 tugas, dan 1 contoh checklist tugas selesai.

## Konfigurasi WAHA (WhatsApp)

Notifikasi dikirim melalui [WAHA](https://waha.devlike.pro/). Konfigurasi dilakukan dari **Panel Admin** (tersimpan di tabel `app_settings`, tidak perlu edit `.env`):

1. Login sebagai Admin (`00001`)
2. Buka menu **Admin** → bagian konfigurasi WAHA
3. Isi:
   - **WAHA Base URL** — mis. `http://localhost:3000` (default) atau domain WAHA Anda
   - **WAHA API Key** — kosongkan jika WAHA tanpa API key
4. Daftarkan grup WhatsApp (group JID format `120363...@g.us`) di menu grup WAHA

Fallback konfigurasi via `.env` (jika belum diatur dari panel admin):

```env
WAHA_BASE_URL=http://localhost:3000
WAHA_API_KEY=
```

## Perintah Artisan

```bash
php artisan reminder:schedule   # Kirim reminder jadwal hari ini ke grup WAHA
php artisan reminder:tasks      # Kirim reminder tugas mendekati deadline
```

Kedua perintah juga dijadwalkan otomatis via scheduler (`routes/console.php`). Di produksi, pastikan cron berjalan:

```
* * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1
```

## Testing

```bash
composer test
# atau
php artisan test
```

## Struktur Proyek

```
app/
├── Console/Commands/       # Perintah reminder (jadwal & tugas)
├── Http/Controllers/       # Auth, Dashboard, Schedule, Task, Admin
├── Models/                 # User, Subject, Schedule, Task, dll.
└── Services/
    └── WahaService.php     # Integrasi WAHA (kirim pesan grup)
database/
├── migrations/             # Skema tabel sistem akademik
└── seeders/                # Data contoh (user, jadwal, tugas)
resources/js/
├── Components/             # Badge, CountdownTimer, Modal, dll.
├── Layouts/                # AuthenticatedLayout
└── Pages/                  # Dashboard, Schedules, Tasks, Admin, Auth
routes/
├── web.php                 # Route web (Inertia)
└── console.php             # Scheduler & perintah artisan
```

## Lisensi

MIT
