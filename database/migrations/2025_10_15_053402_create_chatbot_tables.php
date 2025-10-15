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
       Schema::create('chatbot_flows', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique()->default('default');
            $t->json('steps'); // [{key, question, placeholder, type, is_button}]
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('chatbot_leads', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('phone', 20)->nullable()->index();
            $t->string('email')->nullable()->index();
            $t->json('answers')->nullable();   // {key:value}
            $t->json('meta')->nullable();      // e.g. utm, ip, ua
            $t->enum('status', ['in_progress','submitted'])->default('in_progress');
            $t->timestamps();
        });

        Schema::create('chatbot_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('lead_id')->nullable()->constrained('chatbot_leads')->cascadeOnDelete();
            $t->enum('direction', ['bot','user']);
            $t->text('content');
            $t->string('step_key')->nullable();  // which step
            $t->string('ip')->nullable();
            $t->string('ua')->nullable();
            $t->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
       Schema::dropIfExists('chatbot_messages');
        Schema::dropIfExists('chatbot_leads');
        Schema::dropIfExists('chatbot_flows');
    }
};
