<?php
// app/Services/Ai/Experts/HabitEvolutionExpert.php

namespace App\Services\Ai\Experts;

use App\Services\Ai\ExpertInterface;

class HabitEvolutionExpert implements ExpertInterface
{
    public function evaluate(string $message, array $context): array
    {
        $findings = [];
        $suggestions = [];
        $confidence = 0;

        $patterns = $context['habits'] ?? [];
        if (count($patterns) > 3) {
            $findings[] = "Pola kebiasaan baru terdeteksi.";
            $suggestions[] = "Tuan sering membuat tugas serupa di jam ini. Ingin dijadikan rutin?";
            $confidence = 60;
        }

        return [
            'name' => 'HabitEvolution',
            'confidence' => $confidence,
            'findings' => $findings,
            'suggestions' => $suggestions,
        ];
    }
}
