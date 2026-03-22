<?php
// app/Services/Ai/Experts/TimeAwareExpert.php

namespace App\Services\Ai\Experts;

use App\Services\Ai\ExpertInterface;
use Illuminate\Support\Carbon;

class TimeAwareExpert implements ExpertInterface
{
    public function evaluate(string $message, array $context): array
    {
        $findings = [];
        $suggestions = [];
        $confidence = 0;
        
        $hour = Carbon::now()->hour;

        if ($hour >= 23 || $hour < 5) {
            $findings[] = "Waktu menunjukkan larut malam.";
            $suggestions[] = "Sudah larut, istirahatlah. Pekerjaan bisa dilanjutkan besok.";
            $confidence = 70;
        }

        return [
            'name' => 'TimeAware',
            'confidence' => $confidence,
            'findings' => $findings,
            'suggestions' => $suggestions,
        ];
    }
}
