<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChallengeEvent extends Model
{
    /** @use HasFactory<\Database\Factories\ChallengeEventFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'destination_challenge_id',
        'match_id',
        'type',
        'value_before',
        'value_after',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'value_before' => 'integer',
            'value_after' => 'integer',
        ];
    }

    public function destinationChallenge(): BelongsTo
    {
        return $this->belongsTo(DestinationChallenge::class);
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(DotaMatch::class, 'match_id');
    }
}
