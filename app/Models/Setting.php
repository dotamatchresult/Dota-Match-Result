<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;

class Setting extends Model
{
    /** @use HasFactory<\Database\Factories\SettingFactory> */
    use HasFactory;

    protected $fillable = [
        'key',
        'value',
        'type',
        'label',
        'description',
    ];

    protected static function booted(): void
    {
        static::saved(function (Setting $setting) {
            static::syncDestinationFromLegacySetting($setting->key, $setting->value);
            Cache::forget("setting.{$setting->key}");
        });

        static::deleted(function (Setting $setting) {
            Cache::forget("setting.{$setting->key}");
        });
    }

    /**
     * Get a setting value by key
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::remember("setting.{$key}", 3600 * 24, function () use ($key, $default) {
            $setting = static::where('key', $key)->first();

            if (! $setting) {
                return $default;
            }

            // Cast datetime values
            if ($setting->type === 'datetime' && $setting->value) {
                return Date::parse($setting->value);
            }

            return $setting->value ?? $default;
        });
    }

    /**
     * Set a setting value by key
     */
    public static function set(string $key, mixed $value): void
    {
        static::updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );

        static::syncDestinationFromLegacySetting($key, $value);

        Cache::forget("setting.{$key}");
    }

    private static function syncDestinationFromLegacySetting(string $key, mixed $value): void
    {
        $legacyMap = [
            'fonnte_api_key' => [
                'code' => Destination::CODE_WHATSAPP,
                'name' => 'WhatsApp',
                'field' => 'main_bot_token',
            ],
            'fonnte_phone_number' => [
                'code' => Destination::CODE_WHATSAPP,
                'name' => 'WhatsApp',
                'field' => 'target',
            ],
            'telegram_bot_token' => [
                'code' => Destination::CODE_TELEGRAM,
                'name' => 'Telegram',
                'field' => 'main_bot_token',
            ],
            'telegram_bot_ai_token' => [
                'code' => Destination::CODE_TELEGRAM,
                'name' => 'Telegram',
                'field' => 'ai_bot_token',
            ],
            'telegram_group_id' => [
                'code' => Destination::CODE_TELEGRAM,
                'name' => 'Telegram',
                'field' => 'target',
            ],
        ];

        $mapping = $legacyMap[$key] ?? null;

        if (! $mapping) {
            return;
        }

        Destination::query()->updateOrCreate(
            ['code' => $mapping['code']],
            [
                'name' => $mapping['name'],
                $mapping['field'] => $value,
            ]
        );
    }

    /**
     * Clear the settings cache
     */
    public static function clearCache(): void
    {
        Cache::flush();
    }
}
