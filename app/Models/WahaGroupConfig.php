<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WahaGroupConfig extends Model
{
    use HasFactory;

    protected $fillable = [
        'target_group',
        'group_jid',
        'group_name',
    ];

    public static function getJidForTarget(string $targetGroup): ?string
    {
        return static::where('target_group', $targetGroup)->value('group_jid');
    }
}
