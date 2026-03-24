<?php
// app/Http/Controllers/ProjectController.php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Models\Workspace\Project;
use App\Models\Workspace\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ProjectController extends Controller
{
    public function index(Request $request)
    {
        $query = Project::query();
        if ($request->has('workspace_id')) {
            $workspace = Workspace::findOrFail($request->workspace_id);
            if ($workspace->owner_id !== Auth::id()) abort(403);
            $query->where('workspace_id', $request->workspace_id);
        } else {
            $query->where('user_id', Auth::id());
        }
        return $query->paginate(20);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'workspace_id' => 'required|exists:workspaces,id',
            'name' => 'required|string|max:255',
            'color' => 'nullable|string|max:7',
            'description' => 'nullable|string',
        ]);

        $workspace = Workspace::findOrFail($validated['workspace_id']);
        if ($workspace->owner_id !== Auth::id()) {
            abort(403);
        }

        return Project::create(array_merge($validated, ['user_id' => Auth::id()]));
    }

    public function show(Project $project)
    {
        $project->load('workspace');
        if ($project->workspace->user_id !== Auth::id()) {
            abort(403);
        }
        return $project;
    }

    public function update(Request $request, Project $project)
    {
        $project->load('workspace');
        if ($project->workspace->user_id !== Auth::id()) {
            abort(403);
        }
        
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'color' => 'nullable|string|max:7',
        ]);

        $project->update($validated);
        return $project;
    }

    public function destroy(Project $project)
    {
        $project->load('workspace');
        if ($project->workspace->user_id !== Auth::id()) {
            abort(403);
        }
        $project->delete();
        return response()->noContent();
    }
}
