<?php
// app/Http/Controllers/FolderController.php

namespace App\Http\Controllers;

use App\Models\Folder;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class FolderController extends Controller
{
    public function index(Request $request)
    {
        $query = Folder::query();
        if ($request->has('project_id')) {
            $query->where('project_id', $request->project_id);
        } else {
            $query->whereHas('project.workspace', function($q) {
                $q->where('user_id', Auth::id());
            });
        }
        return $query->get();
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'project_id' => 'required|exists:projects,id',
            'name' => 'required|string|max:255',
        ]);

        $project = Project::with('workspace')->findOrFail($validated['project_id']);
        if ($project->workspace->user_id !== Auth::id()) {
            abort(403);
        }

        return Folder::create($validated);
    }

    public function show(Folder $folder)
    {
        $folder->load('project.workspace');
        if ($folder->project->workspace->user_id !== Auth::id()) {
            abort(403);
        }
        return $folder;
    }

    public function update(Request $request, Folder $folder)
    {
        $folder->load('project.workspace');
        if ($folder->project->workspace->user_id !== Auth::id()) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
        ]);

        $folder->update($validated);
        return $folder;
    }

    public function destroy(Folder $folder)
    {
        $folder->load('project.workspace');
        if ($folder->project->workspace->user_id !== Auth::id()) {
            abort(403);
        }
        $folder->delete();
        return response()->noContent();
    }
}
