<?php
// app/Http/Controllers/WorkspaceController.php

namespace App\Http\Controllers;

use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class WorkspaceController extends Controller
{
    use \Illuminate\Foundation\Auth\Access\AuthorizesRequests, \Illuminate\Foundation\Validation\ValidatesRequests;

    public function index()
    {
        return Workspace::where('owner_id', Auth::id())->get();
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'settings' => 'nullable|array',
        ]);

        return Workspace::create(array_merge($validated, ['owner_id' => Auth::id(), 'slug' => \Illuminate\Support\Str::slug($validated['name'])]));
    }

    public function show(Workspace $workspace)
    {
        if ($workspace->owner_id !== Auth::id()) {
            abort(403);
        }
        return $workspace;
    }

    public function update(Request $request, Workspace $workspace)
    {
        if ($workspace->owner_id !== Auth::id()) {
            abort(403);
        }
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'settings' => 'nullable|array',
        ]);

        $workspace->update($validated);
        return $workspace;
    }

    public function destroy(Workspace $workspace)
    {
        $this->authorize('delete', $workspace);
        $workspace->delete();
        return response()->noContent();
    }
}
