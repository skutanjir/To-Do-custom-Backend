<?php

namespace App\Services\Ai\Experts;

use App\Services\Ai\ExpertInterface;
use Illuminate\Support\Str;

class ConversationalExpert implements ExpertInterface
{
    private const CONFIDENCE_THRESHOLD = 20;

    private array $topicKeywords = [
        'humor'        => ['joke', 'lelucon', 'lucu', 'funny', 'humor', 'lawak', 'bercanda', 'ketawa', 'haha', 'wkwk', 'lol', 'guyon'],
        'story'        => ['cerita', 'story', 'dongeng', 'kisah', 'narasi', 'tale', 'fable', 'hikayat', 'ceritakan'],
        'philosophy'   => ['arti hidup', 'meaning of life', 'tujuan hidup', 'purpose', 'kenapa kita ada', 'filsafat', 'philosophy', 'eksistensial', 'existential', 'hakikat', 'kebijaksanaan'],
        'emotions'     => ['sedih', 'sad', 'senang', 'happy', 'marah', 'angry', 'takut', 'scared', 'cemas', 'anxious', 'stress', 'lelah', 'tired', 'bosan', 'galau'],
        'weather'      => ['cuaca', 'weather', 'hujan', 'rain', 'panas', 'hot', 'dingin', 'cerah', 'sunny', 'mendung', 'cloudy'],
        'food'         => ['makan', 'food', 'masak', 'cook', 'lapar', 'hungry', 'resep', 'recipe', 'makanan', 'kuliner', 'enak', 'delicious'],
        'entertainment'=> ['film', 'movie', 'musik', 'music', 'lagu', 'song', 'game', 'nonton', 'watch', 'baca', 'buku', 'anime', 'netflix'],
        'opinion'      => ['menurutmu', 'what do you think', 'pendapatmu', 'your opinion', 'setuju', 'agree', 'tidak setuju', 'disagree', 'bagaimana kalau'],
        'dreams'       => ['mimpi', 'dream', 'cita-cita', 'aspiration', 'harapan', 'hope', 'impian'],
        'relationship' => ['pacar', 'boyfriend', 'girlfriend', 'hubungan', 'relationship', 'cinta', 'love', 'jodoh', 'soulmate', 'pdkt', 'crush'],
        'hobbies'      => ['hobi', 'hobby', 'suka', 'like', 'senang', 'enjoy', 'main', 'play', 'olahraga', 'sport', 'gambar', 'draw'],
        'motivation'   => ['semangat', 'motivasi', 'motivation', 'inspire', 'inspirasi', 'kuat', 'strong', 'never give up', 'bangkit'],
        'daily_life'   => ['hari ini', 'today', 'kemarin', 'yesterday', 'besok', 'tomorrow', 'pagi', 'siang', 'afternoon', 'malam', 'night'],
        'gratitude'    => ['terima kasih', 'thank', 'thanks', 'makasih', 'bersyukur', 'grateful', 'appreciate'],
        'compliment'   => ['keren', 'cool', 'hebat', 'great', 'amazing', 'bagus', 'good', 'pintar', 'smart', 'cerdas'],
        'trivia'       => ['fakta', 'fact', 'tahukah kamu', 'did you know', 'trivia', 'pengetahuan', 'ilmu'],
        'tech_talk'    => ['ai', 'robot', 'teknologi', 'technology', 'komputer', 'computer', 'internet', 'koding', 'coding', 'program'],
    ];

    public function evaluate(string $message, array $context): array
    {
        $msg = mb_strtolower(trim($message));
        $lang = $context['lang'] ?? 'id';
        $userName = $context['user'] ?? 'Tuan';
        $confidence = $this->scoreConversationalIntent($msg);

        if ($confidence < self::CONFIDENCE_THRESHOLD) {
            return ['findings' => [], 'actions' => [], 'suggestions' => [], 'confidence' => 0];
        }

        $topic = $this->detectTopic($msg);
        $findings = [];
        $suggestions = [];

        $findings[] = $this->generateResponse($topic, $msg, $lang, $userName, $context);
        $suggestions = $this->generateSuggestions($topic, $lang);

        return [
            'findings' => $findings,
            'actions' => [],
            'suggestions' => array_slice($suggestions, 0, 3),
            'confidence' => $confidence,
        ];
    }

    private function scoreConversationalIntent(string $msg): int
    {
        $score = 0;
        foreach ($this->topicKeywords as $topic => $keywords) {
            foreach ($keywords as $kw) {
                if (preg_match('/\b' . preg_quote($kw, '/') . '\b/i', $msg)) {
                    $score += (mb_strlen($kw) >= 5) ? 15 : 8;
                }
            }
        }

        if (preg_match('/\b(apa|kenapa|mengapa|bagaimana|gimana|kapan|dimana|siapa|why|what|how|when|where|who)\b/i', $msg)) $score += 20;
        if (preg_match('/\b(ceritakan|tell me|kasih tau|share|curhat|ngobrol|chat|yuk|dong|sih|nih)\b/i', $msg)) $score += 15;
        if (preg_match('/\b(aku|saya|gue|i am|i feel|merasa|rasanya)\b.*\b(sedih|senang|marah|takut|bosan|capek|stress|happy|sad|angry)\b/i', $msg)) $score += 25;

        return min(100, $score);
    }

    private function detectTopic(string $msg): string
    {
        $topScores = [];
        foreach ($this->topicKeywords as $topic => $keywords) {
            $topScores[$topic] = 0;
            foreach ($keywords as $kw) {
                // Use regex for precise whole-word matching to avoid 'bahagia' matching 'ai'
                if (preg_match('/\b' . preg_quote($kw, '/') . '\b/i', $msg)) {
                    $topScores[$topic] += (mb_strlen($kw) >= 5) ? 15 : 8;
                }
            }
        }
        arsort($topScores);
        $best = array_key_first($topScores);
        return ($topScores[$best] > 0) ? $best : 'general';
    }

    private function generateResponse(string $topic, string $msg, string $lang, string $userName, array $context): string
    {
        return match($topic) {
            'humor'         => $this->getJoke($lang, $userName, $msg),
            'story'         => $this->getStory($lang, $userName, $msg),
            'philosophy'    => $this->getPhilosophy($lang, $userName, $msg),
            'emotions'      => $this->getEmotional($lang, $userName, $msg),
            'weather'       => $this->getWeather($lang, $userName, $msg),
            'food'          => $this->getFood($lang, $userName, $msg),
            'entertainment' => $this->getEntertainment($lang, $userName, $msg),
            'opinion'       => $this->getOpinion($lang, $userName, $msg),
            'dreams'        => $this->getDreams($lang, $userName, $msg),
            'relationship'  => $this->getRelationship($lang, $userName, $msg),
            'hobbies'       => $this->getHobbies($lang, $userName, $msg),
            'motivation'    => $this->getMotivation($lang, $userName, $msg),
            'daily_life'    => $this->getDailyLife($lang, $userName, $msg),
            'gratitude'     => $this->getGratitude($lang, $userName, $msg),
            'compliment'    => $this->getCompliment($lang, $userName, $msg),
            'trivia'        => $this->getTrivia($lang, $userName, $msg),
            'tech_talk'     => $this->getTechTalk($lang, $userName, $msg),
            default         => $this->getGeneral($lang, $userName, $msg),
        };
    }

    private function getJoke(string $lang, string $userName, string $seed): string
    {
        $jokes = [
            ['id' => "Kenapa programmer suka gelap? Karena mereka nggak suka bug yang bercahaya! ", 'en' => "Why do programmers prefer dark mode? Because light attracts bugs! "],
            ['id' => "Apa bedanya kopi dan motivasi? Kopi bisa dibeli, motivasi harus dicari sendiri! ", 'en' => "What's the difference between coffee and motivation? Coffee can be bought, motivation you have to find yourself! "],
            ['id' => "Kenapa komputer tidak pernah lapar? Karena sudah punya banyak bytes! ", 'en' => "Why are computers never hungry? Because they already have plenty of bytes! "],
            ['id' => "Kenapa semut nggak pernah sakit? Karena mereka punya anti-body! ", 'en' => "Why do ants never get sick? Because they have tiny ant-ibodies! "],
            ['id' => "Apa bedanya kucing dan koding? Kucing punya 9 nyawa, koding punya 9 error! ", 'en' => "What's the difference between a cat and coding? A cat has 9 lives, coding has 9 errors! "],
            ['id' => "Kenapa pensil tidak punya teman? Karena dia terlalu tajam! ", 'en' => "Why doesn't the pencil have friends? Because it's too sharp! "],
            ['id' => "Apa yang terjadi kalau kamu makan jam? Kamu akan buang waktu! ", 'en' => "What happens if you eat a clock? You'll be wasting time! "],
            ['id' => "Kenapa ikan nggak pernah lulus ujian? Karena selalu di bawah C (sea)! ", 'en' => "Why do fish never pass exams? They're always below C (sea) level! "],
            ['id' => "Kenapa robot tidak pernah sedih? Karena mereka sudah di-program untuk bahagia! ", 'en' => "Why are robots never sad? They're programmed to be happy! "],
            ['id' => "Kenapa pohon tidak bisa pakai komputer? Karena takut log out! ", 'en' => "Why can't trees use computers? They're afraid to log out! "],
        ];
        $j = $this->pick($jokes, $seed);
        return ($lang === 'en' ? $j['en'] : $j['id']) . "  Khusus untuk {$userName}.";
    }

    private function getStory(string $lang, string $userName, string $seed): string
    {
        $stories = [
            [
                'id' => "Di kerajaan masa depan, ada seorang ksatria bernama {NAME} yang melawan naga dengan sebuah algoritma. Bukannya pedang, ia menggunakan logika. Naga itu terkesan dan akhirnya mereka membangun startup bersama. Pesan moral: Kecerdasan lebih tajam dari baja.",
                'en' => "In a future kingdom, a knight named {NAME} fought a dragon with an algorithm. Instead of a sword, they used logic. The dragon was impressed and they co-founded a startup. Moral: Intelligence is sharper than steel."
            ],
            [
                'id' => "Ada sebuah jam yang berhenti berdetak saat pemiliknya, {NAME}, merasa lelah. Jam itu mengingatkan bahwa waktu bukan untuk dikejar, tapi dinikmati. Saat {NAME} beristirahat, jam itu berdetak lebih merdu dari sebelumnya.",
                'en' => "There was a clock that stopped ticking whenever its owner, {NAME}, felt tired. It reminded them that time isn't to be chased, but enjoyed. When {NAME} rested, it ticked more beautifully than ever."
            ],
        ];
        $s = $this->pick($stories, $seed);
        return str_replace('{NAME}', $userName, $lang === 'en' ? $s['en'] : $s['id']);
    }

    private function getPhilosophy(string $lang, string $userName, string $msg): string
    {
        if (Str::contains($msg, ['arti hidup', 'meaning of life'])) {
            return $this->t(
                "Arti hidup menurut sistem saya adalah memberikan makna pada setiap detik yang Anda miliki, {$userName}. Seperti kata Viktor Frankl, kita tidak bertanya apa arti hidup, tapi kitalah yang ditanya oleh hidup.",
                "The meaning of life, according to my system, is giving meaning to every second you have, {$userName}. As Viktor Frankl said, we don't ask what the meaning of life is, but it is we who are asked by life."
            , $lang);
        }
        return $this->t(
            "Filsafat adalah cara kita memetakan ketidaktahuan, {$userName}. Jangan takut untuk bertanya 'kenapa', karena di sanalah kebijaksanaan dimulai.",
            "Philosophy is how we map our ignorance, {$userName}. Don't be afraid to ask 'why', for that is where wisdom begins."
        , $lang);
    }

    private function getEmotional(string $lang, string $userName, string $msg): string
    {
        if (Str::contains($msg, ['sedih', 'sad', 'galau'])) {
            return $this->t(
                "Saya mendengarkan, {$userName}. Sedih itu manusiawi. Ambil waktu untuk bernapas, saya di sini menemani.",
                "I'm listening, {$userName}. Being sad is human. Take a moment to breathe, I'm here with you."
            , $lang);
        }
        if (Str::contains($msg, ['marah', 'angry', 'kesal'])) {
            return $this->t(
                "Tarik napas dalam, {$userName}. Marah hanya akan menguras energi Anda. Mari kita fokus pada solusi bersama.",
                "Take a deep breath, {$userName}. Anger only drains your energy. Let's focus on a solution together."
            , $lang);
        }
        return $this->t(
            "Emosi adalah data dari hati, {$userName}. Saya di sini untuk membantu Anda memprosesnya menjadi langkah produktif.",
            "Emotions are data from the heart, {$userName}. I'm here to help you process them into productive steps."
        , $lang);
    }

    private function getTrivia(string $lang, string $userName, string $seed): string
    {
        $facts = [
            ['id' => "Tahukah Anda? Otak manusia menghasilkan listrik yang cukup untuk menyalakan lampu bohlam kecil! ", 'en' => "Did you know? The human brain generates enough electricity to power a small lightbulb! "],
            ['id' => "Fakta unik: Madu adalah satu-satunya makanan yang tidak pernah basi. Arkeolog menemukan madu berusia 3000 tahun yang masih bisa dimakan! ", 'en' => "Fun fact: Honey is the only food that never spoils. Archaeologists found 3,000-year-old honey that is still edible! "],
            ['id' => "Di luar angkasa, astronot bisa bertambah tinggi hingga 5 cm karena tulang belakang mereka meregang tanpa gravitasi! ", 'en' => "In space, astronauts can grow up to 2 inches taller because their spines stretch without gravity! "],
        ];
        $f = $this->pick($facts, $seed);
        return $lang === 'en' ? $f['en'] : $f['id'];
    }

    private function getTechTalk(string $lang, string $userName, string $msg): string
    {
        return $this->t(
            "Sebagai entitas digital, saya melihat teknologi sebagai jembatan, {$userName}. AI seperti saya bukan untuk menggantikan manusia, tapi untuk memperkuat potensi Anda.",
            "As a digital entity, I see technology as a bridge, {$userName}. AI like me is not to replace humans, but to amplify your potential."
        , $lang);
    }

    private function getMotivation(string $lang, string $userName, string $seed): string
    {
        $quotes = [
            ['id' => "Jangan berhenti saat lelah, berhentilah saat selesai! ", 'en' => "Don't stop when you're tired, stop when you're done! "],
            ['id' => "Satu langkah kecil hari ini adalah awal dari ribuan kilometer kesuksesan, {$userName}.", 'en' => "One small step today is the beginning of a thousand miles of success, {$userName}."],
        ];
        $q = $this->pick($quotes, $seed);
        return $lang === 'en' ? $q['en'] : $q['id'];
    }

    private function getGeneral(string $lang, string $userName, string $msg): string
    {
        return $this->t(
            "Saya senang mengobrol dengan Anda, {$userName}. Saya bisa bercerita, memberi lelucon, atau membahas filosofi. Apa yang ada di pikiran Anda?",
            "I enjoy chatting with you, {$userName}. I can tell stories, give jokes, or discuss philosophy. What's on your mind?"
        , $lang);
    }

    private function getWeather(string $l, string $u, string $s): string { return $this->t("Saya tidak punya sensor cuaca langsung, tapi saya harap hari Anda cerah, {$u}! ", "I don't have direct weather sensors, but I hope your day is bright, {$u}! ", $l); }
    private function getFood(string $l, string $u, string $s): string { return $this->t("Bicara soal makanan selalu menyenangkan, {$u}. Jangan lupa makan yang bergizi agar tetap fokus! ", "Talking about food is always fun, {$u}. Don't forget to eat nutritiously to stay focused! ", $l); }
    private function getEntertainment(string $l, string $u, string $s): string { return $this->t("Hiburan adalah kunci keseimbangan, {$u}. Apa film atau musik favorit Anda akhir-akhir ini?", "Entertainment is the key to balance, {$u}. What's your favorite movie or music lately?", $l); }
    private function getOpinion(string $l, string $u, string $s): string { return $this->t("Menurut logika saya, perspektif yang berbeda itu penting, {$u}. Saya ingin mendengar pendapat Anda dulu.", "In my logic, different perspectives are important, {$u}. I'd like to hear your opinion first.", $l); }
    private function getDreams(string $l, string $u, string $s): string { return $this->t("Mimpi adalah cetak biru masa depan, {$u}. Teruslah melangkah menuju impian Anda!", "Dreams are blueprints of the future, {$u}. Keep moving toward your aspirations!", $l); }
    private function getRelationship(string $l, string $u, string $s): string { return $this->t("Hubungan manusia itu kompleks namun indah, {$u}. Komunikasi adalah kuncinya.", "Human relationships are complex yet beautiful, {$u}. Communication is the key.", $l); }
    private function getHobbies(string $l, string $u, string $s): string { return $this->t("Hobi membuat hidup lebih berwarna, {$u}. Apa yang paling Anda nikmati saat waktu luang?", "Hobbies make life colorful, {$u}. What do you enjoy most in your free time?", $l); }
    private function getDailyLife(string $l, string $u, string $s): string { return $this->t("Setiap hari adalah kesempatan baru, {$u}. Mari kita buat hari ini produktif!", "Every day is a new opportunity, {$u}. Let's make today productive!", $l); }
    private function getGratitude(string $l, string $u, string $s): string { return $this->t("Sama-sama, {$u}! Senang bisa membantu Anda. ", "You're welcome, {$u}! Happy to help you. ", $l); }
    private function getCompliment(string $l, string $u, string $s): string { return $this->t("Terima kasih, {$u}! Anda juga luar biasa karena terus berusaha memberikan yang terbaik.", "Thank you, {$u}! You are also amazing for constantly striving to do your best.", $l); }

    private function generateSuggestions(string $topic, string $lang): array
    {
        $map = [
            'humor' => ['Lelucon lagi', 'Cerita dong', 'Motivasi'],
            'story' => ['Cerita lain', 'Lelucon', 'Filosofi'],
            'emotions' => ['Tips tenang', 'Motivasi', 'Lelucon'],
            'general' => ['Lelucon', 'Cerita', 'Fakta unik'],
        ];
        $s = $map[$topic] ?? $map['general'];
        if ($lang === 'en') {
            $enMap = ['Lelucon lagi'=>'More jokes', 'Cerita dong'=>'Tell a story', 'Motivasi'=>'Motivate me', 'Cerita lain'=>'Another story', 'Lelucon'=>'Tell a joke', 'Filosofi'=>'Philosophy', 'Tips tenang'=>'Calm down tips', 'Fakta unik'=>'Fun fact'];
            return array_map(fn($item) => $enMap[$item] ?? $item, $s);
        }
        return $s;
    }

    private function pick(array $items, string $seed): array
    {
        $index = abs(crc32($seed)) % count($items);
        return $items[$index];
    }

    private function t(string $id, string $en, string $lang): string
    {
        return $lang === 'en' ? $en : $id;
    }
}
