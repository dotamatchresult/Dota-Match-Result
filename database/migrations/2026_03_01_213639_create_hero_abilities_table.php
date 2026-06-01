<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('hero_abilities', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();          // Ability key, e.g. "antimage_mana_break"
            $table->string('hero_npc_name');           // e.g. "npc_dota_hero_antimage"
            $table->timestamps();

            $table->index('hero_npc_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hero_abilities');
    }
};
