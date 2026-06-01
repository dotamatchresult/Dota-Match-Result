<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HeroAbility extends Model
{
    /** @use HasFactory<\Database\Factories\HeroAbilityFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'hero_npc_name',
    ];
}
