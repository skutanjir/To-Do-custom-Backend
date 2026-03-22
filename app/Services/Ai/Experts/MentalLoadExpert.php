<?php
// app/Services/Ai/Experts/MentalLoadExpert.php

namespace App\Services\Ai\Experts;

use App\Services\Ai\ExpertInterface;

class MentalLoadExpert implements ExpertInterface
{
    public function evaluate(string $message, array $context): array
    {
        $findings = [];
        $suggestions = [];
        $confidence = 0;

        $tasks = collect($context['tasks'] ?? []);
        $highPriority = $tasks->where('priority', 'high')->where('is_completed', false)->count();
        $overdue = $tasks->filter(function($t) {
            return !$t['is_completed'] && isset($t['deadline']) && strtotime($t['deadline']) < time();
        })->count();

        // ── Mental Load Score Calculation ───────────────────────────
        $loadScore = ($highPriority * 15) + ($overdue * 25);

        if ($loadScore > 50) {
            $findings[] = "Skor beban mental Tuan berada di angka $loadScore (Tinggi).";
            $suggestions[] = "Gunakan mode 'Deep Work' untuk fokus pada satu tugas besar.";
            $confidence = 95;
        }

        if (str_contains($message, 'lelah') || str_contains($message, 'stres')) {
            $confidence = 100;
            $findings[] = "Saya mendeteksi indikasi kelelahan kognitif.";
        }

        return [
            'name' => 'MentalLoad',
            'confidence' => $confidence,
            'findings' => $findings,
            'suggestions' => $suggestions,
        ];
    }
}
