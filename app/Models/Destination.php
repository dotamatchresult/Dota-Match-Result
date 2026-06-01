<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Destination extends Model
{
    /** @use HasFactory<\Database\Factories\DestinationFactory> */
    use HasFactory;

    use SoftDeletes;

    public const CODE_WHATSAPP = 'whatsapp';

    public const CODE_TELEGRAM = 'telegram';

    protected $fillable = [
        'code',
        'name',
        'main_bot_token',
        'ai_bot_token',
        'target',
    ];

    protected function casts(): array
    {
        return [
            'code' => 'string',
            'name' => 'string',
            'main_bot_token' => 'string',
            'ai_bot_token' => 'string',
            'target' => 'string',
        ];
    }

    public static function forCode(string $code): ?self
    {
        return static::query()->where('code', $code)->first();
    }

    public static function targetForCode(string $code): ?string
    {
        return static::forCode($code)?->target;
    }

    public static function mainTokenForCode(string $code): ?string
    {
        return static::forCode($code)?->main_bot_token;
    }

    public static function aiTokenForCode(string $code): ?string
    {
        return static::forCode($code)?->ai_bot_token;
    }
}
