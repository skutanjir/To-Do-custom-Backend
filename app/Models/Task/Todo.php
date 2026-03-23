<?php
// app/Models/Todo.php

namespace App\Models\Task;

use App\Models\User;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Todo extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'judul',
        'deskripsi',
        'is_completed',
        'deadline',
        'priority',
        'device_id',
        'user_id',
        'team_id',
        'workspace_id',
        'project_id',
        'folder_id',
        'status_id',
        'parent_id',
        'version',
        'sort_order',
        'custom_fields',
        'started_at',
        'estimated_minutes',
        'actual_minutes',
        'assigned_emails',
        'completed_by',
    ];

    protected $casts = [
        'judul' => 'string',
        'deskripsi' => 'string',
        'is_completed' => 'boolean',
        'deadline' => 'datetime',
        'started_at' => 'datetime',
        'assigned_emails' => 'array',
        'completed_by' => 'array',
        'custom_fields' => 'array',
        'version' => 'integer',
        'sort_order' => 'integer',
        'estimated_minutes' => 'integer',
        'actual_minutes' => 'integer',
    ];

    /**
     * Boot the model to handle versioning.
     */
    protected static function boot()
    {
        parent::boot();

        static::updating(function ($todo) {
            $todo->version++;
        });
    }

    /**
     * Get the workspace that owns the todo.
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Get the project that owns the todo.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the folder that owns the todo.
     */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class);
    }

    /**
     * Get the workflow state of the todo.
     */
    public function state(): BelongsTo
    {
        return $this->belongsTo(TodoState::class, 'status_id');
    }

    /**
     * Get the parent task.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Todo::class, 'parent_id');
    }

    /**
     * Get the subtasks.
     */
    public function subtasks(): HasMany
    {
        return $this->hasMany(Todo::class, 'parent_id');
    }

    /**
     * Get the user that owns the todo.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the team associated with the todo.
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}