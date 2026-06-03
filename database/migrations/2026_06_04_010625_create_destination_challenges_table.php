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
        Schema::create('destination_challenges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('destination_id')->constrained()->cascadeOnDelete();
            $table->foreignId('challenge_id')->constrained()->cascadeOnDelete();
            $table->date('assigned_date');
            $table->string('status')->default('active');
            $table->unsignedInteger('current_requirement');
            $table->json('progress_data')->nullable();
            $table->unsignedInteger('failed_days')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['destination_id', 'challenge_id', 'assigned_date'], 'dc_dest_chal_date_unique');
            $table->index(['destination_id', 'status'], 'dc_dest_status_idx');
            $table->index('status', 'dc_status_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('destination_challenges');
    }
};
