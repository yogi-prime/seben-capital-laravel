<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('tags', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug')->unique();
            $t->string('seo_title')->nullable();
            $t->string('seo_description', 500)->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
    }

    public function down(): void {
        Schema::dropIfExists('tags');
    }
};
