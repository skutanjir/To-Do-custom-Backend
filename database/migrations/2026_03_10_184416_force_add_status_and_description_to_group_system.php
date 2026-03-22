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
        Schema::table('teams', function (Blueprint $table) {
            if (!Schema::hasColumn('teams', 'description')) {
                $table->string('description')->nullable()->after('name');
            }
        });

        Schema::table('team_user', function (Blueprint $table) {
            if (!Schema::hasColumn('team_user', 'status')) {
                $table->enum('status', ['pending', 'accepted', 'declined'])->default('pending')->after('user_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('team_user', function (Blueprint $table) {
            if (Schema::hasColumn('team_user', 'status')) {
                $table->dropColumn('status');
            }
        });

        Schema::table('teams', function (Blueprint $table) {
            if (Schema::hasColumn('teams', 'description')) {
                $table->dropColumn('description');
            }
        });
    }
};
