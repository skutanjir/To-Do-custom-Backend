<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Task\TodoController;
use App\Http\Controllers\User\ProfileController;
use App\Http\Controllers\Workspace\TeamController;
use App\Http\Controllers\System\NotificationController;
use App\Http\Controllers\Ai\AiChatController;
use App\Http\Controllers\Ai\AiHistoryController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Public routes (authentication only)
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {

    // ── Todo CRUD (requires authentication) ─────────────────────────
    Route::apiResource('todos', TodoController::class);
    Route::apiResource('todo-states', \App\Http\Controllers\TodoStateController::class);

    // ── AI Hub & Intelligence ────────────────────────────────────────
    Route::post('/ai/chat', [AiChatController::class, 'chat']);
    Route::post('/ai/stream', [AiChatController::class, 'stream']);
    Route::post('/ai/tts', [AiChatController::class, 'tts']);
    Route::match(['get','post'], '/ai/voice-preference', [AiChatController::class, 'voicePreference']);
    Route::post('/ai/compute-mode', [AiChatController::class, 'toggleComputeMode']);
    Route::get('/ai/analytics/report', [AiChatController::class, 'generateAnalyticsReport']);

    // AI Experts & Insights (Dashboard Widgets)
    Route::get('/ai/experts/insights', [AiChatController::class, 'expertInsights']);
    Route::get('/ai/experts/habits', [AiChatController::class, 'habitRecommendations']);
    Route::get('/ai/experts/mental-load', [AiChatController::class, 'mentalLoadMonitor']);

    // AI Knowledge & Creative Endpoints (v10.0)
    Route::post('/ai/knowledge', [AiChatController::class, 'knowledgeQuery']);
    Route::post('/ai/code', [AiChatController::class, 'codeAssistant']);
    Route::post('/ai/translate', [AiChatController::class, 'translateText']);
    Route::post('/ai/creative-write', [AiChatController::class, 'creativeWrite']);
    // Auth
    Route::get('/user', function (Request $request) {
        return $request->user();
    });
    Route::post('/logout', [AuthController::class, 'logout']);

    // Teams management
    Route::apiResource('teams', TeamController::class);
    Route::post('teams/{team}/invite', [TeamController::class, 'invite']);
    Route::post('teams/{team}/accept', [TeamController::class, 'acceptInvitation']);
    Route::post('teams/{team}/decline', [TeamController::class, 'declineInvitation']);
    Route::delete('teams/{team}/members/{user}', [TeamController::class, 'removeMember']);
    Route::post('teams/{team}/members/{user}/ban', [TeamController::class, 'banMember']);

    // Team task member toggle
    Route::post('todos/{todo}/toggle-member', [TodoController::class, 'toggleMember']);

    // Profile
    Route::post('profile/avatar', [ProfileController::class, 'updateAvatar']);
    Route::post('profile/password', [ProfileController::class, 'updatePassword']);
    Route::post('profile/email', [ProfileController::class, 'updateEmail']);
    Route::post('profile/update', [ProfileController::class, 'updateProfile']);

    // Notifications
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::post('notifications/read-all', [NotificationController::class, 'markAllAsRead']);
    Route::post('notifications/{notification}/read', [NotificationController::class, 'markAsRead']);
    Route::delete('notifications/{notification}', [NotificationController::class, 'destroy']);

    // ── Industrial Enterprise Layer (v6.0) ──────────────────────────────
    Route::apiResource('workspaces', \App\Http\Controllers\WorkspaceController::class);
    Route::apiResource('projects', \App\Http\Controllers\ProjectController::class);
    Route::apiResource('folders', \App\Http\Controllers\FolderController::class);
    // Route::apiResource('labels', \App\Http\Controllers\TodoLabelController::class); // TODO: Create TodoLabelController when label feature is needed
    
    Route::get('productivity/analytics', [AiChatController::class, 'generateAnalyticsReport']);

    // AI History
    Route::get('ai/history', [AiHistoryController::class, 'index']);
    Route::get('ai/history/{aiChat}', [AiHistoryController::class, 'show']);
    Route::delete('ai/history/{aiChat}', [AiHistoryController::class, 'destroy']);
    Route::post('ai/history/clear', [AiHistoryController::class, 'clear']);
});
