<?php
// app/Services/Ai/Intents/WorkspaceIntentHandler.php

namespace App\Services\Ai\Intents;

use App\Models\Workspace\Workspace;
use Illuminate\Support\Str;

class WorkspaceIntentHandler implements IntentHandler
{
    /**
     * Scores the intent for Workspace management.
     */
    public function score(string $msg, array $context): int
    {
        $patterns = [
            '/\b(workspace|work space|tenant|organisasi)\b/i' => 80,
            '/\b(buat|tambah|pindah|ganti|pilih)\s+workspace\b/i' => 95,
            '/\bworkspace\s+baru\b/i' => 90,
            '/\blist\s+workspace\b/i' => 85,
        ];

        foreach ($patterns as $pattern => $score) {
            if (preg_match($pattern, $msg)) return $score;
        }

        return 0;
    }

    /**
     * Handles Workspace management operations.
     */
    public function handle(string $msg, array $context): array
    {
        $user = $context['user'];
        
        if (preg_match('/\b(buat|tambah|new)\b/i', $msg)) {
            return $this->handleCreate($msg, $user);
        }

        if (preg_match('/\b(pindah|ganti|pilih|switch)\b/i', $msg)) {
            return $this->handleSwitch($msg, $user);
        }

        return $this->handleList($user);
    }

    private function handleCreate(string $msg, $user): array
    {
        $name = preg_replace('/\b(buat|tambah|new|workspace|baru|named|bernama)\b/i', '', $msg);
        $name = trim($name, ' ,:');
        
        if (empty($name)) {
            return [
                'intent' => 'workspace_create',
                'content' => json_encode([
                    'message' => "What would you like to name your new Workspace?",
                    'action' => null
                ])
            ];
        }

        return [
            'intent' => 'workspace_create',
            'content' => json_encode([
                'message' => "Requesting creation of industrial workspace: \"{$name}\". Jarvis is initializing the environment.",
                'action' => [
                    'type' => 'create_workspace',
                    'data' => [
                        'name' => $name,
                        'slug' => Str::slug($name)
                    ]
                ]
            ])
        ];
    }

    private function handleSwitch(string $msg, $user): array
    {
        // Extraction logic for workspace name/ID
        return [
            'intent' => 'workspace_switch',
            'content' => json_encode([
                'message' => "Which workspace should I switch to? Jarvis can migrate your session context immediately.",
                'action' => ['type' => 'list_workspaces']
            ])
        ];
    }

    private function handleList($user): array
    {
        return [
            'intent' => 'workspace_list',
            'content' => json_encode([
                'message' => "I have found several industrial containers in your account. Which one shall we manage?",
                'action' => ['type' => 'list_workspaces']
            ])
        ];
    }
}
