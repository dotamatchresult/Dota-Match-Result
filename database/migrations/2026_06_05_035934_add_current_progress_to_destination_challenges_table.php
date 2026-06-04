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
        Schema::table('destination_challenges', function (Blueprint $table) {
            $table->unsignedInteger('current_progress')->default(0)->after('current_requirement')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('destination_challenges', function (Blueprint $table) {
            $table->dropIndex(['current_progress']);
            $table->dropColumn('current_progress');
        });
    }
};
