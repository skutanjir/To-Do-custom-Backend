<?php
// app/Services/Ai/Experts/DeadlineExpert.php

namespace App\Services\Ai\Experts;

use App\Services\Ai\ExpertInterface;
use Carbon\Carbon;

class DeadlineExpert implements ExpertInterface
{
    public function evaluate(string $message, array $context): array
    {
        $msg = strtolower($message);
        $findings = [];
        $actions = [];
        $suggestions = [];
        $confidence = 0;

        // Pattern 1: Urgency detection
        if (preg_match('/(deadline|batas waktu|kapan|besok|hari ini|urgent)/i', $msg)) {
            $findings[] = "User is concerned about deadlines or timing.";
            $confidence = 80;

            // Analyze task list if present in context
            $tasks = $context['tasks'] ?? [];
            $overdueCount = 0;
            foreach ($tasks as $task) {
                if (isset($task['deadline']) && Carbon::parse($task['deadline'])->isPast() && !$task['is_completed']) {
                    $overdueCount++;
                }
            }

            if ($overdueCount > 0) {
                $findings[] = "Found $overdueCount overdue tasks.";
                $suggestions[] = "Would you like me to reschedule your $overdueCount overdue tasks?";
                $actions[] = [
                    'type' => 'reschedule_all_overdue',
                    'count' => $overdueCount
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
