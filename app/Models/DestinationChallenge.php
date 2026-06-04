<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DestinationChallenge extends Model
{
    /** @use HasFactory<\Database\Factories\DestinationChallengeFactory> */
    use HasFactory;

    protected $fillable = [
        'destination_id',
        'challenge_id',
        'assigned_date',
        'status',
        'current_requirement',
        'current_progress',
        'progress_data',
        'failed_days',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'assigned_date' => 'date',
            'progress_data' => 'array',
            'completed_at' => 'datetime',
            'current_requirement' => 'integer',
            'current_progress' => 'integer',
            'failed_days' => 'integer',
        ];
    }

    public function challenge(): BelongsTo
    {
        return $this->belongsTo(Challenge::class);
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ChallengeEvent::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(ChallengeNotification::class);
    }
}
