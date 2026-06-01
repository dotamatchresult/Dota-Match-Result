<?php

namespace App\Models;

use App\Enums\DestinationType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Member extends Model
{
    /** @use HasFactory<\Database\Factories\MemberFactory> */
    use HasFactory;

    protected $fillable = [
        'steam_id',
        'name',
        'destination',
    ];

    protected $appends = [
        'platform',
    ];

    protected function casts(): array
    {
        return [
            'steam_id' => 'string',
            'destination' => DestinationType::class,
        ];
    }

    /**
     * Get the platform attribute with fallback to WhatsApp
     */
    public function getPlatformAttribute(): string
    {
        return $this->destination?->value ?? DestinationType::WhatsApp->value;
    }

    public function destinationConfig(): BelongsTo
    {
        return $this->belongsTo(Destination::class, 'destination', 'code');
    }

    public function reminder(): BelongsTo
    {
        return $this->belongsTo(Reminder::class, 'steam_id', 'steam_id');
    }

    /**
     * Revert steam ID to 32-bit format
     */
    public static function convertAccountIdToSteamId(int $steamId): string
    {
        if (! $steamId) {
            return 0;
        }

        return bcadd($steamId, '76561197960265728');
    }

    /**
     * Revert steam ID to 32-bit format
     */
    public static function convertSteamIdToAccountId(string $steamId): int
    {
        if (! $steamId) {
            return 0;
        }

        return (int) bcsub($steamId, '76561197960265728');
    }
}
