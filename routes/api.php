<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ScheduleController;
use App\Http\Controllers\Api\TaskController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — Sistem Kuliah
|--------------------------------------------------------------------------
| Semua endpoint JSON murni. Autentikasi via cookie session Laravel
| (frontend statis di-serve dari origin yang sama oleh Laravel).
| Frontend harus mengirim header Accept: application/json pada semua
| request agar error validasi/401 ter-render sebagai JSON, bukan redirect.
*/

// ---------- Publik (guest) ----------
Route::middleware('guest')->group(function () {
    // Deteksi NIU: belum aktivasi / siap login
    Route::post('/auth/check-niu', [AuthController::class, 'checkNiu'])->name('api.auth.check-niu');
    // Aktivasi PIN pertama kali (akun baru)
    Route::post('/auth/activate', [AuthController::class, 'activatePin'])->name('api.auth.activate');
    // Pendaftaran mandiri akun mahasiswa (role STUDENT, non-aktif sampai PIN dibuat)
    Route::post('/auth/register', [AuthController::class, 'register'])->name('api.auth.register');
    // Login NIU + PIN
    Route::post('/auth/login', [AuthController::class, 'login'])->name('api.auth.login');
});

// ---------- Terotentikasi ----------
Route::middleware('auth')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('api.auth.logout');
    Route::get('/me', [AuthController::class, 'me'])->name('api.me');

    // Beranda: jadwal hari ini (termasuk override) + tugas prioritas
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('api.dashboard');

    // Jadwal mingguan + kelas pengganti
    Route::get('/schedules', [ScheduleController::class, 'index'])->name('api.schedules.index');

    // PJ/Admin: kelola jadwal master
    Route::post('/schedules', [ScheduleController::class, 'store'])->middleware('pj')->name('api.schedules.store');
    Route::put('/schedules/{schedule}', [ScheduleController::class, 'update'])->middleware('pj')->name('api.schedules.update');
    Route::delete('/schedules/{schedule}', [ScheduleController::class, 'destroy'])->middleware('pj')->name('api.schedules.destroy');

    // PJ/Admin: override (daring / reschedule / batal) per tanggal
    Route::post('/schedules/override', [ScheduleController::class, 'storeOverride'])->name('api.schedules.override');

    // Tugas
    Route::get('/tasks', [TaskController::class, 'index'])->name('api.tasks.index');
    Route::post('/tasks', [TaskController::class, 'store'])->middleware('pj')->name('api.tasks.store');
    Route::post('/tasks/{task}/toggle', [TaskController::class, 'toggleComplete'])->name('api.tasks.toggle');

    // Admin panel
    Route::get('/admin', [AdminController::class, 'index'])->middleware('admin')->name('api.admin.index');
    Route::post('/admin/subjects', [AdminController::class, 'storeSubject'])->middleware('admin')->name('api.admin.subjects.store');
    Route::put('/admin/subjects/{subject}', [AdminController::class, 'updateSubject'])->middleware('admin')->name('api.admin.subjects.update');
    Route::delete('/admin/subjects/{subject}', [AdminController::class, 'destroySubject'])->middleware('admin')->name('api.admin.subjects.destroy');
    Route::post('/admin/users', [AdminController::class, 'storeUser'])->middleware('admin')->name('api.admin.users.store');
    Route::put('/admin/users/{user}', [AdminController::class, 'updateUser'])->middleware('admin')->name('api.admin.users.update');
    Route::delete('/admin/users/{user}', [AdminController::class, 'deleteUser'])->middleware('admin')->name('api.admin.users.destroy');
    Route::post('/admin/users/reset-pin', [AdminController::class, 'resetPin'])->middleware('admin')->name('api.admin.users.reset-pin');
    Route::get('/admin/waha/groups', [AdminController::class, 'fetchWahaGroups'])->middleware('admin')->name('api.admin.waha.groups');
    Route::post('/admin/waha/settings', [AdminController::class, 'updateWahaSettings'])->middleware('admin')->name('api.admin.waha.settings');
    Route::post('/admin/waha/test-blast', [AdminController::class, 'testBlast'])->middleware('admin')->name('api.admin.waha.test-blast');
});
