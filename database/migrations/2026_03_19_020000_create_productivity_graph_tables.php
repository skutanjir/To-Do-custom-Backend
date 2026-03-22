<?php
// database/migrations/2026_03_19_020000_create_productivity_graph_tables.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Workspaces (The top-level container)
        Schema::create('workspaces', function (Blueprint $col) {
            $col->id();
            $col->string('name');
            $col->string('slug')->unique();
            $col->foreignId('owner_id')->constrained('users')->onDelete('cascade');
            $col->jsonb('settings')->nullable();
            $col->timestamps();
            $col->softDeletes();
            
            $col->index(['owner_id', 'deleted_at']);
        });

        // 2. Projects (Groupings within a workspace)
        Schema::create('projects', function (Blueprint $col) {
            $col->id();
            $col->string('name');
            $col->text('description')->nullable();
            $col->string('color', 7)->default('#4F46E5');
            $col->string('icon')->nullable();
            $col->foreignId('workspace_id')->constrained('workspaces')->onDelete('cascade');
            $col->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $col->timestamps();
            $col->softDeletes();
            
            $col->index(['workspace_id', 'user_id']);
        });

        // 3. Folders (Nested organization within a project)
        Schema::create('folders', function (Blueprint $col) {
            $col->id();
            $col->string('name');
            $col->foreignId('project_id')->constrained('projects')->onDelete('cascade');
            $col->unsignedBigInteger('parent_id')->nullable();
            $col->integer('sort_order')->default(0);
            $col->timestamps();
            
            $col->foreign('parent_id')->references('id')->on('folders')->onDelete('cascade');
            $col->index(['project_id', 'parent_id', 'sort_order']);
        });
        
        // 4. Task States (Configurable workflow states)
        Schema::create('todo_states', function (Blueprint $col) {
            $col->id();
            $col->string('name'); // e.g., 'Pending', 'In Progress', 'Blocked', 'Completed'
            $col->string('color', 7)->default('#9CA3AF');
            $col->boolean('is_final')->default(false);
            $col->integer('sort_order')->default(0);
            $col->foreignId('workspace_id')->nullable()->constrained('workspaces')->onDelete('cascade');
            $col->timestamps();
            
            $col->index(['workspace_id', 'sort_order']);
        });
        
        // Seed default states
        DB::table('todo_states')->insert([
            ['name' => 'Backlog', 'color' => '#6B7280', 'is_final' => false, 'sort_order' => 0],
            ['name' => 'Todo', 'color' => '#3B82F6', 'is_final' => false, 'sort_order' => 1],
            ['name' => 'In Progress', 'color' => '#F59E0B', 'is_final' => false, 'sort_order' => 2],
            ['name' => 'Review', 'color' => '#8B5CF6', 'is_final' => false, 'sort_order' => 3],
            ['name' => 'Completed', 'color' => '#10B981', 'is_final' => true, 'sort_order' => 4],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('todo_states');
        Schema::dropIfExists('folders');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('workspaces');
    }
};
