<?php
// app/Models/AiConversation.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiConversation extends Model
{
    protected $table = 'ai_conversations';

    protected $fillable = [
        'user_id',
        'device_id',
        'summary',
        'topics',
        'mood',
        'message_count',
    ];

    protected $casts = [
        'topics' => 'array',
    ];
}
