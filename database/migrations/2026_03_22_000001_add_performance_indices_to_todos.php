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
        Schema::table('todos', function (Blueprint $table) {
            // team_id is already a foreign key, but index helps for sorting/filtering
            if (Schema::hasColumn('todos', 'team_id')) {
                $table->index('team_id');
            }
            
            if (Schema::hasColumn('todos', 'priority')) {
                $table->index('priority');
            }
            
            if (Schema::hasColumn('todos', 'is_completed')) {
                $table->index(['user_id', 'is_completed']);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('todos', function (Blueprint $table) {
            $table->dropIndex(['team_id']);
            $table->dropIndex(['priority']);
            $table->dropIndex(['user_id', 'is_completed']);
        });
    }
};
