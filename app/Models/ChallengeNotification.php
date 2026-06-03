<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChallengeNotification extends Model
{
    /** @use HasFactory<\Database\Factories\ChallengeNotificationFactory> */
    use HasFactory;

    protected $fillable = [
        'destination_challenge_id',
        'type',
        'scheduled_at',
        'sent_at',
        'status',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'sent_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    public function destinationChallenge(): BelongsTo
    {
        return $this->belongsTo(DestinationChallenge::class);
    }
}
