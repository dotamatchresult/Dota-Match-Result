<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Reminder extends Model
{
    /** @use HasFactory<\Database\Factories\ReminderFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'steam_id',
        'max_matches',
    ];

    protected function casts(): array
    {
        return [
            'steam_id' => 'string',
            'max_matches' => 'integer',
        ];
    }

    public function members(): HasMany
    {
        return $this->hasMany(Member::class, 'steam_id', 'steam_id');
    }
}
