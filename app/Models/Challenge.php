<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Challenge extends Model
{
    /** @use HasFactory<\Database\Factories\ChallengeFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'description',
        'category',
        'base_requirement',
        'increment_value',
        'max_requirement',
        'configuration',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'configuration' => 'array',
            'is_active' => 'boolean',
            'base_requirement' => 'integer',
            'increment_value' => 'integer',
            'max_requirement' => 'integer',
        ];
    }

    public function destinationChallenges(): HasMany
    {
        return $this->hasMany(DestinationChallenge::class);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<Challenge>  $query
     */
    public function scopeActive($query): void
    {
        $query->where('is_active', true);
    }
}
