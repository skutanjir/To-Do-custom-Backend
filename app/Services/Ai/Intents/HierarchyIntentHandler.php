<?php
// app/Services/Ai/Intents/HierarchyIntentHandler.php

namespace App\Services\Ai\Intents;

use App\Models\Todo;
use Illuminate\Support\Str;

class HierarchyIntentHandler implements IntentHandler
{
    /**
     * Scores the intent for Hierarchical task management (subtasks).
     */
    public function score(string $msg, array $context): int
    {
        $patterns = [
            '/\b(subtask|sub task|anak tugas|bagian dari|sub-task)\b/i' => 85,
            '/\b(tambah|buat)\s+(subtask|anak)\s+(di|ke|untuk)\b/i' => 95,
            '/\bsebagai\s+bagian\s+dari\s+tugas\b/i' => 90,
        ];

        foreach ($patterns as $pattern => $score) {
            if (preg_match($pattern, $msg)) return $score;
        }

        return 0;
    }

    /**
     * Handles Hierarchical task operations.
     */
    public function handle(string $msg, array $context): array
    {
        $user = $context['user'];
        
        // Extract Subtask Title and Parent Reference
        // e.g., "tambah subtask 'Beli Telur' ke tugas 'Belanja Mingguan'"
        $parentRef = null;
        if (preg_match('/\b(ke|untuk|di|dari|tugas)\s+[\'"]?(.+?)[\'"]?$/i', $msg, $matches)) {
            $parentRef = trim($matches[2]);
        }

        $title = preg_replace('/\b(tambah|buat|subtask|anak|tugas|ke|untuk|di|dari)\b/i', '', $msg);
        $title = trim($title, ' ,:');
        
        if (empty($title)) {
            return [
                'intent' => 'subtask_create',
                'content' => json_encode([
                    'message' => "What is the title of the subtask? Jarvis is ready to nest it.",
                    'action' => null
                ])
            ];
        }

        return [
            'intent' => 'subtask_create',
            'content' => json_encode([
                'message' => "Requesting a nested subtask: \"{$title}\". Identifying the parent entity for recursive alignment.",
                'action' => [
                    'type' => 'create_subtask',
                    'data' => [
                        'judul' => $title,
                        'parent_reference' => $parentRef
                    ]
                ]
            ])
        ];
    }
}
