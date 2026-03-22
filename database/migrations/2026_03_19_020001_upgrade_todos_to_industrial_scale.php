<?php
// database/migrations/2026_03_19_020001_upgrade_todos_to_industrial_scale.php

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
        Schema::table('todos', function (Blueprint $col) {
            // Soft Deletes (for Industrial Persistence)
            if (!Schema::hasColumn('todos', 'deleted_at')) {
                $col->softDeletes()->after('user_id');
            }

            // Associations
            $col->foreignId('workspace_id')->nullable()->after('user_id')->constrained('workspaces')->onDelete('cascade');
            $col->foreignId('project_id')->nullable()->after('workspace_id')->constrained('projects')->onDelete('cascade');
            $col->foreignId('folder_id')->nullable()->after('project_id')->constrained('folders')->onDelete('cascade');
            
            // Workflow & State (Replacing simple is_completed)
            $col->unsignedBigInteger('status_id')->nullable()->after('is_completed');
            $col->foreign('status_id')->references('id')->on('todo_states')->onDelete('set null');
            
            // Hierarchy (Nested Subtasks)
            $col->unsignedBigInteger('parent_id')->nullable()->after('status_id');
            $col->foreign('parent_id')->references('id')->on('todos')->onDelete('cascade');
            
            // Industrial Metadata
            $col->integer('version')->default(1)->after('parent_id');
            $col->integer('sort_order')->default(0)->after('version');
            $col->jsonb('custom_fields')->nullable()->after('sort_order');
            $col->timestamp('started_at')->nullable()->after('deadline');
            $col->integer('estimated_minutes')->nullable()->after('started_at');
            $col->integer('actual_minutes')->nullable()->after('estimated_minutes');
            
            // Indexing for scale
            $col->index(['workspace_id', 'project_id', 'status_id']);
            $col->index(['parent_id', 'sort_order']);
            $col->index(['deleted_at', 'workspace_id']);
        });

        // Migrate existing is_completed to status_id
        DB::table('todos')->where('is_completed', true)->update(['status_id' => 5]); // Completed
        DB::table('todos')->where('is_completed', false)->update(['status_id' => 2]); // Todo
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('todos', function (Blueprint $col) {
            $col->dropForeign(['workspace_id']);
            $col->dropForeign(['project_id']);
            $col->dropForeign(['folder_id']);
            $col->dropForeign(['status_id']);
            $col->dropForeign(['parent_id']);
            
            $col->dropColumn([
                'workspace_id', 'project_id', 'folder_id', 
                'status_id', 'parent_id', 'version', 
                'sort_order', 'custom_fields', 'started_at', 
                'estimated_minutes', 'actual_minutes', 'deleted_at'
            ]);
        });
    }
};
