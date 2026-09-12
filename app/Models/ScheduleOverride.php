<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduleOverride extends Model
{
    use HasFactory;

    protected $fillable = [
        'schedule_id',
        'original_date',
        'status',
        'new_date',
        'new_start_time',
        'new_end_time',
        'new_room',
        'meeting_url',
        'meeting_passcode',
        'reason',
        'is_notified',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'original_date' => 'date',
            'new_date' => 'date',
            'is_notified' => 'boolean',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class, 'schedule_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
