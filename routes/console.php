<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

use Illuminate\Support\Facades\Schedule;

// Pengingat H-15 Menit Mulai Kuliah / Praktikum (Cron setiap menit)
Schedule::command('reminder:schedule')
    ->everyMinute()
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping();

// Pengingat H-1 Tenggat Waktu Tugas Kuliah (Pukul 19.00 WIB)
Schedule::command('reminder:tasks')
    ->dailyAt('19:00')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping();
