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
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->integer('item_id')->unique();
            $table->string('name');                 // API key, e.g. "blink"
            $table->string('dname')->nullable();    // Display name, e.g. "Blink Dagger"
            $table->unsignedInteger('cost')->nullable();
            $table->string('img')->nullable();
            $table->timestamps();

            $table->index('item_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
