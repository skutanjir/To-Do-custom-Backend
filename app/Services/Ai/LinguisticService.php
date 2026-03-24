<?php
// app/Services/Ai/LinguisticService.php

namespace App\Services\Ai;

class LinguisticService
{
    /** @var array Massive Industrial Synonym Map */
    protected array $synonymMap = [
        // Basic Verbs & Gen Z Pronouns
        'gue' => 'saya', 'aku' => 'saya', 'i' => 'saya', 'kulo' => 'saya', 'abdi' => 'saya', 'gw' => 'saya', 'w' => 'saya', 'we' => 'saya', 'aq' => 'saya', 'sy' => 'saya', 'sya' => 'saya', 'saia' => 'saya',
        'elo' => 'anda', 'lu' => 'anda', 'you' => 'anda', 'panjenengan' => 'anda', 'maneh' => 'anda', 'lo' => 'anda', 'lw' => 'anda', 'u' => 'anda', 'kmu' => 'anda', 'km' => 'anda', 'klo' => 'kalau', 'kalo' => 'kalau', 'kl' => 'kalau',
        'udh' => 'sudah', 'done' => 'selesai', 'ok' => 'oke', 'sampun' => 'sudah', 'tos' => 'sudah', 'dah' => 'sudah', 'sdh' => 'sudah', 'udah' => 'sudah', 'blm' => 'belum', 'blom' => 'belum',
        'kagak' => 'tidak', 'ga' => 'tidak', 'gak' => 'tidak', 'no' => 'tidak', 'mboten' => 'tidak', 'ngga' => 'tidak', 'nggak' => 'tidak', 'ndak' => 'tidak', 'tdk' => 'tidak',
        'y' => 'ya', 'iya' => 'ya', 'yup' => 'ya', 'yep' => 'ya', 'hooh' => 'ya', 'yoi' => 'ya', 'iyh' => 'ya', 'iy' => 'ya', 'yo' => 'ya',
        
        // Task Actions & Lazy Typos (bikin, buat, tolong)
        'bikin' => 'buat', 'tambah' => 'buat', 'add' => 'buat', 'create' => 'buat', 'new' => 'buat',
        'bkin' => 'buat', 'biknn' => 'buat', 'bwat' => 'buat', 'buwat' => 'buat', 'buatt' => 'buat', 'bkinin' => 'buat', 'buatin' => 'buat', 'bikinin' => 'buat', 'tmbhin' => 'buat',
        'tolong' => 'bantu', 'tlg' => 'bantu', 'pls' => 'bantu', 'plis' => 'bantu', 'pliss' => 'bantu', 'bantuin' => 'bantu', 'bant' => 'bantu',
        'hapus' => 'delete', 'remove' => 'delete', 'buang' => 'delete', 'hilangkan' => 'delete',
        'hps' => 'delete', 'apus' => 'delete', 'del' => 'delete', 'dlt' => 'delete', 'ilangin' => 'delete', 'hpsn' => 'delete',
        'ganti' => 'update', 'ubah' => 'update', 'edit' => 'update', 'modify' => 'update',
        'benerin' => 'update', 'bener' => 'update', 'revisi' => 'update', 'gnti' => 'update', 'ubh' => 'update', 'rvs' => 'update', 'updet' => 'update',
        'cek' => 'list', 'tampilkan' => 'list', 'liat' => 'list', 'show' => 'list', 'pilih' => 'list',
        'lihat' => 'list', 'tampil' => 'list', 'cari' => 'list', 'liatin' => 'list', 'ceki' => 'list', 'shw' => 'list',
        
        // Task Keywords & Gen Z Words
        'tugas' => 'tugas', 'tgas' => 'tugas', 'tuges' => 'tugas', 'tgss' => 'tugas', 'task' => 'tugas',
        'kerjaan' => 'tugas', 'pr' => 'tugas', 'gawean' => 'tugas', 'project' => 'tugas', 'proj' => 'tugas', 'krjaan' => 'tugas',
        
        // Time & Deadlines (Extreme Abbrev)
        'kapan' => 'deadline', 'when' => 'deadline', 'tenggat' => 'deadline', 'batas' => 'deadline',
        'kpn' => 'deadline', 'dedlin' => 'deadline', 'dl' => 'deadline', 'ddln' => 'deadline', 'dline' => 'deadline', 'bts' => 'deadline',
        'besok' => 'tomorrow', 'bsk' => 'tomorrow', 'bso' => 'tomorrow', 'bsknya' => 'tomorrow', 'tmrw' => 'tomorrow',
        'nanti' => 'later', 'nt' => 'later', 'ntar' => 'later', 'ntr' => 'later', 'ltr' => 'later',
        'sekarang' => 'now', 'skrg' => 'now', 'skrng' => 'now', 'rn' => 'now',
        'hari ini' => 'today', 'hr ini' => 'today', 'hri ini' => 'today', 'hrni' => 'today', 'td' => 'today', 'tdy' => 'today',
        'minggu depan' => 'next week', 'bulan depan' => 'next month', 'mg dpn' => 'next week', 'mgu dpn' => 'next week', 'bln dpn' => 'next month',
        
        // Priorities
        'penting' => 'high', 'prioritas' => 'high', 'urgent' => 'high', 'darurat' => 'high',
        'pntg' => 'high', 'ptg' => 'high', 'pnting' => 'high', 'asap' => 'high', 'cpt' => 'high', 'cepet' => 'high',
        'santai' => 'low', 'kecil' => 'low', 'biasa' => 'medium', 'normal' => 'medium',
        'sntai' => 'low', 'slow' => 'low', 'selow' => 'low', 'woles' => 'low', 'chill' => 'low',
        'b aja' => 'medium', 'biasa aja' => 'medium', 'norm' => 'medium', 'nrml' => 'medium',
        
        // Productivity Lingo (Scale-Up)
        'fokus' => 'deep_work', 'focussed' => 'deep_work', 'konsentrasi' => 'deep_work',
        'istirahat' => 'break', 'break' => 'break', 'napas' => 'break', 'rehat' => 'break',
        'jadwal' => 'schedule', 'agenda' => 'schedule', 'planning' => 'schedule',
        'statistik' => 'stats', 'grafik' => 'stats',
        'stres' => 'mental_load', 'lelah' => 'mental_load', 'capek' => 'mental_load', 'pusing' => 'mental_load',

        // Chatty/Informal Fillers (Filtered out or translated safely)
        'dong' => '', 'deh' => '', 'nih' => '', 'sih' => '', 'kok' => '',
        'anjir' => '', 'njir' => '', 'jir' => '', 'bjir' => '', 'buset' => '',
        'cuy' => 'kawan', 'ngab' => 'kawan', 'brok' => 'kawan', 'ges' => 'kawan', 'guys' => 'kawan', 'bro' => 'kawan', 'sis' => 'kawan',
        'btw' => 'ngomong-ngomong', 'fyi' => 'informasi', 'afh' => 'apa', 'bgmn' => 'bagaimana',
        'yuk' => 'mari', 'ayo' => 'mari', 'kuy' => 'mari',
        'gimana' => 'bagaimana', 'piye' => 'bagaimana', 'kumaha' => 'bagaimana', 'gmn' => 'bagaimana',
        'siapa' => 'who', 'syp' => 'who', 'apa' => 'what', 'kapan' => 'when', 'dimana' => 'where', 'dmn' => 'where',
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
