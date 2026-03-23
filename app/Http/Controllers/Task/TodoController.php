<?php

namespace App\Http\Controllers\Task;

use App\Models\Task\Todo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TodoController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user('sanctum');
        $deviceId = $request->header('X-Device-ID') ?? $request->device_id;
        Log::info('Todo index request', ['user_id' => $user?->id, 'device_id' => $deviceId]);

        if ($user) {
            $teamIds = $user->teams()->pluck('teams.id')->toArray();
            $todos = Todo::where(function($query) use ($user, $teamIds) {
                $query->where('user_id', $user->id)
                      ->orWhereIn('team_id', $teamIds);
            })->latest()->get();
        } elseif ($deviceId) {
            $todos = Todo::where('device_id', $deviceId)->whereNull('user_id')->latest()->get();
        } else {
            return response()->json(['message' => 'Unauthorized or Device ID required'], 401);
        }

        return response()->json([
            'todos' => $todos,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'judul' => 'required|string|max:255',
            'deskripsi' => 'nullable|string',
            'deadline' => 'nullable|date',
            'priority' => 'nullable|in:high,medium,low',
            'team_id' => 'nullable|exists:teams,id',
            'assigned_emails' => 'nullable|array',
        ]);

        $user = $request->user('sanctum');
        $deviceId = $request->header('X-Device-ID') ?? $request->device_id;
        Log::info('Todo store request', ['user_id' => $user?->id, 'device_id' => $deviceId, 'judul' => $request->judul]);

        if (!$user && !$deviceId) {
            return response()->json(['message' => 'Unauthorized or Device ID required'], 401);
        }

        $todo = Todo::create([
            'judul' => $request->judul,
            'deskripsi' => $request->deskripsi,
            'deadline' => $request->deadline,
            'priority' => $request->priority ?? 'medium',
            'user_id' => $user ? $user->id : null,
            'device_id' => $user ? null : $deviceId,
            'team_id' => $request->team_id,
            'assigned_emails' => $request->assigned_emails,
        ]);

        return response()->json([
            'message' => 'Todo berhasil ditambahkan',
            'todo' => $todo,
        ], 201);
    }

    public function show(Request $request, Todo $todo)
    {
        $user = $request->user('sanctum');
        $deviceId = $request->header('X-Device-ID') ?? $request->device_id;

        if (!$this->verifyOwnership($todo, $user, $deviceId)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json([
            'todo' => $todo,
        ]);
    }

    public function update(Request $request, Todo $todo)
    {
        $user = $request->user('sanctum');
        $deviceId = $request->header('X-Device-ID') ?? $request->device_id;

        if (!$this->verifyOwnership($todo, $user, $deviceId)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'judul' => 'sometimes|required|string|max:255',
            'deskripsi' => 'nullable|string',
            'is_completed' => 'sometimes|boolean',
            'deadline' => 'nullable|date',
            'priority' => 'nullable|in:high,medium,low',
            'assigned_emails' => 'nullable|array',
        ]);

        $todo->update($request->only(['judul', 'deskripsi', 'is_completed', 'deadline', 'priority', 'assigned_emails']));

        return response()->json([
            'message' => 'Todo berhasil diupdate',
            'todo' => $todo,
        ]);
    }

    public function destroy(Request $request, Todo $todo)
    {
        $user = $request->user('sanctum');
        $deviceId = $request->header('X-Device-ID') ?? $request->device_id;

        if (!$this->verifyOwnership($todo, $user, $deviceId)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $todo->delete();

        return response()->json([
            'message' => 'Todo berhasil dihapus',
        ]);
    }

    private function verifyOwnership(Todo $todo, $user, ?string $deviceId): bool
    {
        if ($user) {
            if ($todo->user_id === $user->id) return true;
            if ($todo->team_id) {
                return $user->teams()->where('teams.id', $todo->team_id)->exists();
            }
            return false;
        }

        if ($deviceId) {
            return $todo->device_id === $deviceId && is_null($todo->user_id);
        }

        return false;
    }

    /**
     * Toggle current user's completion status on a team task.
     * Each assigned member must check individually.
     * Task is_completed = true only when ALL assigned members have checked.
     */
    public function toggleMember(Request $request, Todo $todo)
    {
        $user = $request->user('sanctum');
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $email = strtolower(trim($user->email));
        $assignedEmails = collect($todo->assigned_emails ?? [])->map(fn($e) => strtolower(trim($e)));

        // Check if user is owner or assigned, AND is a team member
        $todo->load('team');
        $isOwner = $todo->team && $todo->team->created_by === $user->id;
        $isAssigned = $assignedEmails->contains($email);

        // Verify user is actually a member of the team
        if ($todo->team_id) {
            $isTeamMember = $todo->team && $todo->team->members()->where('users.id', $user->id)->wherePivot('status', 'accepted')->exists();
            if (!$isTeamMember && !$isOwner) {
                return response()->json(['message' => 'You must be a team member to toggle this task'], 403);
            }
        }

        if (!$isOwner && !$isAssigned) {
            return response()->json(['message' => 'Only the owner or assigned member can toggle this task'], 403);
        }

        $completedBy = collect($todo->completed_by ?? []);

        if ($completedBy->contains($email)) {
            // Uncheck: remove from completed_by
            $completedBy = $completedBy->reject(fn($e) => strtolower(trim((string)$e)) === $email)->values();
        } else {
            // Check: add to completed_by
            $completedBy->push($email);
        }

        $completedByArray = $completedBy->values()->all();
        $totalAssigned = max($assignedEmails->count(), 1);
        $totalCompleted = $completedBy->count();
        $isFullyCompleted = $totalCompleted >= $totalAssigned;

        $todo->update([
            'completed_by' => $completedByArray,
            'is_completed' => $isFullyCompleted,
        ]);

        return response()->json([
            'message' => 'Task status updated',
            'todo' => $todo->fresh(),
            'completed_count' => $totalCompleted,
            'total_assigned' => $totalAssigned,
            'is_fully_completed' => $isFullyCompleted,
        ]);
    }
}
