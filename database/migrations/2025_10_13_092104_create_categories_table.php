<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('categories', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug')->unique();
            $t->string('seo_title')->nullable();
            $t->string('seo_description', 500)->nullable();
            $t->string('canonical_url')->nullable();
            $t->json('og_data')->nullable();        // {"title":"","description":"","image":""}
            $t->json('twitter_data')->nullable();   // {"card":"summary_large_image","image":""}
            $t->json('schema_json')->nullable();    // JSON-LD if any
            $t->unsignedInteger('order_column')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();

            // Fast lookup
            $t->index(['is_active', 'order_column']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('categories');
    }
};
