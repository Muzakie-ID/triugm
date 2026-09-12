<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppSetting extends Model
{
    protected $fillable = [
        'setting_key',
        'setting_value',
    ];

    public static function get(string $key, ?string $default = null): ?string
    {
        try {
            $setting = static::where('setting_key', $key)->first();

            return $setting && $setting->setting_value !== null ? $setting->setting_value : $default;
        } catch (\Throwable $e) {
            return $default;
        }
    }

    public static function set(string $key, ?string $value): self
    {
        return static::updateOrCreate(
            ['setting_key' => $key],
            ['setting_value' => $value]
        );
    }
}
