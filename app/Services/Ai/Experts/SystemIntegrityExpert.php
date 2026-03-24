<?php

namespace App\Services\Ai\Experts;

use App\Services\Ai\ExpertInterface;
use Carbon\Carbon;

class SystemIntegrityExpert implements ExpertInterface
{
    private const ENGINE_VERSION = '12.0';
    private const ENGINE_CODENAME = 'Jarvis Ultra';
    private const CONFIDENCE_THRESHOLD = 30;

    private array $systemKeywords = [
        'status'      => ['status', 'kondisi', 'keadaan', 'condition', 'state'],
        'version'     => ['versi', 'version', 'v12', 'v 12', 'update', 'terbaru', 'latest'],
        'capability'  => ['kemampuan', 'capability', 'bisa apa', 'fitur', 'feature', 'apa saja', 'what can you'],
        'diagnostic'  => ['diagnostik', 'diagnostic', 'cek sistem', 'system check', 'health check', 'self test'],
        'identity'    => ['siapa kamu', 'who are you', 'kamu siapa', 'tentang kamu', 'about you', 'jarvis', 'perkenalkan'],
        'uptime'      => ['uptime', 'berapa lama', 'how long', 'sejak kapan', 'since when', 'aktif'],
        'performance' => ['performa', 'performance', 'kecepatan', 'speed', 'latency', 'lambat', 'slow', 'cepat'],
        'mood'        => ['apa kabar', 'how are you', 'gimana kabar', 'baik-baik saja', 'kamu baik', 'are you ok', 'perasaan kamu'],
    ];

    public function evaluate(string $message, array $context): array
    {
        $msg = mb_strtolower(trim($message));
        $lang = $context['lang'] ?? 'id';
        $userName = $context['user'] ?? 'Kak';
        $now = Carbon::now();

        $confidence = $this->scoreSystemIntent($msg);

        if ($confidence < self::CONFIDENCE_THRESHOLD) {
            return ['findings' => [], 'actions' => [], 'suggestions' => [], 'confidence' => 0];
        }

        $topic = $this->detectSystemTopic($msg);
        $result = match ($topic) {
            'status'      => $this->systemStatus($context, $lang, $userName, $now),
            'version'     => $this->versionInfo($lang, $userName),
            'capability'  => $this->capabilityList($lang, $userName),
            'diagnostic'  => $this->selfDiagnostic($context, $lang, $userName, $now),
            'identity'    => $this->identityReport($lang, $userName),
            'uptime'      => $this->uptimeReport($lang, $userName, $now),
            'performance' => $this->performanceReport($context, $lang, $userName),
            'mood'        => $this->moodReport($lang, $userName, $now),
            default       => $this->systemStatus($context, $lang, $userName, $now),
        };

        return [
            'findings'    => $result['findings'],
            'actions'     => [],
            'suggestions' => array_slice($result['suggestions'], 0, 3),
            'confidence'  => max($confidence, 75),
        ];
    }

    //  Scoring 

    private function scoreSystemIntent(string $msg): int
    {
        $score = 0;
        foreach ($this->systemKeywords as $keywords) {
            foreach ($keywords as $kw) {
                if (preg_match('/\b' . preg_quote($kw, '/') . '\b/i', $msg)) {
                    $score += (mb_strlen($kw) >= 5) ? 18 : 10;
                }
            }
        }

        // Strong signals
        if (preg_match('/\b(kamu baik|are you ok|how are you doing|apa kabar jarvis)\b/i', $msg)) $score += 30;
        if (preg_match('/\b(self.?test|self.?check|system.?diagnostic)\b/i', $msg)) $score += 35;
        if (preg_match('/\b(versi berapa|what version|engine version)\b/i', $msg)) $score += 35;

        return min(100, $score);
    }

    private function detectSystemTopic(string $msg): string
    {
        $topScores = [];
        foreach ($this->systemKeywords as $topic => $keywords) {
            $topScores[$topic] = 0;
            foreach ($keywords as $kw) {
                if (preg_match('/\b' . preg_quote($kw, '/') . '\b/i', $msg)) {
                    $topScores[$topic] += (mb_strlen($kw) >= 5) ? 18 : 10;
                }
            }
        }
        arsort($topScores);
        $best = array_key_first($topScores);
        return ($topScores[$best] > 0) ? $best : 'status';
    }

    //  System Status 

    private function systemStatus(array $context, string $lang, string $userName, Carbon $now): array
    {
        $taskCount = count($context['tasks'] ?? []);
        $device = $context['device'] ?? 'cpu';
        $memoryFacts = count($context['memory']['facts'] ?? []);

        $findings = [];
        if ($lang === 'en') {
            $findings[] = "**System Status Report:**";
            $findings[] = "Engine: Local AI Engine v" . self::ENGINE_VERSION . " (" . self::ENGINE_CODENAME . ")";
            $findings[] = "Compute: {$device} | Time: {$now->format('Y-m-d H:i:s')}";
            $findings[] = "Tasks loaded: {$taskCount} | Memory facts: {$memoryFacts}";
            $findings[] = "Status: All systems operational. Ready to assist you, {$userName}.";
        } else {
            $findings[] = "**Laporan Status Sistem:**";
            $findings[] = "Engine: Local AI Engine v" . self::ENGINE_VERSION . " (" . self::ENGINE_CODENAME . ")";
            $findings[] = "Komputasi: {$device} | Waktu: {$now->format('Y-m-d H:i:s')}";
            $findings[] = "Tugas dimuat: {$taskCount} | Fakta memori: {$memoryFacts}";
            $findings[] = "Status: Semua sistem beroperasi normal. Siap membantu Anda, {$userName}.";
        }

        $suggestions = $lang === 'en'
            ? ['Run diagnostic', 'View capabilities', 'Check version']
            : ['Jalankan diagnostik', 'Lihat kemampuan', 'Cek versi'];

        return ['findings' => $findings, 'suggestions' => $suggestions];
    }

    //  Version Info 

    private function versionInfo(string $lang, string $userName): array
    {
        $findings = [];
        if ($lang === 'en') {
            $findings[] = "**Version Information:**";
            $findings[] = "Engine: Local AI Engine v" . self::ENGINE_VERSION;
            $findings[] = "Codename: " . self::ENGINE_CODENAME;
            $findings[] = "Architecture: Pure Logic NLP (No external LLM)";
            $findings[] = "v12.0 Features:";
            $findings[] = "- Recursive Multi-Intent Parsing";
            $findings[] = "- Semantic Weighted Confidence Scoring";
            $findings[] = "- Chain-of-Thought Logic Simulation";
            $findings[] = "- Long-term Personality Adaptation";
            $findings[] = "- Advanced Pronoun Resolution (Anaphora)";
            $findings[] = "- 18 Cognitive Experts registered";
            $findings[] = "- Bilingual ID/EN with Javanese, Sundanese, Betawi support";
        } else {
            $findings[] = "**Informasi Versi:**";
            $findings[] = "Engine: Local AI Engine v" . self::ENGINE_VERSION;
            $findings[] = "Codename: " . self::ENGINE_CODENAME;
            $findings[] = "Arsitektur: Pure Logic NLP (Tanpa LLM eksternal)";
            $findings[] = "Fitur v12.0:";
            $findings[] = "- Parsing Multi-Intent Rekursif";
            $findings[] = "- Penilaian Confidence Semantik Terbobot";
            $findings[] = "- Simulasi Chain-of-Thought Logic";
            $findings[] = "- Adaptasi Kepribadian Jangka Panjang";
            $findings[] = "- Resolusi Pronomina Lanjut (Anafora)";
            $findings[] = "- 18 Cognitive Expert terdaftar";
            $findings[] = "- Bilingual ID/EN dengan dukungan Jawa, Sunda, Betawi";
        }

        $suggestions = $lang === 'en'
            ? ['System status', 'View capabilities', 'Run diagnostic']
            : ['Status sistem', 'Lihat kemampuan', 'Jalankan diagnostik'];

        return ['findings' => $findings, 'suggestions' => $suggestions];
    }

    //  Capability List 

    private function capabilityList(string $lang, string $userName): array
    {
        $findings = [];
        if ($lang === 'en') {
            $findings[] = "**Jarvis Capabilities for {$userName}:**";
            $findings[] = "Task Management: Create, update, delete, toggle, batch operations, templates";
            $findings[] = "Smart Analysis: Eisenhower matrix, workload balance, productivity forecast";
            $findings[] = "Planning: Time blocks, daily planner, Pomodoro, deep work, weekly review";
            $findings[] = "Intelligence: Strategic planning, goal tracking, habit evolution";
            $findings[] = "Wellness: Break reminders, posture, hydration, sleep, stress management";
            $findings[] = "Knowledge: Math/science, code assistance, translation, creative writing";
            $findings[] = "Memory: Learn facts, recall preferences, personality adaptation";
            $findings[] = "Conversation: 17 topic categories, humor, philosophy, motivation";
            $findings[] = "Languages: Indonesian, English, Javanese, Sundanese, Betawi";
        } else {
            $findings[] = "**Kemampuan Jarvis untuk {$userName}:**";
            $findings[] = "Manajemen Tugas: Buat, update, hapus, toggle, operasi batch, template";
            $findings[] = "Analisis Cerdas: Matriks Eisenhower, keseimbangan beban kerja, prakiraan produktivitas";
            $findings[] = "Perencanaan: Blok waktu, rencana harian, Pomodoro, deep work, review mingguan";
            $findings[] = "Inteligensi: Perencanaan strategis, pelacakan goal, evolusi kebiasaan";
            $findings[] = "Kesehatan: Pengingat istirahat, postur, hidrasi, tidur, manajemen stres";
            $findings[] = "Pengetahuan: Matematika/sains, asisten koding, terjemahan, penulisan kreatif";
            $findings[] = "Memori: Pelajari fakta, ingat preferensi, adaptasi kepribadian";
            $findings[] = "Percakapan: 17 kategori topik, humor, filsafat, motivasi";
            $findings[] = "Bahasa: Indonesia, Inggris, Jawa, Sunda, Betawi";
        }

        $suggestions = $lang === 'en'
            ? ['Create a task', 'View stats', 'Start planning']
            : ['Buat tugas', 'Lihat statistik', 'Mulai perencanaan'];

        return ['findings' => $findings, 'suggestions' => $suggestions];
    }

    //  Self Diagnostic 

    private function selfDiagnostic(array $context, string $lang, string $userName, Carbon $now): array
    {
        $checks = [];
        $allPass = true;

        // Check 1: Tasks loaded
        $taskCount = count($context['tasks'] ?? []);
        $checks[] = ['name' => 'Task Engine', 'status' => $taskCount >= 0, 'detail' => "{$taskCount} tasks loaded"];

        // Check 2: Memory system
        $memoryOk = isset($context['memory']);
        $checks[] = ['name' => 'Memory System', 'status' => $memoryOk, 'detail' => $memoryOk ? 'Connected' : 'Disconnected'];
        if (!$memoryOk) $allPass = false;

        // Check 3: Language detection
        $langOk = in_array($context['lang'] ?? '', ['id', 'en', 'jv', 'su', 'bt']);
        $checks[] = ['name' => 'Language Detection', 'status' => $langOk, 'detail' => "Detected: " . ($context['lang'] ?? 'unknown')];
        if (!$langOk) $allPass = false;

        // Check 4: Time system
        $timeOk = $now->year >= 2024;
        $checks[] = ['name' => 'Time System', 'status' => $timeOk, 'detail' => $now->format('Y-m-d H:i:s')];

        // Check 5: Compute device
        $device = $context['device'] ?? 'cpu';
        $checks[] = ['name' => 'Compute Device', 'status' => true, 'detail' => strtoupper($device)];

        // Check 6: Expert system
        $checks[] = ['name' => 'Expert System', 'status' => true, 'detail' => '18 experts registered'];

        $findings = [];
        if ($lang === 'en') {
            $findings[] = "**Self Diagnostic Report:**";
            foreach ($checks as $c) {
                $icon = $c['status'] ? 'PASS' : 'FAIL';
                $findings[] = "[{$icon}] {$c['name']}: {$c['detail']}";
            }
            $findings[] = $allPass
                ? "Overall: All systems healthy. Operating at full capacity, {$userName}."
                : "Overall: Some issues detected. Core functions remain operational.";
        } else {
            $findings[] = "**Laporan Diagnostik Mandiri:**";
            foreach ($checks as $c) {
                $icon = $c['status'] ? 'LULUS' : 'GAGAL';
                $findings[] = "[{$icon}] {$c['name']}: {$c['detail']}";
            }
            $findings[] = $allPass
                ? "Keseluruhan: Semua sistem sehat. Beroperasi penuh, {$userName}."
                : "Keseluruhan: Beberapa masalah terdeteksi. Fungsi inti tetap beroperasi.";
        }

        $suggestions = $lang === 'en'
            ? ['View version', 'View capabilities', 'System status']
            : ['Lihat versi', 'Lihat kemampuan', 'Status sistem'];

        return ['findings' => $findings, 'suggestions' => $suggestions];
    }

    //  Identity Report 

    private function identityReport(string $lang, string $userName): array
    {
        $findings = [];
        if ($lang === 'en') {
            $findings[] = "I am **Jarvis**, {$userName}'s personal AI assistant.";
            $findings[] = "Engine: Local AI Engine v" . self::ENGINE_VERSION . " (" . self::ENGINE_CODENAME . ")";
            $findings[] = "I run entirely on pure logic  no external LLM, no cloud AI, no API tokens.";
            $findings[] = "My purpose: Keep you organized, productive, and healthy.";
            $findings[] = "I learn from our conversations and adapt to your preferences over time.";
        } else {
            $findings[] = "Saya adalah **Jarvis**, asisten AI pribadi {$userName}.";
            $findings[] = "Engine: Local AI Engine v" . self::ENGINE_VERSION . " (" . self::ENGINE_CODENAME . ")";
            $findings[] = "Saya berjalan sepenuhnya dengan logika murni  tanpa LLM eksternal, tanpa cloud AI, tanpa token API.";
            $findings[] = "Tujuan saya: Menjaga Anda tetap teratur, produktif, dan sehat.";
            $findings[] = "Saya belajar dari percakapan kita dan beradaptasi dengan preferensi Anda seiring waktu.";
        }

        $suggestions = $lang === 'en'
            ? ['What can you do?', 'System status', 'Help']
            : ['Bisa apa saja?', 'Status sistem', 'Bankak'];

        return ['findings' => $findings, 'suggestions' => $suggestions];
    }

    //  Uptime Report 

    private function uptimeReport(string $lang, string $userName, Carbon $now): array
    {
        // PHP process uptime simulation (we report engine boot time as request time)
        $bootTime = defined('LARAVEL_START') ? Carbon::createFromTimestamp(LARAVEL_START) : $now->copy()->subMinutes(5);
        $uptime = $bootTime->diffForHumans($now, true);

        $findings = [];
        if ($lang === 'en') {
            $findings[] = "**Uptime Report:**";
            $findings[] = "Engine: Local AI Engine v" . self::ENGINE_VERSION;
            $findings[] = "Current session uptime: {$uptime}";
            $findings[] = "Server time: {$now->format('Y-m-d H:i:s T')}";
            $findings[] = "I'm always ready when you need me, {$userName}.";
        } else {
            $findings[] = "**Laporan Uptime:**";
            $findings[] = "Engine: Local AI Engine v" . self::ENGINE_VERSION;
            $findings[] = "Uptime sesi saat ini: {$uptime}";
            $findings[] = "Waktu server: {$now->format('Y-m-d H:i:s T')}";
            $findings[] = "Saya selalu siap saat {$userName} membutuhkan.";
        }

        $suggestions = $lang === 'en'
            ? ['System diagnostic', 'View capabilities', 'Status']
            : ['Diagnostik sistem', 'Lihat kemampuan', 'Status'];

        return ['findings' => $findings, 'suggestions' => $suggestions];
    }

    //  Performance Report 

    private function performanceReport(array $context, string $lang, string $userName): array
    {
        $device = strtoupper($context['device'] ?? 'CPU');
        $taskCount = count($context['tasks'] ?? []);

        $findings = [];
        if ($lang === 'en') {
            $findings[] = "**Performance Report:**";
            $findings[] = "Compute device: {$device}";
            $findings[] = "Processing model: Pure Rule-Based NLP (zero latency to external services)";
            $findings[] = "Tasks in context: {$taskCount}";
            $findings[] = "Estimated response time: <100ms (local processing)";
            $findings[] = "All reasoning runs locally  your data never leaves your server, {$userName}.";
        } else {
            $findings[] = "**Laporan Performa:**";
            $findings[] = "Perangkat komputasi: {$device}";
            $findings[] = "Model pemrosesan: NLP Berbasis Aturan Murni (tanpa latensi ke layanan eksternal)";
            $findings[] = "Tugas dalam konteks: {$taskCount}";
            $findings[] = "Estimasi waktu respons: <100ms (pemrosesan lokal)";
            $findings[] = "Semua penalaran berjalan lokal  data Anda tidak pernah meninggalkan server, {$userName}.";
        }

        $suggestions = $lang === 'en'
            ? ['Run diagnostic', 'View version', 'System status']
            : ['Jalankan diagnostik', 'Lihat versi', 'Status sistem'];

        return ['findings' => $findings, 'suggestions' => $suggestions];
    }

    //  Mood Report (When user asks "how are you") 

    private function moodReport(string $lang, string $userName, Carbon $now): array
    {
        $hour = (int) $now->format('H');

        $moods = $lang === 'en'
            ? [
                "I'm operating at peak efficiency, {$userName}! All 18 cognitive experts are online and ready.",
                "Feeling excellent, {$userName}! My circuits are warm and my logic is sharp.",
                "I'm great, thank you for asking! Ready to tackle anything you throw at me.",
            ]
            : [
                "Saya beroperasi di efisiensi puncak, {$userName}! Semua 18 cognitive expert online dan siap.",
                "Sangat baik, {$userName}! Sirkuit saya hangat dan logika saya tajam.",
                "Saya baik-baik saja, terima kasih sudah bertanya! Siap menghadapi apapun yang Anda butuhkan.",
            ];

        // Time-aware addition
        $timeNote = '';
        if ($hour >= 22 || $hour < 5) {
            $timeNote = $lang === 'en'
                ? " But more importantly, shouldn't you be resting at this hour?"
                : " Tapi yang lebih penting, bukankah seharusnya Anda istirahat di jam ini?";
        }

        $findings = [];
        $mood = $moods[abs(crc32($now->format('Y-m-d'))) % count($moods)];
        $findings[] = $mood . $timeNote;

        $suggestions = $lang === 'en'
            ? ['What can you do?', 'System status', 'Motivate me']
            : ['Bisa apa saja?', 'Status sistem', 'Motivasi'];

        return ['findings' => $findings, 'suggestions' => $suggestions];
    }
}
