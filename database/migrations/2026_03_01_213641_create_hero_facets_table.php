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
        Schema::create('hero_facets', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();         // Facet key, e.g. "antimage_magebanes_mirror"
            $table->string('hero_npc_name');          // e.g. "npc_dota_hero_antimage"
            $table->string('title');                  // Display title
            $table->string('icon')->nullable();
            $table->string('color')->nullable();
            $table->timestamps();

            $table->index('hero_npc_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hero_facets');
    }
};
