<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Hero extends Model
{
    /** @use HasFactory<\Database\Factories\HeroFactory> */
    use HasFactory;

    protected $fillable = [
        'hero_id',
        'name',
        'localized_name',
    ];

    protected function casts(): array
    {
        return [
            'hero_id' => 'integer',
        ];
    }
}
