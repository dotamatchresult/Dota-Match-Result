<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HeroFacet extends Model
{
    /** @use HasFactory<\Database\Factories\HeroFacetFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'hero_npc_name',
        'title',
        'icon',
        'color',
    ];
}
