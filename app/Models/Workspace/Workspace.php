<?php

namespace App\Models\Workspace;

use App\Models\User;
use App\Models\Task\Todo;
use App\Models\Task\TodoState;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Workspace extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['name', 'slug', 'owner_id', 'settings'];

    protected $casts = [
        'settings' => 'array',
    ];

    /**
     * Get the owner of the workspace.
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Get projects within the workspace.
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * Get todos within the workspace.
     */
    public function todos(): HasMany
    {
        return $this->hasMany(Todo::class);
    }

    /**
     * Get custom states for the workspace.
     */
    public function states(): HasMany
    {
        return $this->hasMany(TodoState::class);
    }
}
