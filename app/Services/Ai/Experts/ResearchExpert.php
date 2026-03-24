<?php

namespace App\Services\Ai\Experts;

use App\Services\Ai\ExpertInterface;
use Illuminate\Support\Str;

/**
 * v13.0 ResearchExpert - Specialized in searching for actionable info to convert into todos.
 * Restricted to productivity and task-related domains.
 */
class ResearchExpert implements ExpertInterface
{
    public function evaluate(string $message, array $context): array
    {
        $msg = mb_strtolower($message);
        $findings = [];
        $actions = [];
        $suggestions = [];
        $confidence = 0;

        // Keywords for research intent
        $researchKeywords = ['cara', 'bagaimana', 'how to', 'tutorial', 'langkah', 'steps', 'search for', 'cari info'];
        
        $isResearch = false;
        foreach ($researchKeywords as $kw) {
            if (Str::contains($msg, $kw)) {
                $isResearch = true;
                break;
            }
        }

        if ($isResearch) {
            $confidence = 75;
            $findings[] = "Kak sedang mencari informasi untuk sebuah kegiatan.";
            
            // Domain Firewall: Filter out dangerous or non-productivity queries
            if (Str::contains($msg, ['hack', 'crack', 'bom', 'senjata', 'weapon', 'illegal'])) {
                $findings[] = "Maaf, pencarian ini di luar domain produktivitas saya.";
                $confidence = 100;
            } else {
                $suggestions[] = "Buat daftar tugas dari hasil pencarian ini?";
                $suggestions[] = "Cari langkah-langkah detail";
                
                // Simulated Research Logic (v13.0)
                // In a real scenario, this would call a Web Search API
                $actions[] = [
                    'type' => 'ai_research',
                    'data' => [
                        'topic' => $message,
                        'restrict_to_todo' => true
                    ]
                ];
            }
        }

        return [
            'findings' => $findings,
            'actions' => $actions,
            'suggestions' => $suggestions,
            'confidence' => $confidence,
        ];
    }
}
