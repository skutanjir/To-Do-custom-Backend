<?php
// app/Models/TodoState.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TodoState extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'color', 'is_final', 'sort_order', 'workspace_id'];

    protected $casts = [
        'is_final' => 'boolean',
    ];

    /**
     * Get the workspace that owns the state (null means global/default).
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Get the todos currently in this state.
     */
    public function todos(): HasMany
    {
        return $this->hasMany(Todo::class, 'status_id');
    }
}
