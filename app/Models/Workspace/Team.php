<?php

namespace App\Models\Workspace;

use App\Models\User;
use App\Models\Task\Todo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Team extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'description', 'created_by'];

    public function owner()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function members()
    {
        return $this->belongsToMany(User::class)->withPivot('status')->withTimestamps();
    }

    public function todos()
    {
        return $this->hasMany(Todo::class);
    }
}
