<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'niu',
        'name',
        'pin_hash',
        'role',
        'theory_class',
        'practicum_group',
        'is_active',
    ];

    protected $hidden = [
        'pin_hash',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function getAuthPassword(): ?string
    {
        return $this->pin_hash;
    }

    public function taskCompletions(): HasMany
    {
        return $this->hasMany(UserTaskCompletion::class, 'user_id');
    }

    public function isAdmin(): bool
    {
        return $this->role === 'ADMIN';
    }

    /**
     * Akun siap dipakai login: sudah aktivasi PIN dan aktif.
     */
    public function isUsable(): bool
    {
        return $this->pin_hash !== null && $this->is_active;
    }

    /**
     * Target group WAHA untuk kelas teori milik user ini (BB_THEORY / AA_THEORY).
     */
    public function theoryTarget(): string
    {
        return $this->theory_class.'_THEORY';
    }

    /**
     * Target group WAHA untuk kloter praktikum milik user ini (B1_PRACTICUM / A2_PRACTICUM / ...).
     */
    public function practicumTarget(): string
    {
        return $this->practicum_group.'_PRACTICUM';
    }

    /**
     * Semua target group jadwal/tugas yang boleh dilihat user ini.
     */
    public function allowedTargets(): array
    {
        return [$this->theoryTarget(), $this->practicumTarget()];
    }

    public function isPj(): bool
    {
        return $this->role === 'PJ' || $this->role === 'ADMIN';
    }

    public function isStudent(): bool
    {
        return $this->role === 'STUDENT';
    }

    /**
     * Kelas (target group) yang boleh dikelola user.
     * PJ adalah mahasiswa → kelas yang ia kelola turunan kelasnya sendiri:
     * teori = theory_class-nya, praktikum = practicum_group-nya.
     * Admin (ketua kelas) bebas di semua kelas.
     */
    public function managedTargets(): array
    {
        if ($this->isAdmin()) {
            return ['BB_THEORY', 'AA_THEORY', 'B1_PRACTICUM', 'B2_PRACTICUM', 'A1_PRACTICUM', 'A2_PRACTICUM'];
        }

        return [$this->theoryTarget(), $this->practicumTarget()];
    }

    public function canManageTarget(string $targetGroup): bool
    {
        return in_array($targetGroup, $this->managedTargets(), true);
    }

    /**
     * Mata kuliah yang boleh dikelola user.
     * PJ Kelas & Admin mengelola SEMUA mata kuliah — batasannya ada di kelas
     * (lihat managedTargets), bukan di mata kuliah.
     */
    public function manageableSubjects()
    {
        return Subject::orderBy('name')->get(['id', 'code', 'name', 'type']);
    }
}
