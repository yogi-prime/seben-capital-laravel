<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('posts', function (Blueprint $t) {
            $t->id();

            // Core
            $t->string('title');
            $t->string('slug')->unique();
            $t->string('excerpt', 1000)->nullable();
            $t->longText('content_html')->nullable();
            $t->longText('content_markdown')->nullable();

            // Media
            $t->string('featured_image')->nullable();     // URL or path
            $t->string('featured_image_alt')->nullable();

            // SEO
            $t->string('seo_title')->nullable();
            $t->string('seo_description', 500)->nullable();
            $t->string('canonical_url')->nullable();
            $t->json('og_data')->nullable();              // {"title":"","description":"","image":""}
            $t->json('twitter_data')->nullable();         // {"card":"summary_large_image","image":""}
            $t->json('schema_json')->nullable();          // article JSON-LD

            // Meta
            $t->string('author_name')->default('Seben Team'); // no auth table needed
            $t->string('read_time')->nullable();              // "8 min read"
            $t->unsignedInteger('word_count')->default(0);
            $t->unsignedInteger('views')->default(0);

            // Status
            $t->enum('status', ['draft', 'scheduled', 'published', 'archived'])->default('draft');
            $t->timestamp('published_at')->nullable();
            $t->boolean('is_featured')->default(false);
            $t->foreignId('primary_category_id')->nullable()->constrained('categories')->nullOnDelete();

            // Extras
            $t->json('meta')->nullable();                 // flexible key/values

            $t->timestamps();
            $t->softDeletes();

            $t->index(['status', 'published_at']);
            $t->index('primary_category_id');
        });

        // Optional FULLTEXT for MySQL 8+ (title/excerpt/content) to boost blog search/SEO tooling
        Schema::table('posts', function (Blueprint $t) {
            // Comment out if DB engine doesn't support FULLTEXT
            $t->fullText(['title', 'excerpt', 'content_html']);
        });
    }

    public function down(): void {
        Schema::table('posts', function (Blueprint $t) {
            // drop fulltext automatically with table
        });
        Schema::dropIfExists('posts');
    }
};
