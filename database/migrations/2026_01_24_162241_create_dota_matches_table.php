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
        Schema::create('dota_matches', function (Blueprint $table) {
            $table->id();
            $table->string('match_id')->unique();
            $table->timestamp('match_timestamp')->nullable();
            $table->json('match_data');
            $table->json('members');
            $table->timestamp('notified_at')->nullable();
            $table->integer('resend_count')->default(0);
            $table->timestamp('last_resent_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dota_matches');
    }
};
