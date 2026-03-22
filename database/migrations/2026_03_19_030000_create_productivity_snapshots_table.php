<?php
// database/migrations/2026_03_19_030000_create_productivity_snapshots_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('productivity_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('device_id')->nullable()->index();
            $table->integer('total_tasks')->default(0);
            $table->integer('completed_tasks')->default(0);
            $table->integer('overdue_tasks')->default(0);
            $table->integer('mental_load_score')->default(0);
            $table->decimal('efficiency_rating', 5, 2)->default(0); // 0-100
            $table->jsonb('expert_metadata')->nullable();         // Store snapshot of expert findings
            $table->timestamp('measured_at')->useCurrent();
            $table->timestamps();

            $table->index(['user_id', 'measured_at']);
            $table->index(['device_id', 'measured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('productivity_snapshots');
    }
};
