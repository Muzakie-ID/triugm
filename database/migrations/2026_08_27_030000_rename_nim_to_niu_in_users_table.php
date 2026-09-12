<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rename kolom `nim` menjadi `niu` (Nomor Induk Universitas)
     * dan perpendek format identitas: 24/53412/SV/23002 -> 53412.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('niu', 30)->unique()->after('id');
        });

        // Salin data: ambil segmen tengah dari NIM lama (24/53412/SV/23002 -> 53412)
        DB::table('users')->update([
            'niu' => DB::raw("substring_index(substring_index(nim, '/', 2), '/', -1)"),
        ]);

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('nim');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('nim', 30)->unique()->after('id');
        });

        DB::table('users')->update([
            'nim' => DB::raw("concat('24/', niu, '/SV/', niu)"),
        ]);

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('niu');
        });
    }
};
