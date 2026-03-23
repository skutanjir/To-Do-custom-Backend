<?php
// app/Services/Ai/Intents/PredictiveIntentHandler.php

namespace App\Services\Ai\Intents;

use App\Models\Task\Todo;
use Illuminate\Support\Str;

class PredictiveIntentHandler implements IntentHandler
{
    /**
     * Scores the intent for Predictive Analytics and productivity reviews.
     */
    public function score(string $msg, array $context): int
    {
        $patterns = [
            '/\b(analisa|review|statistika|heatmap|burnout|produktivitas)\b/i' => 85,
            '/\b(evaluasi|kinerja|performa|summary)\b/i' => 80,
            '/\blaporan\s+(mingguan|harian|bulanan)\b/i' => 95,
        ];

        foreach ($patterns as $pattern => $score) {
            if (preg_match($pattern, $msg)) return $score;
        }

        return 0;
    }

    /**
     * Handles Predictive Analytics operations.
     */
    public function handle(string $msg, array $context): array
    {
        $user = $context['user'];
        
        $reportType = 'standard';
        if (preg_match('/\b(mingguan|weekly)\b/i', $msg)) $reportType = 'weekly';
        if (preg_match('/\b(harian|daily)\b/i', $msg)) $reportType = 'daily';
        if (preg_match('/\b(burnout|lelah|stres)\b/i', $msg)) $reportType = 'burnout_risk';

        return [
            'intent' => 'productivity_analytics',
            'content' => json_encode([
                'message' => "Synthesizing industrial productivity data for your \"{$reportType}\" review. Jarvis is identifying efficiency bottlenecks.",
                'action' => [
                    'type' => 'generate_analytics_report',
                    'data' => [
                        'type' => $reportType,
                        'period' => 'last_7_days'
                    ]
                ]
            ])
        ];
    }
}
