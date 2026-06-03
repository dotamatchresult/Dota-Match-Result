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
        Schema::create('challenge_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('destination_challenge_id')->constrained()->cascadeOnDelete();
            $table->foreignId('match_id')->nullable()->constrained('dota_matches')->nullOnDelete();
            $table->string('type');
            $table->integer('value_before')->nullable();
            $table->integer('value_after')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['destination_challenge_id', 'type'], 'ce_dest_chal_type_idx');
            $table->index(['match_id', 'destination_challenge_id'], 'ce_match_chal_idx');
            $table->unique(
                ['destination_challenge_id', 'match_id', 'type'],
                'ce_chal_match_type_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('challenge_events');
    }
};
