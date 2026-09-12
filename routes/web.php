<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes — Sistem Kuliah (mode pure API)
|--------------------------------------------------------------------------
| Backend melayani JSON via /api/*. Frontend adalah HTML statis di
| public/app/ yang memanggil API dengan cookie session (same-origin).
*/

Route::get('/', fn () => redirect()->to('/app/index.html'));

// Halaman frontend statis (URL cantik tanpa .html)
Route::get('/login', fn () => redirect()->to('/app/index.html'))->name('login');
Route::get('/dashboard', fn () => redirect()->to('/app/dashboard.html'))->name('dashboard');
Route::get('/app/{path}', function (string $path) {
    abort_if(! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]*\.html$/', $path), 404);
    abort_if(str_contains($path, '..'), 404);

    $file = public_path('app/' . $path);
    abort_unless(is_file($file), 404);

    return response()->file($file, ['Content-Type' => 'text/html; charset=UTF-8']);
})->where('path', '[a-zA-Z0-9][a-zA-Z0-9._-]*\.html');
