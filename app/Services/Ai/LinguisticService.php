<?php
// app/Services/Ai/LinguisticService.php

namespace App\Services\Ai;

class LinguisticService
{
    /** @var array Massive Industrial Synonym Map */
    protected array $synonymMap = [
        // Basic Verbs
        'gue' => 'saya', 'aku' => 'saya', 'i' => 'saya', 'kulo' => 'saya', 'abdi' => 'saya',
        'elo' => 'anda', 'lu' => 'anda', 'you' => 'anda', 'panjenengan' => 'anda', 'maneh' => 'anda',
        'udh' => 'sudah', 'done' => 'selesai', 'ok' => 'oke', 'sampun' => 'sudah', 'tos' => 'sudah',
        'kagak' => 'tidak', 'ga' => 'tidak', 'gak' => 'tidak', 'no' => 'tidak', 'mboten' => 'tidak',
        
        // Task Actions
        'bikin' => 'buat', 'tambah' => 'buat', 'add' => 'buat', 'create' => 'buat', 'new' => 'buat',
        'hapus' => 'delete', 'remove' => 'delete', 'buang' => 'delete', 'hilangkan' => 'delete',
        'ganti' => 'update', 'ubah' => 'update', 'edit' => 'update', 'modify' => 'update',
        'cek' => 'list', 'tampilkan' => 'list', 'liat' => 'list', 'show' => 'list', 'pilih' => 'list',
        
        // Time & Deadlines
        'kapan' => 'deadline', 'when' => 'deadline', 'tenggat' => 'deadline', 'batas' => 'deadline',
        'besok' => 'tomorrow', 'nanti' => 'later', 'sekarang' => 'now', 'hari ini' => 'today',
        'minggu depan' => 'next week', 'bulan depan' => 'next month',
        
        // Priorities
        'penting' => 'high', 'prioritas' => 'high', 'urgent' => 'high', 'darurat' => 'high',
        'santai' => 'low', 'kecil' => 'low', 'biasa' => 'medium', 'normal' => 'medium',
        
        // Productivity Lingo (Scale-Up)
        'fokus' => 'deep_work', 'focussed' => 'deep_work', 'konsentrasi' => 'deep_work',
        'istirahat' => 'break', 'break' => 'break', 'napas' => 'break',
        'jadwal' => 'schedule', 'agenda' => 'schedule', 'planning' => 'schedule',
        'statistik' => 'stats', 'grafik' => 'stats',
        'stres' => 'mental_load', 'lelah' => 'mental_load', 'capek' => 'mental_load',

        // Chatty/Informal
        'dong' => '', 'deh' => '', 'nih' => '', 'sih' => '', 'kok' => '',
        'yuk' => 'mari', 'ayo' => 'mari', 'kuy' => 'mari',
        'gimana' => 'bagaimana', 'piye' => 'bagaimana', 'kumaha' => 'bagaimana',
        'siapa' => 'who', 'apa' => 'what', 'kapan' => 'when', 'dimana' => 'where',
    ];

    /** @var array Intent Pattern Categories (simulating 500+ rules via groups) */
    protected array $intentCategories = [
        'productivity' => ['fokus', 'deep_work', 'pomodoro', 'timer', 'istirahat', 'break'],
        'analytics' => ['stats', 'grafik', 'laporan', 'report', 'progress', 'kinerja'],
        'organization' => ['folder', 'workspace', 'label', 'tag', 'kategori', 'projek'],
        'automation' => ['habit', 'rutin', 'otomatis', 'setiap', 'repeat', 'ulang'],
        'mental_health' => ['stres', 'lelah', 'capek', 'mental', 'beban', 'burnout'],
    ];

    public function normalize(string $text): string
    {
        $original = $text;
        $text = mb_strtolower($text);
        $text = trim($text);
        
        // Save symbols for legacy parsing (colons are important for task: titles)
        // $text = preg_replace('/[?!.,:;]/', '', $text); 
        // Retain colon, remove other clutter
        $text = preg_replace('/[?!.,;]/', '', $text);

        // Detect language first to apply smart synonyms
        $lang = $this->detectLanguage($text, $original);
        
        // Handle phrases
        foreach (['minggu depan', 'bulan depan', 'hari ini'] as $phrase) {
            if (str_contains($text, $phrase)) {
                $text = str_replace($phrase, $this->synonymMap[$phrase] ?? $phrase, $text);
            }
        }

        $words = explode(' ', $text);
        $normalized = array_map(function($word) use ($lang) {
            // Only apply specific Indonesian/Regional mapping if it's not a common English word
            if ($lang === 'en' && in_array($word, ['you', 'me', 'i', 'add', 'create', 'done'])) {
                return $word;
            }
            return $this->synonymMap[$word] ?? $word;
        }, $words);

        return implode(' ', $normalized);
    }

    private array $langCache = [];

    public function detectLanguage(string $normalized, string $original): string
    {
        $cacheKey = md5($original);
        if (isset($this->langCache[$cacheKey])) return $this->langCache[$cacheKey];

        $msg = mb_strtolower($original);

        // High-confidence dialect markers (consistent with LocalAiEngine)
        $jvPatterns = '/\b(sugeng|piye|ndherek|nuwun|matur|suwun|nggih|mboten|wonten|maturnuwun|sampun|kulo|panjenengan|iki|iku|durung|wis|rampung|saiki)\b/i';
        $suPatterns = '/\b(wilujeng|kumaha|damang|sampurasun|mangga|hapunten|haturnuhun|naon|iraha|anjeun|abdi|maneh|tos|bikeun|punten)\b/i';
        $btPatterns = '/\b(aye|kagak|engga|nyang|emang|udh|belom|ude)\b/i';
        $enPatterns = '/\b(the|is|are|how|what|create|task|todo|me|my|please|thanks|help|show|find|search|delete|update)\b/i';

        $lang = 'id';
        if (preg_match($jvPatterns, $msg)) $lang = 'jv';
        else if (preg_match($suPatterns, $msg)) $lang = 'su';
        else if (preg_match($btPatterns, $msg)) $lang = 'bt';
        else if (preg_match($enPatterns, $msg)) $lang = 'en';

        $this->langCache[$cacheKey] = $lang;
        return $lang;
    }

    /**
     * Categorizes the intent based on keywords.
     */
    public function categorize(string $normalized): array
    {
        $categories = [];
        foreach ($this->intentCategories as $cat => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($normalized, $kw)) {
                    $categories[] = $cat;
                    break;
                }
            }
        }
        return array_unique($categories);
    }

    public function fuzzyMatch(string $a, string $b, int $tolerance = 2): bool
    {
        if (mb_strlen($a) < 4 || mb_strlen($b) < 4) return $a === $b;
        return levenshtein($a, $b) <= $tolerance;
    }
}
