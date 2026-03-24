<?php

namespace App\Http\Controllers\Workspace;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\System\Notification;
use App\Models\Workspace\Team;
use App\Models\User;

class TeamController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        
        // Teams where user is an accepted member
        $teams = $user->teams()
            ->wherePivot('status', 'accepted')
            ->with(['owner', 'members' => function($q) {
                $q->wherePivot('status', 'accepted');
            }])
            ->withCount(['todos', 'todos as completed_todos_count' => function($q) {
                $q->where('is_completed', true);
            }])
            ->get()
            ->map(function($team) {
                $totalTasks = $team->todos_count;
                $completedTasks = $team->completed_todos_count;
                $team->progress = $totalTasks > 0 ? round(($completedTasks / $totalTasks) * 100) : 0;
                return $team;
            });

        // Pending invitations for the user
        $invitations = $user->teams()
            ->wherePivot('status', 'pending')
            ->with('owner')
            ->get();

        return response()->json([
            'teams' => $teams,
            'invitations' => $invitations,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
        ]);

        $team = \App\Models\Team::create([
            'name' => $request->name,
            'description' => $request->description,
            'created_by' => $request->user()->id,
        ]);

        // Creator automatically becomes an accepted member
        $team->members()->attach($request->user()->id, ['status' => 'accepted']);

        return response()->json([
            'message' => 'Team created successfully',
            'team' => $team->load(['members' => function($q) {
                $q->wherePivot('status', 'accepted');
            }])
        ], 201);
    }

    public function invite(Request $request, \App\Models\Team $team)
    {
        // Only owner can invite
        if ($team->created_by !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'email' => 'required|email|exists:users,email',
        ]);

        $userToInvite = \App\Models\User::where('email', $request->email)->first();

        $membership = $team->members()->where('user_id', $userToInvite->id)->where('status', '!=', 'declined')->first();
        if ($membership) {
            if ($membership->pivot->status === 'banned') {
                return response()->json(['message' => 'This user is banned from this team'], 422);
            }
            return response()->json(['message' => 'User is already a member or invited to this team'], 422);
        }

        // Attach with pending status
        $team->members()->attach($userToInvite->id, ['status' => 'pending']);

        // Notification
        Notification::create([
            'user_id' => $userToInvite->id,
            'type' => 'invite',
            'message' => "Anda telah diundang untuk bergabung dengan tim {$team->name}.",
            'team_id' => $team->id,
        ]);

        return response()->json([
            'message' => 'User invited to team successfully',
            'team' => $team->load('members')
        ]);
    }

    public function acceptInvitation(Request $request, \App\Models\Team $team)
    {
        $user = $request->user();
        
        $membership = $team->members()->where('user_id', $user->id)->first();
        
        if (!$membership || $membership->pivot->status !== 'pending') {
            return response()->json(['message' => 'No pending invitation found'], 404);
        }

        $team->members()->updateExistingPivot($user->id, ['status' => 'accepted']);

        return response()->json([
            'message' => 'Invitation accepted successfully',
            'team' => $team->load(['members' => function($q) {
                $q->wherePivot('status', 'accepted');
            }])
        ]);
    }

    public function declineInvitation(Request $request, \App\Models\Team $team)
    {
        $user = $request->user();
        
        $membership = $team->members()->where('user_id', $user->id)->first();
        
        if (!$membership || $membership->pivot->status !== 'pending') {
            return response()->json(['message' => 'No pending invitation found'], 404);
        }

        $team->members()->detach($user->id);

        return response()->json([
            'message' => 'Invitation declined successfully'
        ]);
    }

    public function show(\App\Models\Team $team)
    {
        $user = auth()->user();
        $isMember = $team->members()->where('user_id', $user->id)->where('status', 'accepted')->exists();

        if (!$isMember) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $allTeamTasks = $team->todos()->get();
        $members = $team->members()
            ->wherePivot('status', 'accepted')
            ->get()
            ->map(function($user) use ($team, $allTeamTasks) {
                $memberEmail = strtolower(trim($user->email));
                
                // Get tasks assigned to this member (by user_id OR by assigned_emails)
                $memberTasks = $allTeamTasks->filter(function($todo) use ($user, $memberEmail) {
                    // Task assigned by user_id
                    if ($todo->user_id === $user->id) return true;
                    // Task assigned by email in assigned_emails
                    $assignedEmails = collect($todo->assigned_emails ?? [])->map(fn($e) => strtolower(trim($e)));
                    return $assignedEmails->contains($memberEmail);
                });
                
                $totalTasks = $memberTasks->count();
                $completedTasks = $memberTasks->filter(function($todo) use ($memberEmail) {
                    $completedBy = collect($todo->completed_by ?? []);
                    $assignedEmails = collect($todo->assigned_emails ?? []);
                    
                    // If task uses completed_by system (has assigned_emails)
                    if ($assignedEmails->isNotEmpty()) {
                        return $completedBy->contains(fn($e) => strtolower(trim((string)$e)) === $memberEmail);
                    }
                    // Fallback: legacy task with is_completed
                    return $todo->is_completed;
                })->count();
                
                $user->progress = $totalTasks > 0 ? round(($completedTasks / $totalTasks) * 100) : 0;
                $user->role = ($user->id === $team->created_by) ? 'Ketua Team' : 'Member';
                return $user;
            });

        return response()->json([
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
                'description' => $team->description,
                'created_by' => $team->created_by,
                'owner' => $team->owner,
                'members' => $members,
            ],
            'tasks' => $team->todos()->with('user')->get(),
        ]);
    }

    public function update(Request $request, \App\Models\Team $team)
    {
        if ($team->created_by !== auth()->id()) {
            return response()->json(['message' => 'Only owner can update team'], 403);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        $team->update($request->only('name', 'description'));

        return response()->json([
            'message' => 'Team updated successfully',
            'team' => $team
        ]);
    }

    public function removeMember(Request $request, \App\Models\Team $team, \App\Models\User $user)
    {
        if ($team->created_by !== auth()->id()) {
            return response()->json(['message' => 'Only owner can remove members'], 403);
        }

        if ($user->id === $team->created_by) {
            return response()->json(['message' => 'Cannot remove the owner'], 400);
        }

        $team->members()->detach($user->id);

        // Notification
        Notification::create([
            'user_id' => $user->id,
            'type' => 'kick',
            'message' => "Anda telah dikeluarkan dari tim {$team->name}.",
            'team_id' => $team->id,
        ]);

        return response()->json(['message' => 'Member removed successfully']);
    }

    public function banMember(Request $request, \App\Models\Team $team, \App\Models\User $user)
    {
        if ($team->created_by !== auth()->id()) {
            return response()->json(['message' => 'Only owner can ban members'], 403);
        }

        if ($user->id === $team->created_by) {
            return response()->json(['message' => 'Cannot ban the owner'], 400);
        }

        $team->members()->updateExistingPivot($user->id, ['status' => 'banned']);

        // Notification
        Notification::create([
            'user_id' => $user->id,
            'type' => 'ban',
            'message' => "Anda telah dilarang (banned) dari tim {$team->name}.",
            'team_id' => $team->id,
        ]);

        return response()->json(['message' => 'Member banned successfully']);
    }

    public function destroy(Request $request, \App\Models\Team $team)
    {
        if ($team->created_by !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $team->delete();

        return response()->json(['message' => 'Team deleted successfully']);
    }
}
