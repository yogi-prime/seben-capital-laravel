<?php

// database/migrations/2025_01_01_000001_alter_posts_wordcount_default.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            // unsigned integer with default 0 (adjust type to your schema)
            $table->unsignedInteger('word_count')->default(0)->change();
            // optional: make read_time nullable
            $table->string('read_time')->nullable()->change();
        });
    }
    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            // rollback if needed (remove default)
            $table->unsignedInteger('word_count')->change();
            $table->string('read_time')->change();
        });
    }
};
