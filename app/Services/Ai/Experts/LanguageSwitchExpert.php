<?php
// app/Services/Ai/Experts/LanguageSwitchExpert.php

namespace App\Services\Ai\Experts;

use App\Services\Ai\ExpertInterface;

class LanguageSwitchExpert implements ExpertInterface
{
    public function evaluate(string $message, array $context): array
    {
        $findings = [];
        $confidence = 0;

        if (preg_match('/\b(ganti|switch|update|pake|pakai|gunakan)\s*(bahasa|language)\b/i', $message)) {
            $findings[] = "User ingin mengubah preferensi bahasa.";
            $confidence = 100;
        }

        return [
            'name' => 'LanguageSwitch',
            'confidence' => $confidence,
            'findings' => $findings,
            'suggestions' => ["Gunakan perintah 'Ganti bahasa ke [inggris/indonesia/jawa/sunda/betawi]'"],
        ];
    }
}
