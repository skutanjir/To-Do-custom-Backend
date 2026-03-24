<?php

namespace App\Services\Ai\Experts;

use App\Services\Ai\ExpertInterface;
use Carbon\Carbon;

class HealthVitalityExpert implements ExpertInterface
{
    private const CONFIDENCE_THRESHOLD = 25;

    private array $healthKeywords = [
        'break'       => ['istirahat', 'break', 'rehat', 'pause', 'rest', 'jeda'],
        'posture'     => ['postur', 'posture', 'duduk', 'sitting', 'punggung', 'back pain', 'ergonomi', 'ergonomic'],
        'hydration'   => ['minum', 'drink', 'air', 'water', 'haus', 'thirsty', 'hidrasi', 'hydration', 'dehidrasi'],
        'sleep'       => ['tidur', 'sleep', 'insomnia', 'ngantuk', 'sleepy', 'begadang', 'lembur', 'overtime', 'istirahat malam'],
        'screen'      => ['layar', 'screen', 'mata', 'eye', 'strain', 'screen time', 'biru', 'blue light'],
        'exercise'    => ['olahraga', 'exercise', 'gerak', 'move', 'stretching', 'peregangan', 'jalan', 'walk'],
        'stress'      => ['stres', 'stress', 'burnout', 'burn out', 'lelah', 'exhausted', 'capek', 'tired', 'kelelahan', 'fatigue'],
        'nutrition'   => ['makan', 'eat', 'gizi', 'nutrition', 'lapar', 'hungry', 'snack', 'vitamin', 'nutrisi'],
    ];

    public function evaluate(string $message, array $context): array
    {
        $msg = mb_strtolower(trim($message));
        $lang = $context['lang'] ?? 'id';
        $userName = $context['user'] ?? 'Kak';
        $now = Carbon::now();

        $confidence = $this->scoreHealthIntent($msg);

        if ($confidence < self::CONFIDENCE_THRESHOLD) {
            // Proactive check: if it's late or working hours are long, inject a reminder
            $proactive = $this->proactiveHealthCheck($now, $lang, $userName);
            if ($proactive) {
                return [
                    'findings'    => [$proactive],
                    'actions'     => [],
                    'suggestions' => [],
                    'confidence'  => 15, // Low confidence so it only shows as an insight, not main response
                ];
            }
            return ['findings' => [], 'actions' => [], 'suggestions' => [], 'confidence' => 0];
        }

        $topic = $this->detectHealthTopic($msg);
        $findings = [];
        $suggestions = [];

        switch ($topic) {
            case 'break':
                $result = $this->breakReminder($now, $lang, $userName);
                break;
            case 'posture':
                $result = $this->postureAdvice($lang, $userName);
                break;
            case 'hydration':
                $result = $this->hydrationTracker($now, $lang, $userName);
                break;
            case 'sleep':
                $result = $this->sleepAdvice($now, $lang, $userName);
                break;
            case 'screen':
                $result = $this->screenTimeAdvice($now, $lang, $userName);
                break;
            case 'exercise':
                $result = $this->exerciseReminder($lang, $userName);
                break;
            case 'stress':
                $result = $this->stressManagement($lang, $userName);
                break;
            case 'nutrition':
                $result = $this->nutritionAdvice($now, $lang, $userName);
                break;
            default:
                $result = $this->generalHealth($now, $lang, $userName);
                break;
        }

        return [
            'findings'    => $result['findings'],
            'actions'     => [],
            'suggestions' => array_slice($result['suggestions'], 0, 3),
            'confidence'  => max($confidence, 70),
        ];
    }

    //  Scoring 

    private function scoreHealthIntent(string $msg): int
    {
        $score = 0;
        foreach ($this->healthKeywords as $keywords) {
            foreach ($keywords as $kw) {
                if (preg_match('/\b' . preg_quote($kw, '/') . '\b/i', $msg)) {
                    $score += (mb_strlen($kw) >= 5) ? 18 : 10;
                }
            }
        }

        if (preg_match('/\b(kesehatan|health|sehat|healthy|wellness|well-being)\b/i', $msg)) $score += 20;
        if (preg_match('/\b(capek banget|sangat lelah|very tired|so tired|super exhausted)\b/i', $msg)) $score += 25;

        return min(100, $score);
    }

    private function detectHealthTopic(string $msg): string
    {
        $topScores = [];
        foreach ($this->healthKeywords as $topic => $keywords) {
            $topScores[$topic] = 0;
            foreach ($keywords as $kw) {
                if (preg_match('/\b' . preg_quote($kw, '/') . '\b/i', $msg)) {
                    $topScores[$topic] += (mb_strlen($kw) >= 5) ? 18 : 10;
                }
            }
        }
        arsort($topScores);
        $best = array_key_first($topScores);
        return ($topScores[$best] > 0) ? $best : 'general';
    }

    //  Proactive Health Check 

    private function proactiveHealthCheck(Carbon $now, string $lang, string $userName): ?string
    {
        $hour = (int) $now->format('H');

        if ($hour >= 23 || $hour < 5) {
            return $lang === 'en'
                ? "It's very late, {$userName}. Consider wrapping up and getting some rest. Sleep is critical for productivity."
                : "Sudah sangat malam, {$userName}. Pertimbangkan untuk istirahat. Tidur yang cukup penting untuk produktivitas.";
        }

        // Every 2 hours during work hours, suggest a break
        if ($hour >= 10 && $hour <= 20 && $hour % 2 === 0) {
            $reminders = $lang === 'en'
                ? ["Remember to hydrate, {$userName}! A glass of water boosts focus.", "Quick stretch? Your body will thank you."]
                : ["Jangan lupa minum air, {$userName}! Hidrasi meningkatkan fokus.", "Peregangan sebentar? Tubuh Anda akan berterima kasih."];
            return $reminders[array_rand($reminders)];
        }

        return null;
    }

    //  Break Reminder 

    private function breakReminder(Carbon $now, string $lang, string $userName): array
    {
        $hour = (int) $now->format('H');
        $findings = [];

        if ($lang === 'en') {
            $findings[] = "**Break Reminder for {$userName}:**";
            $findings[] = "The 52-17 rule: Work for 52 minutes, break for 17 minutes.";
            $findings[] = "Alternatively, try Pomodoro: 25 min work + 5 min break.";

            if ($hour >= 12 && $hour <= 13) {
                $findings[] = "It's lunchtime! Make sure to have a proper meal.";
            } elseif ($hour >= 15 && $hour <= 16) {
                $findings[] = "Afternoon slump zone. A short walk or coffee can help.";
            }

            $findings[] = "During breaks: stand up, stretch, look at something 20 feet away for 20 seconds (20-20-20 rule).";
        } else {
            $findings[] = "**Pengingat Istirahat untuk {$userName}:**";
            $findings[] = "Aturan 52-17: Kerja 52 menit, istirahat 17 menit.";
            $findings[] = "Alternatif: Pomodoro  kerja 25 menit + istirahat 5 menit.";

            if ($hour >= 12 && $hour <= 13) {
                $findings[] = "Waktunya makan siang! Pastikan makan yang bergizi.";
            } elseif ($hour >= 15 && $hour <= 16) {
                $findings[] = "Zona kantuk sore. Jalan singkat atau kopi bisa membantu.";
            }

            $findings[] = "Saat istirahat: berdiri, peregangan, lihat objek 6 meter selama 20 detik (aturan 20-20-20).";
        }

        $suggestions = $lang === 'en'
            ? ['Start Pomodoro', 'Set break timer', 'Stretching exercises']
            : ['Mulai Pomodoro', 'Atur timer istirahat', 'Latihan peregangan'];

        return ['findings' => $findings, 'suggestions' => $suggestions];
    }

    //  Posture Advice 

    private function postureAdvice(string $lang, string $userName): array
    {
        $findings = [];
        if ($lang === 'en') {
            $findings[] = "**Posture Guide for {$userName}:**";
            $findings[] = "1. Monitor at eye level, arm's length away";
            $findings[] = "2. Feet flat on the floor, knees at 90 degrees";
            $findings[] = "3. Shoulders relaxed, not hunched";
            $findings[] = "4. Lower back supported (use a cushion if needed)";
            $findings[] = "5. Wrists neutral when typing  not bent up or down";
            $findings[] = "Set a reminder to check posture every 30 minutes.";
        } else {
            $findings[] = "**Panduan Postur untuk {$userName}:**";
            $findings[] = "1. Monitor sejajar mata, satu lengan jaraknya";
            $findings[] = "2. Kaki rata di lantai, lutut 90 derajat";
            $findings[] = "3. Bahu rileks, jangan membungkuk";
            $findings[] = "4. Punggung bawah ditopang (gunakan bantal jika perlu)";
            $findings[] = "5. Pergelangan tangan netral saat mengetik";
            $findings[] = "Atur pengingat cek postur setiap 30 menit.";
        }

        $suggestions = $lang === 'en'
            ? ['Set posture reminder', 'Stretching guide', 'Ergonomic tips']
            : ['Atur pengingat postur', 'Panduan peregangan', 'Tips ergonomi'];

        return ['findings' => $findings, 'suggestions' => $suggestions];
    }

    //  Hydration Tracker 

    private function hydrationTracker(Carbon $now, string $lang, string $userName): array
    {
        $hour = (int) $now->format('H');
        $elapsedHours = max(1, $hour - 7); // Assuming wake at 7
        $recommendedGlasses = min(8, $elapsedHours); // 1 glass per hour

        $findings = [];
        if ($lang === 'en') {
            $findings[] = "**Hydration Tracker for {$userName}:**";
            $findings[] = "Goal: 8 glasses (2 liters) per day.";
            $findings[] = "By now ({$now->format('H:i')}), you should have had ~{$recommendedGlasses} glasses.";
            $findings[] = "Tips: Keep a water bottle at your desk. Drink before you feel thirsty.";
            $findings[] = "Dehydration reduces cognitive performance by up to 25%.";
        } else {
            $findings[] = "**Tracker Hidrasi untuk {$userName}:**";
            $findings[] = "Target: 8 gelas (2 liter) per hari.";
            $findings[] = "Sekarang ({$now->format('H:i')}), seharusnya sudah ~{$recommendedGlasses} gelas.";
            $findings[] = "Tips: Taruh botol air di meja. Minum sebelum merasa haus.";
            $findings[] = "Dehidrasi menurunkan performa kognitif hingga 25%.";
        }

        $suggestions = $lang === 'en'
            ? ['Set water reminder', 'Track intake', 'Health overview']
            : ['Atur pengingat minum', 'Lacak asupan', 'Overview kesehatan'];

        return ['findings' => $findings, 'suggestions' => $suggestions];
    }

    //  Sleep Advice 

    private function sleepAdvice(Carbon $now, string $lang, string $userName): array
    {
        $hour = (int) $now->format('H');

        $findings = [];
        if ($lang === 'en') {
            $findings[] = "**Sleep Advisor for {$userName}:**";
            $findings[] = "Recommended: 7-9 hours of sleep per night.";
            $findings[] = "Sleep cycles are ~90 minutes. Plan bedtime in multiples of 90 min before your alarm.";

            if ($hour >= 22) {
                $findings[] = "It's getting late. Consider starting your wind-down routine now.";
                $findings[] = "Avoid screens 30 min before bed. Try reading or light stretching.";
            } elseif ($hour >= 0 && $hour < 6) {
                $findings[] = "You're up very late/early, {$userName}. Every hour of sleep before midnight counts as double.";
            }

            $findings[] = "Tips: Consistent sleep schedule > total hours. Go to bed at the same time daily.";
        } else {
            $findings[] = "**Penasihat Tidur untuk {$userName}:**";
            $findings[] = "Rekomendasi: 7-9 jam tidur per malam.";
            $findings[] = "Siklus tidur ~90 menit. Rencanakan waktu tidur dalam kelipatan 90 menit sebelum alarm.";

            if ($hour >= 22) {
                $findings[] = "Sudah malam. Pertimbangkan untuk mulai rutinitas sebelum tidur.";
                $findings[] = "Hindari layar 30 menit sebelum tidur. Coba baca buku atau peregangan ringan.";
            } elseif ($hour >= 0 && $hour < 6) {
                $findings[] = "Anda masih terjaga sangat larut, {$userName}. Tidur sebelum tengah malam kualitasnya lebih baik.";
            }

            $findings[] = "Tips: Jadwal tidur konsisten > total jam. Tidur di waktu yang sama setiap hari.";
        }

        $suggestions = $lang === 'en'
            ? ['Set bedtime reminder', 'Calculate sleep cycles', 'Track sleep quality']
            : ['Atur pengingat tidur', 'Hitung siklus tidur', 'Lacak kualitas tidur'];

        return ['findings' => $findings, 'suggestions' => $suggestions];
    }

    //  Screen Time Advice 

    private function screenTimeAdvice(Carbon $now, string $lang, string $userName): array
    {
        $findings = [];
        if ($lang === 'en') {
            $findings[] = "**Screen Time Guide for {$userName}:**";
            $findings[] = "20-20-20 Rule: Every 20 minutes, look at something 20 feet away for 20 seconds.";
            $findings[] = "Blue light exposure can disrupt sleep. Use night mode after 8 PM.";
            $findings[] = "Consider screen breaks: 5 min every 30 min, or 15 min every hour.";
            $findings[] = "Adjust brightness to match ambient lighting. Too bright = eye strain.";
        } else {
            $findings[] = "**Panduan Screen Time untuk {$userName}:**";
            $findings[] = "Aturan 20-20-20: Setiap 20 menit, lihat objek 6 meter selama 20 detik.";
            $findings[] = "Cahaya biru bisa ganggu tidur. Aktifkan mode malam setelah jam 8.";
            $findings[] = "Istirahat layar: 5 menit setiap 30 menit, atau 15 menit setiap jam.";
            $findings[] = "Sesuaikan kecerahan dengan cahaya ruangan. Terlalu terang = mata lelah.";
        }

        $suggestions = $lang === 'en'
            ? ['Set eye rest timer', 'Enable night mode', 'Take a break now']
            : ['Atur timer istirahat mata', 'Aktifkan mode malam', 'Istirahat sekarang'];

        return ['findings' => $findings, 'suggestions' => $suggestions];
    }

    //  Exercise Reminder 

    private function exerciseReminder(string $lang, string $userName): array
    {
        $exercises = $lang === 'en'
            ? [
                "Neck rolls: 5 circles each direction",
                "Shoulder shrugs: Hold 5 sec, release. Repeat 10x",
                "Wrist circles: 10 each direction",
                "Stand and touch toes: Hold 15 sec",
                "Walk for 5 minutes around your space",
            ]
            : [
                "Putar leher: 5 lingkaran tiap arah",
                "Angkat bahu: Tahan 5 detik, lepas. Ulangi 10x",
                "Putar pergelangan: 10 tiap arah",
                "Berdiri dan sentuh jari kaki: Tahan 15 detik",
                "Jalan kaki 5 menit di sekitar ruangan",
            ];

        $findings = [];
        if ($lang === 'en') {
            $findings[] = "**Quick Desk Exercises for {$userName}:**";
            foreach ($exercises as $e) $findings[] = "- {$e}";
            $findings[] = "Doing these every 2 hours reduces fatigue by up to 40%.";
        } else {
            $findings[] = "**Latihan Cepat di Meja untuk {$userName}:**";
            foreach ($exercises as $e) $findings[] = "- {$e}";
            $findings[] = "Melakukan ini setiap 2 jam mengurangi kelelahan hingga 40%.";
        }

        $suggestions = $lang === 'en'
            ? ['Set exercise reminder', 'Full stretching routine', 'Take a walk']
            : ['Atur pengingat olahraga', 'Rutinitas peregangan lengkap', 'Jalan kaki'];

        return ['findings' => $findings, 'suggestions' => $suggestions];
    }

    //  Stress Management 

    private function stressManagement(string $lang, string $userName): array
    {
        $findings = [];
        if ($lang === 'en') {
            $findings[] = "**Stress Management for {$userName}:**";
            $findings[] = "1. Box Breathing: Inhale 4s → Hold 4s → Exhale 4s → Hold 4s. Repeat 4 cycles.";
            $findings[] = "2. Brain dump: Write down everything on your mind  don't organize, just dump.";
            $findings[] = "3. The 2-minute rule: If it takes less than 2 minutes, do it now.";
            $findings[] = "4. Progressive muscle relaxation: Tense each muscle group 5s, then release.";
            $findings[] = "Remember: Stress is a signal, not an identity. You're doing your best.";
        } else {
            $findings[] = "**Manajemen Stres untuk {$userName}:**";
            $findings[] = "1. Box Breathing: Tarik napas 4d → Tahan 4d → Buang 4d → Tahan 4d. Ulangi 4 siklus.";
            $findings[] = "2. Brain dump: Tulis semua yang ada di pikiran  jangan diatur, tulis saja.";
            $findings[] = "3. Aturan 2 menit: Jika butuh kurang dari 2 menit, kerjakan sekarang.";
            $findings[] = "4. Relaksasi otot progresif: Tegangkan tiap otot 5 detik, lalu lepas.";
            $findings[] = "Ingat: Stres adalah sinyal, bukan identitas. Anda sudah melakukan yang terbaik.";
        }

        $suggestions = $lang === 'en'
            ? ['Start breathing exercise', 'Brain dump session', 'View workload']
            : ['Mulai latihan napas', 'Sesi brain dump', 'Lihat beban kerja'];

        return ['findings' => $findings, 'suggestions' => $suggestions];
    }

    //  Nutrition Advice 

    private function nutritionAdvice(Carbon $now, string $lang, string $userName): array
    {
        $hour = (int) $now->format('H');
        $findings = [];

        if ($lang === 'en') {
            $findings[] = "**Nutrition Advisor for {$userName}:**";

            if ($hour >= 6 && $hour < 10) {
                $findings[] = "Start with a good breakfast: protein + complex carbs for sustained energy.";
            } elseif ($hour >= 12 && $hour < 14) {
                $findings[] = "Lunchtime! Choose a balanced meal. Avoid heavy carbs to prevent afternoon crash.";
            } elseif ($hour >= 15 && $hour < 17) {
                $findings[] = "Afternoon snack time: nuts, fruits, or yogurt for a brain boost.";
            } else {
                $findings[] = "For dinner: lighter meals are better for sleep quality.";
            }

            $findings[] = "Brain foods: nuts, berries, dark chocolate, fatty fish, green tea.";
        } else {
            $findings[] = "**Penasihat Nutrisi untuk {$userName}:**";

            if ($hour >= 6 && $hour < 10) {
                $findings[] = "Mulai dengan sarapan baik: protein + karbohidrat kompleks untuk energi tahan lama.";
            } elseif ($hour >= 12 && $hour < 14) {
                $findings[] = "Waktunya makan siang! Pilih makanan seimbang. Hindari karbohidrat berat agar tidak ngantuk.";
            } elseif ($hour >= 15 && $hour < 17) {
                $findings[] = "Waktu camilan sore: kacang, buah, atau yogurt untuk boost otak.";
            } else {
                $findings[] = "Untuk makan malam: makanan lebih ringan lebih baik untuk kualitas tidur.";
            }

            $findings[] = "Makanan otak: kacang, berry, cokelat hitam, ikan berlemak, teh hijau.";
        }

        $suggestions = $lang === 'en'
            ? ['Hydration check', 'Set meal reminder', 'Health overview']
            : ['Cek hidrasi', 'Atur pengingat makan', 'Overview kesehatan'];

        return ['findings' => $findings, 'suggestions' => $suggestions];
    }

    //  General Health 

    private function generalHealth(Carbon $now, string $lang, string $userName): array
    {
        $hour = (int) $now->format('H');
        $findings = [];

        if ($lang === 'en') {
            $findings[] = "**Health Overview for {$userName}:**";
            $findings[] = "Key habits for peak performance:";
            $findings[] = "- Sleep: 7-9 hours consistently";
            $findings[] = "- Hydration: 8 glasses daily";
            $findings[] = "- Movement: Every 60-90 minutes";
            $findings[] = "- Screen breaks: 20-20-20 rule";
            $findings[] = "- Nutrition: Regular, balanced meals";
        } else {
            $findings[] = "**Overview Kesehatan untuk {$userName}:**";
            $findings[] = "Kebiasaan kunci untuk performa puncak:";
            $findings[] = "- Tidur: 7-9 jam konsisten";
            $findings[] = "- Hidrasi: 8 gelas per hari";
            $findings[] = "- Gerak: Setiap 60-90 menit";
            $findings[] = "- Istirahat layar: Aturan 20-20-20";
            $findings[] = "- Nutrisi: Makan teratur dan seimbang";
        }

        $suggestions = $lang === 'en'
            ? ['Break reminder', 'Posture check', 'Hydration tracker']
            : ['Pengingat istirahat', 'Cek postur', 'Tracker hidrasi'];

        return ['findings' => $findings, 'suggestions' => $suggestions];
    }
}
