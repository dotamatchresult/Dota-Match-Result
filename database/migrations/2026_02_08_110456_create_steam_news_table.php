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
        Schema::create('steam_news', function (Blueprint $table) {
            $table->id();
            $table->string('gid')->unique();
            $table->string('title');
            $table->text('url');
            $table->string('author')->nullable();
            $table->text('contents');
            $table->string('feedname');
            $table->timestamp('published_at');
            $table->timestamp('notified_at')->nullable();
            $table->json('tags')->nullable();
            $table->timestamps();

            $table->index('gid');
            $table->index('published_at');
            $table->index('notified_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('steam_news');
    }
};
