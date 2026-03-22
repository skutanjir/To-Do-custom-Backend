<?php
// app/Services/Ai/Intents/StateMachineIntentHandler.php

namespace App\Services\Ai\Intents;

use App\Models\TodoState;
use Illuminate\Support\Str;

class StateMachineIntentHandler implements IntentHandler
{
    /**
     * Scores the intent for Task State management (Workflow).
     */
    public function score(string $msg, array $context): int
    {
        $patterns = [
            '/\b(pindah|geser|move|set|mark|tandai)\s+(status|ke|sebagai)\b/i' => 85,
            '/\b(in progress|progress|jalan|sedang)\b/i' => 80,
            '/\b(blocked|hambat|macet|tertahan)\b/i' => 90,
            '/\b(review|tinjau|periksa)\b/i' => 85,
        ];

        foreach ($patterns as $pattern => $score) {
            if (preg_match($pattern, $msg)) return $score;
        }

        return 0;
    }

    /**
     * Handles Task State operations.
     */
    public function handle(string $msg, array $context): array
    {
        $user = $context['user'];
        
        // Extract Target State and Task Reference
        // e.g., "pindahkan tugas 'Laporan' ke In Progress"
        $targetState = null;
        if (preg_match('/\b(ke|sebagai|status)\s+[\'"]?(.+?)[\'"]?$/i', $msg, $matches)) {
            $targetState = trim($matches[2]);
        }

        // Search for State in common aliases
        if (!$targetState) {
            if (preg_match('/\b(in progress|progress|running)\b/i', $msg)) $targetState = 'In Progress';
            if (preg_match('/\b(blocked|stuck|waiting)\b/i', $msg)) $targetState = 'Blocked';
            if (preg_match('/\b(completed|done|selesai)\b/i', $msg)) $targetState = 'Completed';
        }

        $taskRef = preg_replace('/\b(pindah|geser|move|set|mark|tandai|tugas|ke|sebagai|status|statusnya)\b/i', '', $msg);
        $taskRef = trim($taskRef, ' ,:');
        
        return [
            'intent' => 'task_state_transition',
            'content' => json_encode([
                'message' => "Requesting a state transition to \"{$targetState}\". Jarvis is calculating the industrial impact on your project velocity.",
                'action' => [
                    'type' => 'update_task_state',
                    'data' => [
                        'task_reference' => $taskRef,
                        'target_state' => $targetState
                    ]
                ]
            ])
        ];
    }
}
