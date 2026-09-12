<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kolom completed_at awalnya NOT NULL (useCurrent), padahal
     * TaskController@toggleComplete meng-set null saat tugas
     * dikembalikan ke "belum selesai". Jadikan nullable.
     */
    public function up(): void
    {
        Schema::table('user_task_completions', function (Blueprint $table) {
            $table->timestamp('completed_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('user_task_completions', function (Blueprint $table) {
            $table->timestamp('completed_at')->useCurrent()->change();
        });
    }
};
