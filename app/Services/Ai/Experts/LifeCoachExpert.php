<?php

namespace App\Services\Ai\Experts;

use App\Services\Ai\ExpertInterface;
use Illuminate\Support\Str;

class LifeCoachExpert implements ExpertInterface
{
    private const CONFIDENCE_THRESHOLD = 25;

    private array $lifeKeywords = [
        'karir', 'career', 'kerja', 'job', 'interview', 'cv', 'resume', 'gaji', 'salary',
        'keuangan', 'finance', 'uang', 'money', 'tabungan', 'saving', 'investasi', 'investment',
        'hutang', 'debt', 'budget', 'anggaran',
        'kesehatan', 'health', 'olahraga', 'exercise', 'diet', 'nutrisi', 'nutrition',
        'tidur', 'sleep', 'meditasi', 'meditation', 'stress',
        'hubungan', 'relationship', 'pacar', 'teman', 'friend', 'keluarga', 'family',
        'cinta', 'love', 'komunikasi', 'communication',
        'pengembangan diri', 'self improvement', 'kebiasaan', 'habit', 'disiplin', 'discipline',
        'percaya diri', 'confidence', 'prokrastinasi', 'procrastination',
        'belajar', 'study', 'ujian', 'exam', 'kuliah', 'sekolah', 'school',
        'cemas', 'anxiety', 'depresi', 'depression', 'sedih', 'mental', 'mindfulness',
        'self care', 'pindah', 'perubahan', 'change', 'menikah', 'marriage',
        'nasihat', 'advice', 'saran hidup', 'life tips', 'life coach',
    ];

    public function evaluate(string $message, array $context): array
    {
        $msg = mb_strtolower(trim($message));
        $lang = $context['lang'] ?? 'id';
        $userName = $context['user'] ?? 'Kak';
        $confidence = $this->scoreLifeCoachIntent($msg);

        if ($confidence < self::CONFIDENCE_THRESHOLD) {
            return ['findings' => [], 'actions' => [], 'suggestions' => [], 'confidence' => 0];
        }

        $category = $this->detectCategory($msg);
        $findings = [];
        $suggestions = [];

        switch ($category) {
            case 'career':
                $findings[] = $this->getCareerAdvice($msg, $lang, $userName);
                $suggestions = $lang === 'en'
                    ? ['Interview tips', 'Resume help', 'Career goals']
                    : ['Tips interview', 'Bankak CV', 'Target karir'];
                break;

            case 'finance':
                $findings[] = $this->getFinanceAdvice($msg, $lang, $userName);
                $suggestions = $lang === 'en'
                    ? ['Budgeting tips', 'Saving strategies', 'Investment basics']
                    : ['Tips anggaran', 'Strategi menabung', 'Dasar investasi'];
                break;

            case 'health':
                $findings[] = $this->getHealthAdvice($msg, $lang, $userName);
                $suggestions = $lang === 'en'
                    ? ['Exercise tips', 'Nutrition', 'Sleep hygiene']
                    : ['Tips olahraga', 'Nutrisi', 'Kualitas tidur'];
                break;

            case 'relationship':
                $findings[] = $this->getRelationshipAdvice($msg, $lang, $userName);
                $suggestions = $lang === 'en'
                    ? ['Communication tips', 'Conflict resolution', 'Self-love']
                    : ['Tips komunikasi', 'Resolusi konflik', 'Cinta diri'];
                break;

            case 'personal_dev':
                $findings[] = $this->getPersonalDevAdvice($msg, $lang, $userName);
                $suggestions = $lang === 'en'
                    ? ['Build habits', 'Beat procrastination', 'Set goals']
                    : ['Bangun kebiasaan', 'Atasi prokrastinasi', 'Buat target'];
                break;

            case 'education':
                $findings[] = $this->getEducationAdvice($msg, $lang, $userName);
                $suggestions = $lang === 'en'
                    ? ['Study techniques', 'Exam prep', 'Memory tips']
                    : ['Teknik belajar', 'Persiapan ujian', 'Tips memori'];
                break;

            case 'mental_wellness':
                $findings[] = $this->getMentalWellnessAdvice($msg, $lang, $userName);
                $suggestions = $lang === 'en'
                    ? ['Mindfulness', 'Self-care routine', 'Stress relief']
                    : ['Mindfulness', 'Rutinitas self-care', 'Redakan stress'];
                break;

            case 'life_transition':
                $findings[] = $this->getLifeTransitionAdvice($msg, $lang, $userName);
                $suggestions = $lang === 'en'
                    ? ['Embrace change', 'New beginnings', 'Life planning']
                    : ['Menerima perubahan', 'Awal baru', 'Perencanaan hidup'];
                break;

            default:
                $findings[] = $this->getGeneralLifeAdvice($lang, $userName);
                $suggestions = $lang === 'en'
                    ? ['Career advice', 'Finance tips', 'Health tips']
                    : ['Saran karir', 'Tips keuangan', 'Tips kesehatan'];
                break;
        }

        return [
            'findings' => $findings,
            'actions' => [],
            'suggestions' => array_slice($suggestions, 0, 3),
            'confidence' => $confidence,
        ];
    }

    private function scoreLifeCoachIntent(string $msg): int
    {
        $score = 0;
        foreach ($this->lifeKeywords as $kw) {
            if (str_contains($msg, $kw)) {
                $score += (mb_strlen($kw) >= 5) ? 12 : 6;
            }
        }

        if (preg_match('/\b(tips|saran|advice|nasihat|cara|how to|gimana|bagaimana)\b/', $msg)) $score += 10;
        if (preg_match('/\b(tips|saran|advice|cara)\b.*\b(karir|career|kerja|job|keuangan|finance|kesehatan|health|hubungan|relationship|belajar|study)\b/', $msg)) $score += 25;

        return min(100, $score);
    }

    private function detectCategory(string $msg): string
    {
        $cats = [
            'career'          => ['karir', 'career', 'kerja', 'job', 'interview', 'cv', 'resume', 'gaji', 'salary', 'promosi', 'promotion', 'networking'],
            'finance'         => ['keuangan', 'finance', 'uang', 'money', 'tabungan', 'saving', 'investasi', 'investment', 'hutang', 'debt', 'budget', 'anggaran'],
            'health'          => ['kesehatan', 'health', 'olahraga', 'exercise', 'diet', 'nutrisi', 'nutrition', 'tidur', 'sleep', 'meditasi', 'meditation'],
            'relationship'    => ['hubungan', 'relationship', 'pacar', 'teman', 'friend', 'keluarga', 'family', 'cinta', 'love', 'komunikasi'],
            'personal_dev'    => ['pengembangan diri', 'self improvement', 'kebiasaan', 'habit', 'disiplin', 'discipline', 'percaya diri', 'confidence', 'prokrastinasi', 'procrastination', 'target', 'goal'],
            'education'       => ['belajar', 'study', 'ujian', 'exam', 'kuliah', 'sekolah', 'school', 'pelajaran', 'lesson'],
            'mental_wellness' => ['cemas', 'anxiety', 'depresi', 'depression', 'mental', 'mindfulness', 'self care', 'stress', 'burnout'],
            'life_transition' => ['pindah', 'perubahan', 'change', 'baru', 'new chapter', 'menikah', 'marriage', 'pensiun', 'retirement'],
        ];

        foreach ($cats as $cat => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($msg, $kw)) return $cat;
            }
        }
        return 'general';
    }

    private function getCareerAdvice(string $msg, string $lang, string $userName): string
    {
        if (preg_match('/\b(interview|wawancara)\b/', $msg)) {
            return $lang === 'en'
                ? " **Interview Tips for {$userName}**:\n\n1. **Research** the company thoroughly  mission, products, recent news\n2. **STAR Method** for behavioral questions: Situation, Task, Action, Result\n3. **Prepare** 3-5 questions for the interviewer\n4. **Practice** common questions: 'Tell me about yourself', 'Why this company?', 'Strengths/weaknesses'\n5. **Dress** appropriately and arrive 10-15 min early\n6. **Follow up** with a thank-you email within 24 hours\n7. **Body language**: firm handshake, eye contact, genuine smile\n\nRemember: the interview is a two-way street  you're evaluating them too! "
                : " **Tips Interview untuk {$userName}**:\n\n1. **Riset** perusahaan secara menyeluruh  visi, produk, berita terbaru\n2. **Metode STAR** untuk pertanyaan perilaku: Situasi, Tugas, Aksi, Hasil\n3. **Siapkan** 3-5 pertanyaan untuk pewawancara\n4. **Latih** pertanyaan umum: 'Ceritakan tentang diri Anda', 'Kenapa perusahaan ini?', 'Kelebihan/kekurangan'\n5. **Berpakaian** rapi dan datang 10-15 menit lebih awal\n6. **Follow up** dengan email terima kasih dalam 24 jam\n7. **Bahasa tubuh**: jabat tangan mantap, kontak mata, senyum tulus\n\nIngat: interview itu dua arah  kamu juga menilai mereka! ";
        }

        if (preg_match('/\b(cv|resume)\b/', $msg)) {
            return $lang === 'en'
                ? " **Resume/CV Tips for {$userName}**:\n\n1. **Keep it 1-2 pages**  recruiters spend ~6 seconds scanning\n2. **Quantify achievements**: 'Increased sales by 30%' > 'Helped increase sales'\n3. **Tailor** to each job posting  match keywords\n4. **Structure**: Contact → Summary → Experience → Education → Skills\n5. **Use action verbs**: Led, Developed, Implemented, Achieved, Optimized\n6. **No** typos, no photos (unless required), no personal info (religion/age)\n7. **ATS-friendly**: simple format, standard fonts, no tables/graphics\n\nPro tip: Keep a 'master resume' with everything, then customize per application! "
                : " **Tips CV/Resume untuk {$userName}**:\n\n1. **Maksimal 1-2 halaman**  recruiter menghabiskan ~6 detik scanning\n2. **Kuantifikasi pencapaian**: 'Meningkatkan penjualan 30%' > 'Membantu meningkatkan penjualan'\n3. **Sesuaikan** dengan setiap lowongan  cocokkan kata kunci\n4. **Struktur**: Kontak → Ringkasan → Pengalaman → Pendidikan → Keahlian\n5. **Gunakan kata kerja aktif**: Memimpin, Mengembangkan, Mengimplementasikan, Mencapai, Mengoptimalkan\n6. **Jangan**: typo, foto (kecuali diminta), info pribadi (agama/usia)\n7. **ATS-friendly**: format sederhana, font standar, tanpa tabel/grafik\n\nPro tip: Buat 'master CV' lengkap, lalu sesuaikan per lamaran! ";
        }

        $advices = [
            ['id' => " **Saran Karir untuk {$userName}**:\n\n **5 Langkah Pengembangan Karir**:\n1. Identifikasi kekuatan dan passion kamu\n2. Tentukan target jangka pendek (6 bulan) dan panjang (5 tahun)\n3. Bangun network  hadiri event, aktif di LinkedIn\n4. Tingkatkan skill: kursus online, sertifikasi, mentoring\n5. Evaluasi progress setiap kuartal\n\n Ingat: karir bukan sprint, ini maraton. Setiap langkah kecil berarti!", 'en' => " **Career Advice for {$userName}**:\n\n **5 Steps for Career Growth**:\n1. Identify your strengths and passions\n2. Set short-term (6 months) and long-term (5 years) goals\n3. Build your network  attend events, be active on LinkedIn\n4. Upskill: online courses, certifications, mentoring\n5. Evaluate progress every quarter\n\n Remember: career is a marathon, not a sprint. Every small step counts!"],
            ['id' => " **Tips Negosiasi Gaji untuk {$userName}**:\n\n1.  Riset range gaji di industri kamu (Glassdoor, LinkedIn, tanya teman)\n2.  Siapkan data pencapaianmu (angka, hasil nyata)\n3.  Negosiasi setelah dapat offer, bukan sebelum\n4.  Sebutkan range, bukan angka pasti: 'Saya mengharapkan di range X-Y'\n5.  Jangan hanya negosiasi gaji  pertimbangkan benefit lain (remote, cuti, bonus)\n6.  Tetap profesional dan positif\n\n Kalimat ajaib: 'Berdasarkan riset dan pengalaman saya, saya yakin kompensasi yang adil adalah...'", 'en' => " **Salary Negotiation Tips for {$userName}**:\n\n1.  Research salary ranges in your industry (Glassdoor, LinkedIn, peers)\n2.  Prepare data on your achievements (numbers, tangible results)\n3.  Negotiate after receiving the offer, not before\n4.  State a range, not a fixed number: 'I'm looking at X-Y range'\n5.  Don't just negotiate salary  consider other benefits (remote, PTO, bonus)\n6.  Stay professional and positive\n\n Magic phrase: 'Based on my research and experience, I believe fair compensation would be...'"],
        ];
        $pick = $advices[array_rand($advices)];
        return $lang === 'en' ? $pick['en'] : $pick['id'];
    }

    private function getFinanceAdvice(string $msg, string $lang, string $userName): string
    {
        if (preg_match('/\b(investasi|investment|invest)\b/', $msg)) {
            return $lang === 'en'
                ? " **Investment Basics for {$userName}**:\n\n1. **Emergency Fund First**: Save 3-6 months of expenses before investing\n2. **Start Small**: Even \$50/month makes a difference over time\n3. **Diversify**: Don't put all eggs in one basket (stocks, bonds, funds, real estate)\n4. **Compound Interest**: Einstein called it the 8th wonder  start early!\n5. **Risk Tolerance**: Higher return = higher risk. Know your comfort level\n6. **Common Options**: Index funds (low-cost, diversified), mutual funds, stocks, bonds, gold\n7. **Long-term**: Time in market > timing the market\n\n This is educational info, not financial advice. Consult a financial advisor for personal decisions."
                : " **Dasar Investasi untuk {$userName}**:\n\n1. **Dana Darurat Dulu**: Tabung 3-6 bulan pengeluaran sebelum investasi\n2. **Mulai Kecil**: Bahkan Rp100rb/bulan berpengaruh dalam jangka panjang\n3. **Diversifikasi**: Jangan taruh semua telur di satu keranjang (saham, obligasi, reksa dana, properti)\n4. **Bunga Majemuk**: Einstein menyebutnya keajaiban ke-8  mulai sedini mungkin!\n5. **Toleransi Risiko**: Return tinggi = risiko tinggi. Kenali batas nyamanmu\n6. **Opsi Umum**: Reksa dana indeks, deposito, saham, obligasi, emas\n7. **Jangka Panjang**: Waktu di pasar > timing pasar\n\n Ini informasi edukatif, bukan saran finansial. Konsultasikan dengan advisor untuk keputusan pribadi.";
        }

        if (preg_match('/\b(hutang|debt|cicilan)\b/', $msg)) {
            return $lang === 'en'
                ? " **Debt Management for {$userName}**:\n\n**Snowball Method** (motivation-focused): Pay off smallest debt first → build momentum\n**Avalanche Method** (math-optimal): Pay off highest interest first → save money\n\n Steps:\n1. List all debts: amount, interest rate, minimum payment\n2. Always pay minimums on all debts\n3. Put extra money on target debt (snowball OR avalanche)\n4. Once paid, roll that payment to the next debt\n5. Cut unnecessary expenses temporarily\n6. Consider consolidation if rates are high\n\n Avoid: new debt while paying off old debt, payday loans, maxing credit cards"
                : " **Manajemen Hutang untuk {$userName}**:\n\n**Metode Snowball** (fokus motivasi): Lunasi hutang terkecil dulu → bangun momentum\n**Metode Avalanche** (optimal secara matematik): Lunasi bunga tertinggi dulu → hemat uang\n\n Langkah:\n1. List semua hutang: jumlah, bunga, pembayaran minimum\n2. Selalu bayar minimum semua hutang\n3. Uang ekstra untuk target hutang (snowball ATAU avalanche)\n4. Setelah lunas, gulirkan pembayaran ke hutang berikutnya\n5. Potong pengeluaran tidak perlu sementara waktu\n6. Pertimbangkan konsolidasi jika bunga tinggi\n\n Hindari: hutang baru saat melunasi yang lama, pinjol, kartu kredit maks";
        }

        $advices = [
            ['id' => " **Tips Keuangan untuk {$userName}**:\n\n **Aturan 50/30/20**:\n 50% Kebutuhan (sewa, makan, transport, tagihan)\n 30% Keinginan (hiburan, hobi, makan di luar)\n 20% Tabungan & investasi\n\n **5 Langkah Sehat Finansial**:\n1. Track pengeluaran selama 1 bulan\n2. Buat anggaran bulanan\n3. Bangun dana darurat (3-6 bulan pengeluaran)\n4. Lunasi hutang berbunga tinggi\n5. Mulai investasi rutin (min. 10% pendapatan)", 'en' => " **Finance Tips for {$userName}**:\n\n **The 50/30/20 Rule**:\n 50% Needs (rent, food, transport, bills)\n 30% Wants (entertainment, hobbies, dining out)\n 20% Savings & investments\n\n **5 Steps to Financial Health**:\n1. Track expenses for 1 month\n2. Create a monthly budget\n3. Build emergency fund (3-6 months of expenses)\n4. Pay off high-interest debt\n5. Start regular investing (min. 10% of income)"],
        ];
        $pick = $advices[array_rand($advices)];
        return $lang === 'en' ? $pick['en'] : $pick['id'];
    }

    private function getHealthAdvice(string $msg, string $lang, string $userName): string
    {
        if (preg_match('/\b(tidur|sleep)\b/', $msg)) {
            return $lang === 'en'
                ? " **Sleep Hygiene Tips for {$userName}**:\n\n1. **Consistent Schedule**: Same bedtime & wake time (even weekends)\n2. **Screen Off**: No screens 1 hour before bed (blue light blocks melatonin)\n3. **Cool & Dark Room**: 18-22°C, blackout curtains\n4. **No Caffeine**: After 2 PM (6-hour half-life)\n5. **Wind Down Routine**: Read, meditate, gentle stretch\n6. **7-9 Hours**: Aim for this range\n7. **No Heavy Meals**: 2-3 hours before sleep\n\n Remember: quality sleep = better focus, mood, and immune system!\n\n Persistent sleep issues? Consult a healthcare professional."
                : " **Tips Kualitas Tidur untuk {$userName}**:\n\n1. **Jadwal Konsisten**: Jam tidur & bangun sama (termasuk weekend)\n2. **Layar Off**: Tanpa layar 1 jam sebelum tidur (cahaya biru blok melatonin)\n3. **Kamar Sejuk & Gelap**: 18-22°C, tirai gelap\n4. **Tanpa Kafein**: Setelah jam 2 siang (waktu paruh 6 jam)\n5. **Rutinitas Wind Down**: Baca, meditasi, stretching ringan\n6. **7-9 Jam**: Target durasi tidur\n7. **Tanpa Makan Berat**: 2-3 jam sebelum tidur\n\n Ingat: tidur berkualitas = fokus, mood, dan imun lebih baik!\n\n Masalah tidur berkepanjangan? Konsultasikan ke profesional kesehatan.";
        }

        if (preg_match('/\b(olahraga|exercise|fitness|gym|workout)\b/', $msg)) {
            return $lang === 'en'
                ? " **Exercise Tips for {$userName}**:\n\n**For Beginners**:\n1. Start with 20 min, 3x/week\n2. Mix cardio + strength training\n3. Walking counts! 10,000 steps/day is great\n4. Stretch before & after\n5. Rest days are important\n\n**Quick Home Workout (No Equipment)**:\n 10 push-ups\n 15 squats\n 20 lunges (10 each leg)\n 30-second plank\n 15 sit-ups\n Repeat 3 rounds\n\n Consistency > intensity. Even 15 min/day beats nothing!\n\n New to exercise? Start slowly and consult a doctor if you have health conditions."
                : " **Tips Olahraga untuk {$userName}**:\n\n**Untuk Pemula**:\n1. Mulai 20 menit, 3x/minggu\n2. Campurkan kardio + latihan kekuatan\n3. Jalan kaki juga dihitung! 10.000 langkah/hari sangat bagus\n4. Peregangan sebelum & sesudah\n5. Hari istirahat itu penting\n\n**Workout Cepat di Rumah (Tanpa Alat)**:\n 10 push-up\n 15 squat\n 20 lunge (10 tiap kaki)\n Plank 30 detik\n 15 sit-up\n Ulangi 3 ronde\n\n Konsistensi > intensitas. Bahkan 15 menit/hari lebih baik daripada tidak sama sekali!\n\n Baru mulai? Mulai pelan-pelan dan konsultasi dokter jika ada kondisi kesehatan.";
        }

        return $lang === 'en'
            ? " **Health Tips for {$userName}**:\n\n **Hydration**: 8 glasses/day minimum\n **Nutrition**: Eat the rainbow  varied fruits & veggies\n **Movement**: 30 min moderate activity daily\n **Sleep**: 7-9 hours per night\n **Stress**: Practice deep breathing or meditation\n **Sunlight**: 15 min morning sun for vitamin D\n **Social**: Maintain meaningful connections\n\n This is general wellness info. Always consult healthcare professionals for medical advice."
            : " **Tips Kesehatan untuk {$userName}**:\n\n **Hidrasi**: Minimum 8 gelas/hari\n **Nutrisi**: Makan pelangi  variasi buah & sayur\n **Gerakan**: 30 menit aktivitas sedang setiap hari\n **Tidur**: 7-9 jam per malam\n **Stress**: Latih pernapasan dalam atau meditasi\n **Sinar Matahari**: 15 menit pagi untuk vitamin D\n **Sosial**: Jaga koneksi yang bermakna\n\n Ini info kesehatan umum. Selalu konsultasikan ke profesional medis untuk saran kesehatan.";
    }

    private function getRelationshipAdvice(string $msg, string $lang, string $userName): string
    {
        $advices = [
            ['id' => " **Tips Hubungan untuk {$userName}**:\n\n **Komunikasi Sehat**:\n1. Dengarkan untuk memahami, bukan untuk membalas\n2. Gunakan 'Aku merasa...' bukan 'Kamu selalu...'\n3. Jangan bawa masalah lama saat bertengkar\n4. Minta maaf saat salah  tanpa 'tapi'\n5. Quality time > quantity time\n\n **5 Bahasa Cinta** (Gary Chapman):\n Words of Affirmation (kata-kata afirmasi)\n Quality Time (waktu berkualitas)\n Physical Touch (sentuhan fisik)\n Acts of Service (tindakan melayani)\n Receiving Gifts (menerima hadiah)\n\nTiap orang punya bahasa cinta yang berbeda. Kenali milikmu dan pasanganmu!", 'en' => " **Relationship Tips for {$userName}**:\n\n **Healthy Communication**:\n1. Listen to understand, not to respond\n2. Use 'I feel...' not 'You always...'\n3. Don't bring up old issues in arguments\n4. Apologize when wrong  without 'but'\n5. Quality time > quantity time\n\n **5 Love Languages** (Gary Chapman):\n Words of Affirmation\n Quality Time\n Physical Touch\n Acts of Service\n Receiving Gifts\n\nEveryone has a different love language. Know yours and your partner's!"],
            ['id' => " **Tips Pertemanan untuk {$userName}**:\n\n1. **Jadilah pendengar yang baik**  teman butuh didengar, bukan dinasihati\n2. **Hadir saat dibutuhkan**  bukan hanya saat senang\n3. **Hormati batas**  setiap orang punya ruang pribadi\n4. **Jujur tapi baik**  kebenaran yang disampaikan dengan kasih\n5. **Mutual growth**  dukung impian masing-masing\n\n Ingat: kualitas > kuantitas. Lebih baik 3 teman sejati daripada 100 kenalan.", 'en' => " **Friendship Tips for {$userName}**:\n\n1. **Be a good listener**  friends need to be heard, not lectured\n2. **Show up when needed**  not just during good times\n3. **Respect boundaries**  everyone has personal space\n4. **Honest but kind**  truth delivered with love\n5. **Mutual growth**  support each other's dreams\n\n Remember: quality > quantity. Better 3 true friends than 100 acquaintances."],
        ];
        $pick = $advices[array_rand($advices)];
        return $lang === 'en' ? $pick['en'] : $pick['id'];
    }

    private function getPersonalDevAdvice(string $msg, string $lang, string $userName): string
    {
        if (preg_match('/\b(prokrastinasi|procrastination|malas|lazy|menunda)\b/', $msg)) {
            return $lang === 'en'
                ? " **Beat Procrastination, {$userName}!**\n\n1. **2-Minute Rule**: If it takes <2 min, do it now\n2. **Pomodoro**: Work 25 min, break 5 min. Repeat.\n3. **Eat the Frog**: Do the hardest task first thing in the morning\n4. **Break it Down**: Big task → tiny steps (first step should be laughably small)\n5. **Remove Distractions**: Phone in another room, close social media tabs\n6. **Accountability**: Tell someone your goal and deadline\n7. **Reward System**: Finish task → small reward\n\n Procrastination isn't laziness  it's often anxiety or perfectionism in disguise. Be kind to yourself while pushing forward! "
                : " **Atasi Prokrastinasi, {$userName}!**\n\n1. **Aturan 2 Menit**: Kalau <2 menit, kerjakan sekarang\n2. **Pomodoro**: Kerja 25 menit, istirahat 5 menit. Ulangi.\n3. **Eat the Frog**: Kerjakan tugas tersulit pertama kali di pagi hari\n4. **Pecah Jadi Kecil**: Tugas besar → langkah-langkah kecil (langkah pertama harus sangat mudah)\n5. **Hilangkan Distraksi**: HP di ruangan lain, tutup tab sosial media\n6. **Accountability**: Beritahu seseorang target dan deadline kamu\n7. **Sistem Reward**: Selesai tugas → hadiah kecil\n\n Prokrastinasi bukan kemalasan  seringkali itu kecemasan atau perfeksionisme yang menyamar. Sayangi dirimu sambil terus maju! ";
        }

        if (preg_match('/\b(percaya diri|confidence|pede|self esteem)\b/', $msg)) {
            return $lang === 'en'
                ? " **Building Confidence, {$userName}**:\n\n1. **Celebrate small wins**  every achievement counts\n2. **Positive self-talk**: Replace 'I can't' with 'I'm learning to'\n3. **Posture matters**: Stand tall, shoulders back  it affects your brain!\n4. **Prepare well**: Confidence comes from competence\n5. **Step outside comfort zone** daily  even in small ways\n6. **Stop comparing**: Your journey is unique\n7. **Dress well**: Looking good = feeling good\n8. **Help others**: Teaching/helping boosts your sense of capability\n\n Confidence isn't 'I won't fail.' It's 'I'll be okay even if I fail.'"
                : " **Membangun Percaya Diri, {$userName}**:\n\n1. **Rayakan kemenangan kecil**  setiap pencapaian berarti\n2. **Self-talk positif**: Ganti 'Aku tidak bisa' dengan 'Aku sedang belajar'\n3. **Postur penting**: Berdiri tegak, bahu ke belakang  ini mempengaruhi otak!\n4. **Persiapkan diri**: Percaya diri datang dari kompetensi\n5. **Keluar dari zona nyaman** setiap hari  bahkan hal kecil\n6. **Berhenti membandingkan**: Perjalananmu unik\n7. **Berpenampilan baik**: Terlihat baik = merasa baik\n8. **Bantu orang lain**: Mengajar/membantu meningkatkan rasa mampu\n\n Percaya diri bukan 'Aku tidak akan gagal.' Tapi 'Aku akan baik-baik saja meski gagal.'";
        }

        return $lang === 'en'
            ? " **Personal Development for {$userName}**:\n\n**The 1% Rule**: Improve just 1% daily = 37x better in a year!\n\n **Daily Habits of Successful People**:\n1.  Wake early (5-6 AM)\n2.  Journal/plan the day\n3.  Read 20+ min\n4.  Exercise\n5.  Meditate/reflect\n6.  Prioritize 3 important tasks\n7.  Limit social media\n8.  Review the day before bed\n\nStart with ONE habit. Master it. Then add the next. "
            : " **Pengembangan Diri untuk {$userName}**:\n\n**Aturan 1%**: Tingkatkan 1% setiap hari = 37x lebih baik dalam setahun!\n\n **Kebiasaan Harian Orang Sukses**:\n1.  Bangun pagi (jam 5-6)\n2.  Journaling/rencana hari\n3.  Baca 20+ menit\n4.  Olahraga\n5.  Meditasi/refleksi\n6.  Prioritaskan 3 tugas penting\n7.  Batasi media sosial\n8.  Review hari sebelum tidur\n\nMulai dengan SATU kebiasaan. Kuasai. Lalu tambah yang berikutnya. ";
    }

    private function getEducationAdvice(string $msg, string $lang, string $userName): string
    {
        if (preg_match('/\b(ujian|exam|tes|test)\b/', $msg)) {
            return $lang === 'en'
                ? " **Exam Prep Tips for {$userName}**:\n\n1. **Spaced Repetition**: Review material at increasing intervals (1 day, 3 days, 1 week)\n2. **Active Recall**: Test yourself instead of re-reading. Use flashcards.\n3. **Teach Someone**: Explaining = deeper understanding (Feynman Technique)\n4. **Past Papers**: Practice with old exams\n5. **Mind Maps**: Visual summaries for complex topics\n6. **Pomodoro Study**: 25 min focus + 5 min break\n7. **Sleep Well**: Brain consolidates memory during sleep!\n8. **Exam Day**: Light breakfast, arrive early, read questions carefully before answering\n\n Remember: understanding > memorizing!"
                : " **Tips Persiapan Ujian untuk {$userName}**:\n\n1. **Spaced Repetition**: Review materi dengan interval meningkat (1 hari, 3 hari, 1 minggu)\n2. **Active Recall**: Tes diri sendiri daripada baca ulang. Gunakan flashcard.\n3. **Ajarkan Orang Lain**: Menjelaskan = pemahaman lebih dalam (Teknik Feynman)\n4. **Soal Lama**: Latihan dengan ujian sebelumnya\n5. **Mind Map**: Ringkasan visual untuk topik kompleks\n6. **Belajar Pomodoro**: 25 menit fokus + 5 menit istirahat\n7. **Tidur Cukup**: Otak mengkonsolidasi memori saat tidur!\n8. **Hari Ujian**: Sarapan ringan, datang awal, baca soal dengan teliti sebelum menjawab\n\n Ingat: memahami > menghafal!";
        }

        return $lang === 'en'
            ? " **Study Tips for {$userName}**:\n\n**Effective Learning Techniques**:\n1.  **Feynman Technique**: Explain concepts in simple words\n2.  **Spaced Repetition**: Review at intervals\n3.  **Active Recall**: Test yourself frequently\n4.  **Mind Mapping**: Connect ideas visually\n5.  **Handwrite Notes**: Better retention than typing\n6.  **Study Environment**: Quiet place, consistent spot\n7.  **Pomodoro**: 25 min focus blocks\n\nThe key is ACTIVE learning, not passive reading! "
            : " **Tips Belajar untuk {$userName}**:\n\n**Teknik Belajar Efektif**:\n1.  **Teknik Feynman**: Jelaskan konsep dengan kata sederhana\n2.  **Spaced Repetition**: Review berkala\n3.  **Active Recall**: Sering tes diri sendiri\n4.  **Mind Mapping**: Hubungkan ide secara visual\n5.  **Tulis Tangan**: Retensi lebih baik daripada mengetik\n6.  **Lingkungan Belajar**: Tempat tenang, spot konsisten\n7.  **Pomodoro**: Blok fokus 25 menit\n\nKuncinya adalah belajar AKTIF, bukan baca pasif! ";
    }

    private function getMentalWellnessAdvice(string $msg, string $lang, string $userName): string
    {
        if (preg_match('/\b(cemas|anxiety|khawatir|worry)\b/', $msg)) {
            return $lang === 'en'
                ? " **Anxiety Management for {$userName}**:\n\n**Immediate Relief (5-4-3-2-1 Grounding)**:\n See 5 things\n Touch 4 things\n Hear 3 things\n Smell 2 things\n Taste 1 thing\n\n**Long-term Strategies**:\n1. Deep breathing: 4s in, 4s hold, 4s out\n2. Limit caffeine and alcohol\n3. Regular exercise (natural anti-anxiety)\n4. Journal your worries  externalize them\n5. Challenge catastrophic thinking: 'What's the evidence?'\n6. Progressive muscle relaxation before bed\n\n Anxiety is manageable. If it significantly impacts daily life, please consider speaking with a mental health professional. You're not alone, {$userName}."
                : " **Manajemen Kecemasan untuk {$userName}**:\n\n**Bankak Segera (Grounding 5-4-3-2-1)**:\n Lihat 5 benda\n Sentuh 4 benda\n Dengar 3 suara\n Cium 2 aroma\n Rasakan 1 rasa\n\n**Strategi Jangka Panjang**:\n1. Napas dalam: 4 detik masuk, 4 detik tahan, 4 detik keluar\n2. Batasi kafein dan alkohol\n3. Olahraga rutin (anti-cemas alami)\n4. Tulis kecemasan di jurnal  keluarkan dari pikiran\n5. Tantang pikiran katastrofis: 'Apa buktinya?'\n6. Relaksasi otot progresif sebelum tidur\n\n Kecemasan bisa dikelola. Jika sangat mengganggu kehidupan sehari-hari, pertimbangkan berbicara dengan profesional kesehatan mental. Kamu tidak sendiri, {$userName}.";
        }

        return $lang === 'en'
            ? " **Mental Wellness for {$userName}**:\n\n**Daily Self-Care Checklist**:\n Drink enough water\n Move your body (even 10 min walk)\n Connect with someone you care about\n Do one thing just for fun\n Take breaks from screens\n Practice gratitude (3 things you're thankful for)\n Say no to one thing that drains you\n\n Mental health is health. Taking care of your mind is not selfish  it's essential.\n\n If you're struggling significantly, please reach out to a mental health professional. You deserve support."
            : " **Kesehatan Mental untuk {$userName}**:\n\n**Checklist Self-Care Harian**:\n Minum cukup air\n Gerakkan tubuh (bahkan jalan 10 menit)\n Terhubung dengan orang yang kamu sayangi\n Lakukan satu hal untuk bersenang-senang\n Istirahat dari layar\n Latih rasa syukur (3 hal yang disyukuri)\n Katakan tidak pada satu hal yang menguras energi\n\n Kesehatan mental itu kesehatan. Merawat pikiran bukan egois  itu esensial.\n\n Jika kamu sangat kesulitan, silakan hubungi profesional kesehatan mental. Kamu layak mendapat dukungan.";
    }

    private function getLifeTransitionAdvice(string $msg, string $lang, string $userName): string
    {
        return $lang === 'en'
            ? " **Navigating Life Changes, {$userName}**:\n\n1. **Acknowledge feelings**: Change brings mixed emotions  that's normal\n2. **Focus on what you CAN control**: Not everything is in your hands, and that's okay\n3. **Build a support system**: Lean on people who care\n4. **One day at a time**: Don't try to figure everything out at once\n5. **Document the journey**: Journal through transitions\n6. **Give yourself grace**: Adjustment takes time\n7. **Find the opportunity**: Every ending is a new beginning\n\n \"The only constant in life is change.\"  Heraclitus\n\nYou've survived 100% of your hardest days. This one is no different. "
            : " **Menghadapi Perubahan Hidup, {$userName}**:\n\n1. **Akui perasaan**: Perubahan membawa emosi campur aduk  itu normal\n2. **Fokus pada yang BISA dikontrol**: Tidak semua ada di tanganmu, dan itu oke\n3. **Bangun sistem dukungan**: Bersandar pada orang yang peduli\n4. **Satu hari sekaligus**: Jangan coba selesaikan semua sekaligus\n5. **Dokumentasikan perjalanan**: Journaling melalui transisi\n6. **Beri dirimu kelonggaran**: Adaptasi butuh waktu\n7. **Temukan peluang**: Setiap akhir adalah awal baru\n\n \"Satu-satunya yang konstan dalam hidup adalah perubahan.\"  Heraclitus\n\nKamu telah melewati 100% hari terberatmu. Hari ini pun tak berbeda. ";
    }

    private function getGeneralLifeAdvice(string $lang, string $userName): string
    {
        $advices = [
            ['id' => " **Nasihat Hidup untuk {$userName}**:\n\n Hidup bukan tentang menunggu badai berlalu, tapi belajar menari di tengah hujan\n Investasi terbaik adalah pada diri sendiri  skill, kesehatan, hubungan\n Katakan 'ya' pada hal yang mengembangkanmu, 'tidak' pada hal yang menguras\n Progress > perfection. 1% lebih baik setiap hari\n Jaga 3 pilar: kesehatan fisik, mental, dan finansial\n\nAda topik spesifik yang ingin dibahas? Karir, keuangan, kesehatan, hubungan, pendidikan? ", 'en' => " **Life Advice for {$userName}**:\n\n Life isn't about waiting for the storm to pass, but learning to dance in the rain\n The best investment is in yourself  skills, health, relationships\n Say 'yes' to what grows you, 'no' to what drains you\n Progress > perfection. 1% better every day\n Guard 3 pillars: physical health, mental health, and financial health\n\nAny specific topic to discuss? Career, finance, health, relationships, education? "],
        ];
        $pick = $advices[array_rand($advices)];
        return $lang === 'en' ? $pick['en'] : $pick['id'];
    }
}
