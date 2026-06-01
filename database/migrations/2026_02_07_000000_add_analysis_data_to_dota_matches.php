<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dota_matches', function (Blueprint $table) {
            $table->json('analysis_data')->nullable()->after('ai_analysis');
        });
    }

    public function down(): void
    {
        Schema::table('dota_matches', function (Blueprint $table) {
            $table->dropColumn('analysis_data');
        });
    }
};
