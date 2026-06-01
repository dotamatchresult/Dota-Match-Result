<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SteamNews extends Model
{
    /** @use HasFactory<\Database\Factories\SteamNewsFactory> */
    use HasFactory;

    protected $fillable = [
        'gid',
        'title',
        'url',
        'author',
        'contents',
        'feedname',
        'published_at',
        'notified_at',
        'tags',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'notified_at' => 'datetime',
            'tags' => 'array',
        ];
    }
}
