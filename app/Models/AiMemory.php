<?php
// app/Models/AiMemory.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiMemory extends Model
{
    protected $table = 'ai_memories';

    protected $fillable = [
        'user_id',
        'device_id',
        'type',
        'key',
        'value',
        'hits',
        'expires_at',
    ];

    protected $casts = [
        'value' => 'array',
        'expires_at' => 'datetime',
    ];
}
