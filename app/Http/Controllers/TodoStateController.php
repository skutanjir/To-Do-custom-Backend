<?php
// app/Http/Controllers/TodoStateController.php

namespace App\Http\Controllers;

use App\Models\TodoState;
use Illuminate\Http\Request;

class TodoStateController extends Controller
{
    public function index()
    {
        return TodoState::orderBy('sort_order')->get();
    }

    public function show(TodoState $todoState)
    {
        return $todoState;
    }
}
