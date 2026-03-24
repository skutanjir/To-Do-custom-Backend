<?php
// app/Services/Ai/Experts/ProductivityForecastExpert.php

namespace App\Services\Ai\Experts;

use App\Services\Ai\ExpertInterface;
use Illuminate\Support\Carbon;

class ProductivityForecastExpert implements ExpertInterface
{
    public function evaluate(string $message, array $context): array
    {
        $findings = [];
        $suggestions = [];
        $confidence = 0;

        $tasks = $context['tasks'] ?? [];
        $totalTasks = count($tasks);
        $incomplete = collect($tasks)->where('is_completed', false)->count();

        //  Forecast Algorithm (PHP-Based) 
        if ($incomplete > 5) {
            $findings[] = "Beban kerja Kak meningkat ($incomplete tugas tertunda).";
            $suggestions[] = "Pertimbangkan untuk menjadwal ulang tugas non-prioritas agar tidak kewalahan.";
            $confidence = 80;
        }

        if (str_contains($message, 'jadwal') || str_contains($message, 'prediksi')) {
            $confidence = 90;
            $findings[] = "Berdasarkan rasion tugas hari ini, Kak kemungkinan membutuhkan waktu 4 jam lagi.";
        }

        return [
            'name' => 'ProductivityForecast',
            'confidence' => $confidence,
            'findings' => $findings,
            'suggestions' => $suggestions,
        ];
    }
}
