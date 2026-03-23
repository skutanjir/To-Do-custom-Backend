<?php

namespace App\Services\Ai\Experts;

use App\Services\Ai\ExpertInterface;
use Carbon\Carbon;

class StrategicPlannerExpert implements ExpertInterface
{
    private const CONFIDENCE_THRESHOLD = 25;

    private array $planningKeywords = [
        'strategy'    => ['strategi', 'strategy', 'rencana', 'plan', 'planning', 'perencanaan'],
        'timeblock'   => ['time block', 'timeblock', 'blok waktu', 'jadwal kerja', 'work schedule', 'alokasi waktu'],
        'eisenhower'  => ['eisenhower', 'matriks', 'matrix', 'kuadran', 'quadrant', 'urgent', 'important', 'penting', 'mendesak'],
        'weekly'      => ['weekly', 'mingguan', 'minggu ini', 'this week', 'rencana minggu', 'weekly plan', 'weekly goal'],
        'workload'    => ['workload', 'beban kerja', 'kapasitas', 'capacity', 'overload', 'kewalahan', 'terlalu banyak', 'too much'],
        'priority'    => ['prioritas', 'priority', 'yang paling penting', 'most important', 'utama', 'main focus'],
        'goal'        => ['goal', 'target', 'tujuan', 'objective', 'sasaran', 'milestone'],
        'delegate'    => ['delegasi', 'delegate', 'serahkan', 'hand off', 'assign'],
    ];

    public function evaluate(string $message, array $context): array
    {
        $msg = mb_strtolower(trim($message));
        $lang = $context['lang'] ?? 'id';
        $userName = $context['user'] ?? 'Tuan';
        $tasks = $context['tasks'] ?? [];
        $now = Carbon::now();

        $confidence = $this->scorePlanningIntent($msg);

        if ($confidence < self::CONFIDENCE_THRESHOLD) {
            return ['findings' => [], 'actions' => [], 'suggestions' => [], 'confidence' => 0];
        }

        $topic = $this->detectPlanningTopic($msg);
        $findings = [];
        $actions = [];
        $suggestions = [];

        switch ($topic) {
            case 'eisenhower':
                $result = $this->analyzeEisenhower($tasks, $lang, $userName);
                $findings = $result['findings'];
                $suggestions = $result['suggestions'];
                $confidence = max($confidence, 85);
                break;

            case 'timeblock':
                $result = $this->generateTimeBlocks($tasks, $now, $lang, $userName);
                $findings = $result['findings'];
                $suggestions = $result['suggestions'];
                $confidence = max($confidence, 80);
                break;

            case 'weekly':
                $result = $this->weeklyPlanAnalysis($tasks, $now, $lang, $userName);
                $findings = $result['findings'];
                $suggestions = $result['suggestions'];
                $confidence = max($confidence, 78);
                break;

            case 'workload':
                $result = $this->workloadBalance($tasks, $lang, $userName);
                $findings = $result['findings'];
                $actions = $result['actions'];
                $suggestions = $result['suggestions'];
                $confidence = max($confidence, 82);
                break;

            case 'goal':
                $result = $this->goalStrategy($tasks, $lang, $userName);
                $findings = $result['findings'];
                $suggestions = $result['suggestions'];
                $confidence = max($confidence, 75);
                break;

            case 'delegate':
                $result = $this->delegationAdvice($tasks, $lang, $userName);
                $findings = $result['findings'];
                $suggestions = $result['suggestions'];
                $confidence = max($confidence, 70);
                break;

            default:
                $result = $this->generalStrategy($tasks, $lang, $userName);
                $findings = $result['findings'];
                $suggestions = $result['suggestions'];
                $confidence = max($confidence, 60);
                break;
        }

        return [
            'findings'    => $findings,
            'actions'     => $actions,
            'suggestions' => array_slice($suggestions, 0, 3),
            'confidence'  => $confidence,
        ];
    }

    //  Scoring 

    private function scorePlanningIntent(string $msg): int
    {
        $score = 0;
        foreach ($this->planningKeywords as $keywords) {
            foreach ($keywords as $kw) {
                if (preg_match('/\b' . preg_quote($kw, '/') . '\b/i', $msg)) {
                    $score += (mb_strlen($kw) >= 6) ? 18 : 10;
                }
            }
        }

        if (preg_match('/\b(bagaimana|how|gimana|cara|way)\b.*\b(atur|manage|organize|susun|kelola)\b/i', $msg)) $score += 20;
        if (preg_match('/\b(optimalkan|optimize|efisien|efficient|produktif|productive)\b/i', $msg)) $score += 15;

        return min(100, $score);
    }

    private function detectPlanningTopic(string $msg): string
    {
        $topScores = [];
        foreach ($this->planningKeywords as $topic => $keywords) {
            $topScores[$topic] = 0;
            foreach ($keywords as $kw) {
                if (preg_match('/\b' . preg_quote($kw, '/') . '\b/i', $msg)) {
                    $topScores[$topic] += (mb_strlen($kw) >= 6) ? 18 : 10;
                }
            }
        }
        arsort($topScores);
        $best = array_key_first($topScores);
        return ($topScores[$best] > 0) ? $best : 'strategy';
    }

    //  Eisenhower Matrix 

    private function analyzeEisenhower(array $tasks, string $lang, string $userName): array
    {
        $q1 = $q2 = $q3 = $q4 = [];

        foreach ($tasks as $task) {
            if ($task['is_completed'] ?? false) continue;

            $isUrgent = false;
            $isImportant = false;

            // Urgency: has deadline within 2 days or overdue
            if (!empty($task['deadline'])) {
                $deadline = Carbon::parse($task['deadline']);
                $isUrgent = $deadline->isPast() || $deadline->diffInDays(Carbon::now()) <= 2;
            }

            // Importance: high/critical priority
            $priority = strtolower($task['priority'] ?? 'medium');
            $isImportant = in_array($priority, ['high', 'critical', 'tinggi', 'kritis']);

            $title = $task['judul'] ?? 'Untitled';

            if ($isUrgent && $isImportant) $q1[] = $title;
            elseif (!$isUrgent && $isImportant) $q2[] = $title;
            elseif ($isUrgent && !$isImportant) $q3[] = $title;
            else $q4[] = $title;
        }

        $findings = [];

        if ($lang === 'en') {
            $findings[] = "**Eisenhower Matrix for {$userName}:**";
            $findings[] = "Q1 (Do Now - Urgent & Important): " . (empty($q1) ? 'None' : implode(', ', $q1));
            $findings[] = "Q2 (Schedule - Important, Not Urgent): " . (empty($q2) ? 'None' : implode(', ', $q2));
            $findings[] = "Q3 (Delegate - Urgent, Not Important): " . (empty($q3) ? 'None' : implode(', ', $q3));
            $findings[] = "Q4 (Eliminate - Neither): " . (empty($q4) ? 'None' : implode(', ', $q4));

            if (count($q1) >= 3) {
                $findings[] = "Warning: You have " . count($q1) . " tasks in Q1. Consider delegating or rescheduling Q3 tasks to free up capacity.";
            }
        } else {
            $findings[] = "**Matriks Eisenhower untuk {$userName}:**";
            $findings[] = "Q1 (Kerjakan Sekarang - Urgent & Penting): " . (empty($q1) ? 'Kosong' : implode(', ', $q1));
            $findings[] = "Q2 (Jadwalkan - Penting, Tidak Urgent): " . (empty($q2) ? 'Kosong' : implode(', ', $q2));
            $findings[] = "Q3 (Delegasikan - Urgent, Tidak Penting): " . (empty($q3) ? 'Kosong' : implode(', ', $q3));
            $findings[] = "Q4 (Eliminasi - Tidak Keduanya): " . (empty($q4) ? 'Kosong' : implode(', ', $q4));

            if (count($q1) >= 3) {
                $findings[] = "Peringatan: Ada " . count($q1) . " tugas di Q1. Pertimbangkan untuk mendelegasikan tugas Q3 agar kapasitas lebih lega.";
            }
        }

        $suggestions = $lang === 'en'
            ? ['Focus on Q1 tasks first', 'Schedule Q2 tasks', 'Review workload']
            : ['Fokus Q1 dulu', 'Jadwalkan tugas Q2', 'Review beban kerja'];

        return ['findings' => $findings, 'suggestions' => $suggestions];
    }

    //  Time Block Generator 

    private function generateTimeBlocks(array $tasks, Carbon $now, string $lang, string $userName): array
    {
        $pendingTasks = array_filter($tasks, fn($t) => !($t['is_completed'] ?? false));

        // Sort by priority (high first) then by deadline (soonest first)
        usort($pendingTasks, function ($a, $b) {
            $priorityOrder = ['critical' => 0, 'kritis' => 0, 'high' => 1, 'tinggi' => 1, 'medium' => 2, 'sedang' => 2, 'low' => 3, 'rendah' => 3];
            $pA = $priorityOrder[strtolower($a['priority'] ?? 'medium')] ?? 2;
            $pB = $priorityOrder[strtolower($b['priority'] ?? 'medium')] ?? 2;
            if ($pA !== $pB) return $pA - $pB;

            $dA = $a['deadline'] ?? '9999-12-31';
            $dB = $b['deadline'] ?? '9999-12-31';
            return strcmp($dA, $dB);
        });

        $blocks = [];
        $startHour = max(8, (int) $now->format('H'));
        $blockMinutes = 90; // 90-minute deep work blocks
        $breakMinutes = 15;

        $slotIndex = 0;
        foreach (array_slice($pendingTasks, 0, 5) as $task) {
            $blockStart = Carbon::today()->setHour($startHour)->addMinutes($slotIndex * ($blockMinutes + $breakMinutes));
            $blockEnd = $blockStart->copy()->addMinutes($blockMinutes);

            $title = $task['judul'] ?? 'Untitled';
            $priority = $task['priority'] ?? 'medium';

            $blocks[] = $blockStart->format('H:i') . '-' . $blockEnd->format('H:i') . " | {$title} [{$priority}]";
            $slotIndex++;
        }

        $findings = [];
        if ($lang === 'en') {
            $findings[] = "**Optimized Time Blocks for {$userName}:**";
            $findings[] = "Using 90-minute deep work blocks with 15-min breaks:";
            foreach ($blocks as $b) $findings[] = "  {$b}";
            if (empty($blocks)) $findings[] = "No pending tasks to schedule.";
        } else {
            $findings[] = "**Blok Waktu Optimal untuk {$userName}:**";
            $findings[] = "Menggunakan blok deep work 90 menit + istirahat 15 menit:";
            foreach ($blocks as $b) $findings[] = "  {$b}";
            if (empty($blocks)) $findings[] = "Tidak ada tugas pending untuk dijadwalkan.";
        }

        $suggestions = $lang === 'en'
            ? ['Start first block now', 'Enable Pomodoro', 'Review Eisenhower matrix']
            : ['Mulai blok pertama', 'Aktifkan Pomodoro', 'Lihat matriks Eisenhower'];

        return ['findings' => $findings, 'suggestions' => $suggestions];
    }

    //  Weekly Plan Analysis 

    private function weeklyPlanAnalysis(array $tasks, Carbon $now, string $lang, string $userName): array
    {
        $weekStart = $now->copy()->startOfWeek();
        $weekEnd = $now->copy()->endOfWeek();

        $thisWeek = [];
        $overdue = [];
        $completed = 0;
        $total = count($tasks);

        foreach ($tasks as $task) {
            if ($task['is_completed'] ?? false) {
                $completed++;
                continue;
            }
            if (!empty($task['deadline'])) {
                $dl = Carbon::parse($task['deadline']);
                if ($dl->between($weekStart, $weekEnd)) {
                    $thisWeek[] = $task['judul'] ?? 'Untitled';
                } elseif ($dl->isPast()) {
                    $overdue[] = $task['judul'] ?? 'Untitled';
                }
            }
        }

        $completionRate = $total > 0 ? round(($completed / $total) * 100) : 0;
        $daysLeft = $now->diffInDays($weekEnd);

        $findings = [];
        if ($lang === 'en') {
            $findings[] = "**Weekly Plan for {$userName}:**";
            $findings[] = "Completion rate: {$completionRate}% ({$completed}/{$total})";
            $findings[] = "Tasks due this week: " . (empty($thisWeek) ? 'None' : implode(', ', $thisWeek));
            $findings[] = "Overdue tasks: " . (empty($overdue) ? 'None' : count($overdue) . ' tasks');
            $findings[] = "Days remaining this week: {$daysLeft}";

            if (count($thisWeek) > $daysLeft && $daysLeft > 0) {
                $perDay = ceil(count($thisWeek) / $daysLeft);
                $findings[] = "Recommendation: Complete ~{$perDay} tasks/day to stay on track.";
            }
        } else {
            $findings[] = "**Rencana Minggu untuk {$userName}:**";
            $findings[] = "Tingkat penyelesaian: {$completionRate}% ({$completed}/{$total})";
            $findings[] = "Tugas deadline minggu ini: " . (empty($thisWeek) ? 'Tidak ada' : implode(', ', $thisWeek));
            $findings[] = "Tugas terlambat: " . (empty($overdue) ? 'Tidak ada' : count($overdue) . ' tugas');
            $findings[] = "Sisa hari minggu ini: {$daysLeft}";

            if (count($thisWeek) > $daysLeft && $daysLeft > 0) {
                $perDay = ceil(count($thisWeek) / $daysLeft);
                $findings[] = "Rekomendasi: Selesaikan ~{$perDay} tugas/hari agar tetap on track.";
            }
        }

        $suggestions = $lang === 'en'
            ? ['Set weekly goal', 'View time blocks', 'Reschedule overdue']
            : ['Set goal mingguan', 'Lihat blok waktu', 'Jadwalkan ulang overdue'];

        return ['findings' => $findings, 'suggestions' => $suggestions];
    }

    //  Workload Balance 

    private function workloadBalance(array $tasks, string $lang, string $userName): array
    {
        $pending = array_filter($tasks, fn($t) => !($t['is_completed'] ?? false));
        $count = count($pending);

        $highCount = count(array_filter($pending, fn($t) => in_array(strtolower($t['priority'] ?? ''), ['high', 'critical', 'tinggi', 'kritis'])));
        $overdueCount = count(array_filter($pending, function ($t) {
            return !empty($t['deadline']) && Carbon::parse($t['deadline'])->isPast();
        }));

        // Capacity assessment
        $capacityLevel = 'normal';
        $actions = [];
        if ($count > 15 || $highCount > 5) {
            $capacityLevel = 'overloaded';
        } elseif ($count > 8 || $highCount > 3) {
            $capacityLevel = 'heavy';
        } elseif ($count <= 3) {
            $capacityLevel = 'light';
        }

        $findings = [];
        if ($lang === 'en') {
            $findings[] = "**Workload Analysis for {$userName}:**";
            $findings[] = "Pending tasks: {$count} | High priority: {$highCount} | Overdue: {$overdueCount}";

            $findings[] = match ($capacityLevel) {
                'overloaded' => "Status: OVERLOADED. You have too many tasks. Consider delegating or postponing low-priority items.",
                'heavy'      => "Status: HEAVY. Manageable but needs focus. Prioritize ruthlessly.",
                'light'      => "Status: LIGHT. Good capacity for new projects or learning.",
                default      => "Status: BALANCED. Keep up the steady pace!",
            };
        } else {
            $findings[] = "**Analisis Beban Kerja untuk {$userName}:**";
            $findings[] = "Tugas pending: {$count} | Prioritas tinggi: {$highCount} | Terlambat: {$overdueCount}";

            $findings[] = match ($capacityLevel) {
                'overloaded' => "Status: OVERLOAD. Terlalu banyak tugas. Pertimbangkan delegasi atau tunda tugas prioritas rendah.",
                'heavy'      => "Status: BERAT. Bisa dikelola tapi butuh fokus. Prioritaskan dengan ketat.",
                'light'      => "Status: RINGAN. Kapasitas bagus untuk proyek baru atau belajar.",
                default      => "Status: SEIMBANG. Pertahankan ritme yang stabil!",
            };
        }

        if ($capacityLevel === 'overloaded') {
            $actions[] = ['type' => 'suggest_delegation', 'overdue' => $overdueCount, 'total' => $count];
        }

        $suggestions = $lang === 'en'
            ? ['View Eisenhower matrix', 'Set time blocks', 'Focus on top 3']
            : ['Lihat matriks Eisenhower', 'Atur blok waktu', 'Fokus 3 teratas'];

        return ['findings' => $findings, 'actions' => $actions, 'suggestions' => $suggestions];
    }

    //  Goal Strategy 

    private function goalStrategy(array $tasks, string $lang, string $userName): array
    {
        $pending = array_filter($tasks, fn($t) => !($t['is_completed'] ?? false));
        $completed = array_filter($tasks, fn($t) => ($t['is_completed'] ?? false));

        $findings = [];
        if ($lang === 'en') {
            $findings[] = "**Goal Strategy for {$userName}:**";
            $findings[] = "Completed: " . count($completed) . " | Remaining: " . count($pending);
            $findings[] = "Tip: Break large goals into 3-5 smaller actionable tasks.";
            $findings[] = "SMART Goals: Specific, Measurable, Achievable, Relevant, Time-bound.";
            if (count($pending) > 0) {
                $top = array_slice($pending, 0, 3);
                $topNames = array_map(fn($t) => $t['judul'] ?? 'Untitled', $top);
                $findings[] = "Your current top priorities: " . implode(', ', $topNames);
            }
        } else {
            $findings[] = "**Strategi Goal untuk {$userName}:**";
            $findings[] = "Selesai: " . count($completed) . " | Tersisa: " . count($pending);
            $findings[] = "Tips: Pecah goal besar menjadi 3-5 tugas kecil yang bisa dieksekusi.";
            $findings[] = "Gunakan SMART Goals: Spesifik, Terukur, Achievable, Relevan, Time-bound.";
            if (count($pending) > 0) {
                $top = array_slice($pending, 0, 3);
                $topNames = array_map(fn($t) => $t['judul'] ?? 'Untitled', $top);
                $findings[] = "Prioritas utama saat ini: " . implode(', ', $topNames);
            }
        }

        $suggestions = $lang === 'en'
            ? ['Create weekly goal', 'View progress', 'Set milestone']
            : ['Buat goal mingguan', 'Lihat progress', 'Set milestone'];

        return ['findings' => $findings, 'suggestions' => $suggestions];
    }

    //  Delegation Advice 

    private function delegationAdvice(array $tasks, string $lang, string $userName): array
    {
        $pending = array_filter($tasks, fn($t) => !($t['is_completed'] ?? false));
        $lowPriority = array_filter($pending, fn($t) => in_array(strtolower($t['priority'] ?? ''), ['low', 'rendah']));

        $findings = [];
        if ($lang === 'en') {
            $findings[] = "**Delegation Advisor for {$userName}:**";
            $findings[] = "Delegatable tasks (low priority): " . count($lowPriority);
            if (!empty($lowPriority)) {
                $names = array_map(fn($t) => $t['judul'] ?? 'Untitled', array_slice($lowPriority, 0, 5));
                $findings[] = "Candidates: " . implode(', ', $names);
            }
            $findings[] = "Rule: If someone else can do it 70% as well as you, delegate it.";
        } else {
            $findings[] = "**Penasihat Delegasi untuk {$userName}:**";
            $findings[] = "Tugas yang bisa didelegasikan (prioritas rendah): " . count($lowPriority);
            if (!empty($lowPriority)) {
                $names = array_map(fn($t) => $t['judul'] ?? 'Untitled', array_slice($lowPriority, 0, 5));
                $findings[] = "Kandidat: " . implode(', ', $names);
            }
            $findings[] = "Aturan: Jika orang lain bisa mengerjakannya 70% sebaik Anda, delegasikan.";
        }

        $suggestions = $lang === 'en'
            ? ['Assign to team', 'View workload', 'Focus on high priority']
            : ['Tugaskan ke tim', 'Lihat beban kerja', 'Fokus prioritas tinggi'];

        return ['findings' => $findings, 'suggestions' => $suggestions];
    }

    //  General Strategy 

    private function generalStrategy(array $tasks, string $lang, string $userName): array
    {
        $pending = count(array_filter($tasks, fn($t) => !($t['is_completed'] ?? false)));

        $findings = [];
        if ($lang === 'en') {
            $findings[] = "Strategic overview for {$userName}: {$pending} tasks pending.";
            $findings[] = "Try: 1) Eisenhower matrix to categorize 2) Time blocking to execute 3) Weekly review to reflect.";
        } else {
            $findings[] = "Ringkasan strategis untuk {$userName}: {$pending} tugas pending.";
            $findings[] = "Coba: 1) Matriks Eisenhower untuk kategorisasi 2) Time blocking untuk eksekusi 3) Weekly review untuk refleksi.";
        }

        $suggestions = $lang === 'en'
            ? ['Eisenhower matrix', 'Time blocks', 'Weekly plan']
            : ['Matriks Eisenhower', 'Blok waktu', 'Rencana mingguan'];

        return ['findings' => $findings, 'suggestions' => $suggestions];
    }
}
