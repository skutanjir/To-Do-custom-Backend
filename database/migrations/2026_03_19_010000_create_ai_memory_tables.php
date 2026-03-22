<?php
// database/migrations/2026_03_19_010000_create_ai_memory_tables.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_memories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('device_id')->nullable()->index();
            $table->string('type', 50)->index();       // preference, correction, pattern, fact, context
            $table->string('key', 255)->index();        // e.g. "nickname", "preferred_lang", "work_hours"
            $table->jsonb('value');                      // flexible storage
            $table->integer('hits')->default(1);         // usage frequency for learning weight
            $table->timestamp('expires_at')->nullable(); // TTL for temporary memories
            $table->timestamps();

            $table->index(['user_id', 'type', 'key']);
            $table->index(['device_id', 'type', 'key']);
        });

        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('device_id')->nullable()->index();
            $table->text('summary');                     // condensed conversation summary
            $table->jsonb('topics')->nullable();          // extracted topics/intents
            $table->string('mood', 50)->nullable();       // user's detected mood
            $table->integer('message_count')->default(0); // messages in session
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['device_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_conversations');
        Schema::dropIfExists('ai_memories');
    }
};
