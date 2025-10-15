<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('category_post', function (Blueprint $t) {
            $t->id();
            $t->foreignId('post_id')->constrained()->cascadeOnDelete();
            $t->foreignId('category_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('order_column')->default(0);
            $t->timestamps();

            $t->unique(['post_id', 'category_id']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('category_post');
    }
};
