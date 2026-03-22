<?php
// app/Services/Ai/Experts/HabitExpert.php

namespace App\Services\Ai\Experts;

use App\Services\Ai\ExpertInterface;
use App\Models\Todo;

class HabitExpert implements ExpertInterface
{
    public function evaluate(string $message, array $context): array
    {
        $msg = strtolower($message);
        $findings = [];
        $actions = [];
        $suggestions = [];
        $confidence = 0;

        // Pattern 1: Habit coaching / Daily routine
        if (preg_match('/(habit|kebiasaan|rutin|pagi|malam|suggest|saran)/i', $msg)) {
            $findings[] = "User is looking for habit-based suggestions.";
            $confidence = 70;

            // Analyze historical completion patterns (mock logic for now)
            $findings[] = "Detected pattern: High productivity between 09:00 - 11:00.";
            $suggestions[] = "You usually finish most tasks in the morning. Shall I block 9 AM for your deepest tasks?";
            
            $actions[] = [
                'type' => 'suggest_deep_work',
                'time' => '09:00'
            ];
        }

        return [
            'findings' => $findings,
            'actions' => $actions,
            'suggestions' => $suggestions,
            'confidence' => $confidence
        ];
    }
}
