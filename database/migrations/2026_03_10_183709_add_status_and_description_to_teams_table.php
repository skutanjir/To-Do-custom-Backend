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
        if (!Schema::hasColumn('teams', 'description')) {
            Schema::table('teams', function (Blueprint $table) {
                $table->string('description')->nullable()->after('name');
            });
        }

        if (!Schema::hasColumn('team_user', 'status')) {
            Schema::table('team_user', function (Blueprint $table) {
                $table->enum('status', ['pending', 'accepted', 'declined'])->default('pending')->after('user_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('team_user', 'status')) {
            Schema::table('team_user', function (Blueprint $table) {
                $table->dropColumn('status');
            });
        }

        if (Schema::hasColumn('teams', 'description')) {
            Schema::table('teams', function (Blueprint $table) {
                $table->dropColumn('description');
            });
        }
    }
};
