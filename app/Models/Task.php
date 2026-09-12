<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Task extends Model
{
    use HasFactory;

    protected $fillable = [
        'subject_id',
        'target_group',
        'title',
        'description',
        'deadline',
        'submission_url',
        'submission_format',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'deadline' => 'datetime',
        ];
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function completions(): HasMany
    {
        return $this->hasMany(UserTaskCompletion::class, 'task_id');
    }

    public function isCompletedByUser(int $userId): bool
    {
        return $this->completions()->where('user_id', $userId)->where('is_completed', true)->exists();
    }
}
