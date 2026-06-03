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
        Schema::create('challenge_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('destination_challenge_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('status')->default('pending');
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['destination_challenge_id', 'type', 'status'], 'cn_dest_chal_type_status_idx');
            $table->index(['status', 'scheduled_at'], 'cn_status_scheduled_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('challenge_notifications');
    }
};
