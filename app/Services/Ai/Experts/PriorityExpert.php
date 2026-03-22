<?php
// app/Services/Ai/Experts/PriorityExpert.php

namespace App\Services\Ai\Experts;

use App\Services\Ai\ExpertInterface;

class PriorityExpert implements ExpertInterface
{
    public function evaluate(string $message, array $context): array
    {
        $msg = strtolower($message);
        $findings = [];
        $actions = [];
        $suggestions = [];
        $confidence = 0;

        // Pattern 1: Priority management
        if (preg_match('/(penting|prioritas|mendesak|priority|high)/i', $msg)) {
            $findings[] = "User is focusing on task priority.";
            $confidence = 75;

            $tasks = $context['tasks'] ?? [];
            $highPriorityTasks = array_filter($tasks, fn($t) => ($t['priority'] ?? '') === 'high' && !$t['is_completed']);

            if (count($highPriorityTasks) > 3) {
                $findings[] = "High workload detected: Too many high-priority tasks.";
                $suggestions[] = "You have " . count($highPriorityTasks) . " high-priority tasks. Shall we re-evaluate their importance?";
                $actions[] = [
                    'type' => 'smart_prioritize',
                    'reason' => 'overload'
                ];
            }
        }

        return [
            'findings' => $findings,
            'actions' => $actions,
            'suggestions' => $suggestions,
            'confidence' => $confidence
        ];
    }
}
