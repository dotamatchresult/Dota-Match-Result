<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Item extends Model
{
    /** @use HasFactory<\Database\Factories\ItemFactory> */
    use HasFactory;

    protected $fillable = [
        'item_id',
        'name',
        'dname',
        'cost',
        'img',
    ];

    protected function casts(): array
    {
        return [
            'item_id' => 'integer',
            'cost' => 'integer',
        ];
    }
}
