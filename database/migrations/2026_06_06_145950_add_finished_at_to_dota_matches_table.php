<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('dota_matches', function (Blueprint $table) {
            $table->timestamp('finished_at')->nullable()->after('match_timestamp');
        });

        // Backfill finished_at = match_timestamp + duration (seconds)
        // Duration is stored in match_data JSON as "duration" in seconds
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement(<<<'SQL'
                UPDATE dota_matches
                SET finished_at = DATE_ADD(
                    match_timestamp,
                    INTERVAL JSON_UNQUOTE(JSON_EXTRACT(match_data, '$.duration')) SECOND
                )
                WHERE match_timestamp IS NOT NULL
                  AND JSON_EXTRACT(match_data, '$.duration') IS NOT NULL
            SQL);
        } else {
            // SQLite: duration as integer seconds stored in JSON
            // match_timestamp is stored as ISO datetime string in SQLite
            DB::statement(<<<'SQL'
                UPDATE dota_matches
                SET finished_at = datetime(
                    match_timestamp,
                    '+' || CAST(JSON_EXTRACT(match_data, '$.duration') AS INTEGER) || ' seconds'
                )
                WHERE match_timestamp IS NOT NULL
                  AND JSON_EXTRACT(match_data, '$.duration') IS NOT NULL
            SQL);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dota_matches', function (Blueprint $table) {
            $table->dropColumn('finished_at');
        });
    }
};
