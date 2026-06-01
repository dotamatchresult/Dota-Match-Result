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
        Schema::table('dota_matches', function (Blueprint $table) {
            $table->enum('parse_status', ['pending', 'parsing', 'parsed', 'failed'])->nullable()->after('notified_at');
            $table->string('parse_job_id')->nullable()->after('parse_status');
            $table->timestamp('parse_requested_at')->nullable()->after('parse_job_id');
            $table->timestamp('parse_completed_at')->nullable()->after('parse_requested_at');
            $table->integer('parse_retry_count')->default(0)->after('parse_completed_at');
            $table->text('ai_analysis')->nullable()->after('parse_retry_count');
            $table->timestamp('ai_analyzed_at')->nullable()->after('ai_analysis');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dota_matches', function (Blueprint $table) {
            $table->dropColumn([
                'parse_status',
                'parse_job_id',
                'parse_requested_at',
                'parse_completed_at',
                'parse_retry_count',
                'ai_analysis',
                'ai_analyzed_at',
            ]);
        });
    }
};
