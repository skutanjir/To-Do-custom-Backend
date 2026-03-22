<?php

namespace App\Services\Ai\Experts;

use App\Services\Ai\ExpertInterface;
use Illuminate\Support\Str;

class TranslationExpert implements ExpertInterface
{
    private const CONFIDENCE_THRESHOLD = 35;

    private array $translationKeywords = [
        'terjemahkan', 'translate', 'artinya', 'meaning', 'apa bahasa inggris',
        'what is in indonesian', 'bahasa inggris dari', 'bahasa indonesia dari',
        'in english', 'in indonesian', 'koreksi', 'correct', 'grammar', 'tata bahasa',
        'sinonim', 'synonym', 'antonim', 'antonym', 'persamaan kata', 'lawan kata',
        'idiom', 'peribahasa', 'proverb', 'ungkapan', 'definisi', 'definition',
        'apa itu', 'what is', 'arti kata', 'belajar bahasa', 'learn language',
        'kosakata', 'vocabulary', 'similar word', 'opposite',
    ];

    private array $dictionary = [
        'halo' => 'hello', 'selamat pagi' => 'good morning', 'selamat siang' => 'good afternoon',
        'selamat malam' => 'good evening', 'selamat tinggal' => 'goodbye', 'terima kasih' => 'thank you',
        'sama-sama' => 'you are welcome', 'maaf' => 'sorry', 'tolong' => 'please',
        'ya' => 'yes', 'tidak' => 'no', 'baik' => 'good', 'buruk' => 'bad',
        'besar' => 'big', 'kecil' => 'small', 'panas' => 'hot', 'dingin' => 'cold',
        'baru' => 'new', 'lama' => 'old', 'cepat' => 'fast', 'lambat' => 'slow',
        'tinggi' => 'tall', 'pendek' => 'short', 'cantik' => 'beautiful', 'tampan' => 'handsome',
        'saya' => 'I', 'kamu' => 'you', 'dia' => 'he/she', 'kami' => 'we', 'mereka' => 'they',
        'apa' => 'what', 'siapa' => 'who', 'dimana' => 'where', 'kapan' => 'when',
        'kenapa' => 'why', 'bagaimana' => 'how', 'berapa' => 'how much/many',
        'rumah' => 'house', 'sekolah' => 'school', 'kantor' => 'office', 'toko' => 'shop',
        'jalan' => 'road', 'kota' => 'city', 'desa' => 'village', 'negara' => 'country',
        'air' => 'water', 'makanan' => 'food', 'minuman' => 'drink', 'nasi' => 'rice',
        'roti' => 'bread', 'buah' => 'fruit', 'sayur' => 'vegetable', 'daging' => 'meat',
        'ikan' => 'fish', 'ayam' => 'chicken', 'susu' => 'milk', 'telur' => 'egg',
        'makan' => 'eat', 'minum' => 'drink', 'tidur' => 'sleep', 'bangun' => 'wake up',
        'berjalan' => 'walk', 'berlari' => 'run', 'duduk' => 'sit', 'berdiri' => 'stand',
        'membaca' => 'read', 'menulis' => 'write', 'berbicara' => 'speak', 'mendengar' => 'hear',
        'melihat' => 'see', 'belajar' => 'study', 'bekerja' => 'work', 'bermain' => 'play',
        'cinta' => 'love', 'benci' => 'hate', 'suka' => 'like', 'takut' => 'fear',
        'senang' => 'happy', 'sedih' => 'sad', 'marah' => 'angry', 'lelah' => 'tired',
        'lapar' => 'hungry', 'haus' => 'thirsty', 'sakit' => 'sick', 'sehat' => 'healthy',
        'hari' => 'day', 'malam' => 'night', 'pagi' => 'morning', 'siang' => 'afternoon',
        'minggu' => 'week', 'bulan' => 'month', 'tahun' => 'year', 'jam' => 'hour',
        'waktu' => 'time', 'sekarang' => 'now', 'kemarin' => 'yesterday', 'besok' => 'tomorrow',
        'senin' => 'monday', 'selasa' => 'tuesday', 'rabu' => 'wednesday', 'kamis' => 'thursday',
        'jumat' => 'friday', 'sabtu' => 'saturday', 'putih' => 'white', 'hitam' => 'black',
        'merah' => 'red', 'biru' => 'blue', 'hijau' => 'green', 'kuning' => 'yellow',
        'ibu' => 'mother', 'ayah' => 'father', 'anak' => 'child', 'kakak' => 'older sibling',
        'adik' => 'younger sibling', 'teman' => 'friend', 'guru' => 'teacher', 'murid' => 'student',
        'dokter' => 'doctor', 'polisi' => 'police', 'petani' => 'farmer', 'nelayan' => 'fisherman',
        'kucing' => 'cat', 'anjing' => 'dog', 'burung' => 'bird', 'kuda' => 'horse',
        'sapi' => 'cow', 'gajah' => 'elephant', 'harimau' => 'tiger', 'ular' => 'snake',
        'bunga' => 'flower', 'pohon' => 'tree', 'gunung' => 'mountain', 'laut' => 'sea',
        'sungai' => 'river', 'hujan' => 'rain', 'angin' => 'wind', 'matahari' => 'sun',
        'bulan_langit' => 'moon', 'bintang' => 'star', 'awan' => 'cloud', 'tanah' => 'land',
        'buku' => 'book', 'pena' => 'pen', 'meja' => 'table', 'kursi' => 'chair',
        'pintu' => 'door', 'jendela' => 'window', 'komputer' => 'computer', 'telepon' => 'phone',
        'uang' => 'money', 'harga' => 'price', 'murah' => 'cheap', 'mahal' => 'expensive',
        'membeli' => 'buy', 'menjual' => 'sell', 'membayar' => 'pay', 'gratis' => 'free',
        'indah' => 'beautiful', 'pintar' => 'smart', 'bodoh' => 'stupid', 'kaya' => 'rich',
        'miskin' => 'poor', 'kuat' => 'strong', 'lemah' => 'weak', 'berani' => 'brave',
        'bisa' => 'can', 'harus' => 'must', 'ingin' => 'want', 'perlu' => 'need',
        'dengan' => 'with', 'tanpa' => 'without', 'untuk' => 'for', 'dari' => 'from',
        'ke' => 'to', 'di' => 'at/in', 'dan' => 'and', 'atau' => 'or', 'tapi' => 'but',
        'jika' => 'if', 'karena' => 'because', 'sudah' => 'already', 'belum' => 'not yet',
        'sangat' => 'very', 'lebih' => 'more', 'paling' => 'most', 'semua' => 'all',
        'ini' => 'this', 'itu' => 'that', 'sini' => 'here', 'sana' => 'there',
    ];

    private array $javanese = [
        'matur nuwun' => 'terima kasih', 'sugeng enjing' => 'selamat pagi',
        'sugeng sonten' => 'selamat sore', 'sugeng dalu' => 'selamat malam',
        'nggih' => 'ya', 'mboten' => 'tidak', 'monggo' => 'silakan',
        'pripun' => 'bagaimana', 'sinten' => 'siapa', 'menopo' => 'apa',
        'griya' => 'rumah', 'toya' => 'air', 'sekul' => 'nasi',
        'tilem' => 'tidur', 'maos' => 'membaca', 'nyerat' => 'menulis',
        'ngendika' => 'berbicara', 'mireng' => 'mendengar', 'ningali' => 'melihat',
        'sugeng rawuh' => 'selamat datang', 'nyuwun pangapunten' => 'minta maaf',
        'kula' => 'saya', 'panjenengan' => 'Anda', 'piyambakipun' => 'beliau',
    ];

    private array $sundanese = [
        'hatur nuhun' => 'terima kasih', 'wilujeng enjing' => 'selamat pagi',
        'wilujeng sonten' => 'selamat sore', 'wilujeng wengi' => 'selamat malam',
        'muhun' => 'ya', 'henteu' => 'tidak', 'mangga' => 'silakan',
        'kumaha' => 'bagaimana', 'saha' => 'siapa', 'naon' => 'apa',
        'bumi' => 'rumah', 'cai' => 'air', 'sangu' => 'nasi',
        'sare' => 'tidur', 'maca' => 'membaca', 'nulis' => 'menulis',
        'nyarios' => 'berbicara', 'nguping' => 'mendengar', 'ningali' => 'melihat',
        'wilujeng sumping' => 'selamat datang', 'hapunten' => 'maaf',
        'abdi' => 'saya', 'anjeun' => 'Anda',
    ];

    public function evaluate(string $message, array $context): array
    {
        $msg = mb_strtolower(trim($message));
        $lang = $context['lang'] ?? 'id';
        $confidence = $this->scoreTranslationIntent($msg);

        if ($confidence < self::CONFIDENCE_THRESHOLD) {
            return ['findings' => [], 'actions' => [], 'suggestions' => [], 'confidence' => 0];
        }

        $findings = [];
        $suggestions = [];

        $translationResult = $this->tryTranslation($msg, $lang);
        if ($translationResult) {
            $findings[] = $translationResult;
            $suggestions = $lang === 'en'
                ? ['Translate more', 'Synonyms', 'Idioms']
                : ['Terjemahkan lagi', 'Sinonim', 'Idiom'];
            return ['findings' => $findings, 'actions' => [], 'suggestions' => $suggestions, 'confidence' => $confidence];
        }

        $synonymResult = $this->trySynonym($msg, $lang);
        if ($synonymResult) {
            $findings[] = $synonymResult;
            $suggestions = $lang === 'en' ? ['More synonyms', 'Antonyms', 'Translate'] : ['Sinonim lagi', 'Antonim', 'Terjemahkan'];
            return ['findings' => $findings, 'actions' => [], 'suggestions' => $suggestions, 'confidence' => $confidence];
        }

        $antonymResult = $this->tryAntonym($msg, $lang);
        if ($antonymResult) {
            $findings[] = $antonymResult;
            $suggestions = $lang === 'en' ? ['More antonyms', 'Synonyms', 'Translate'] : ['Antonim lagi', 'Sinonim', 'Terjemahkan'];
            return ['findings' => $findings, 'actions' => [], 'suggestions' => $suggestions, 'confidence' => $confidence];
        }

        $idiomResult = $this->tryIdiom($msg, $lang);
        if ($idiomResult) {
            $findings[] = $idiomResult;
            $suggestions = $lang === 'en' ? ['More idioms', 'Proverbs', 'Translate'] : ['Idiom lagi', 'Peribahasa', 'Terjemahkan'];
            return ['findings' => $findings, 'actions' => [], 'suggestions' => $suggestions, 'confidence' => $confidence];
        }

        $jvResult = $this->tryJavanese($msg, $lang);
        if ($jvResult) {
            $findings[] = $jvResult;
            $suggestions = $lang === 'en' ? ['More Javanese', 'Sundanese', 'Indonesian'] : ['Bahasa Jawa lagi', 'Bahasa Sunda', 'Bahasa Indonesia'];
            return ['findings' => $findings, 'actions' => [], 'suggestions' => $suggestions, 'confidence' => $confidence];
        }

        $sdResult = $this->trySundanese($msg, $lang);
        if ($sdResult) {
            $findings[] = $sdResult;
            $suggestions = $lang === 'en' ? ['More Sundanese', 'Javanese', 'Indonesian'] : ['Bahasa Sunda lagi', 'Bahasa Jawa', 'Bahasa Indonesia'];
            return ['findings' => $findings, 'actions' => [], 'suggestions' => $suggestions, 'confidence' => $confidence];
        }

        $langTips = $this->tryLanguageTips($msg, $lang);
        if ($langTips) {
            $findings[] = $langTips;
            $suggestions = $lang === 'en' ? ['Vocabulary', 'Translate', 'Idioms'] : ['Kosakata', 'Terjemahkan', 'Idiom'];
            return ['findings' => $findings, 'actions' => [], 'suggestions' => $suggestions, 'confidence' => $confidence];
        }

        $findings[] = $lang === 'en'
            ? "🌐 I can help with translations and language! Try:\n• Translate words/phrases (ID↔EN)\n• Synonyms and antonyms\n• Idioms and proverbs\n• Javanese/Sundanese phrases\n• Language learning tips\n\nExample: 'translate terima kasih' or 'synonym of beautiful'"
            : "🌐 Saya bisa membantu terjemahan dan bahasa! Coba:\n• Terjemahkan kata/frasa (ID↔EN)\n• Sinonim dan antonim\n• Idiom dan peribahasa\n• Frasa Jawa/Sunda\n• Tips belajar bahasa\n\nContoh: 'terjemahkan thank you' atau 'sinonim cantik'";
        $suggestions = $lang === 'en' ? ['Translate', 'Synonyms', 'Idioms'] : ['Terjemahkan', 'Sinonim', 'Idiom'];

        return [
            'findings' => $findings,
            'actions' => [],
            'suggestions' => array_slice($suggestions, 0, 3),
            'confidence' => $confidence,
        ];
    }

    private function scoreTranslationIntent(string $msg): int
    {
        $score = 0;
        foreach ($this->translationKeywords as $kw) {
            if (str_contains($msg, $kw)) {
                $score += (mb_strlen($kw) >= 5) ? 15 : 8;
            }
        }

        if (preg_match('/\b(terjemahkan|translate)\b/', $msg)) $score += 30;
        if (preg_match('/\b(apa|what)\b.*\b(artinya|meaning|bahasa)\b/', $msg)) $score += 25;
        if (preg_match('/\b(sinonim|synonym|antonim|antonym)\b/', $msg)) $score += 25;
        if (preg_match('/\b(idiom|peribahasa|proverb)\b/', $msg)) $score += 25;
        if (preg_match('/\b(bahasa jawa|javanese|bahasa sunda|sundanese)\b/', $msg)) $score += 20;

        return min(100, $score);
    }

    private function tryTranslation(string $msg, string $lang): ?string
    {
        if (preg_match('/\b(?:terjemahkan|translate|artinya|arti dari|meaning of|bahasa inggris dari|bahasa indonesia dari|in english|in indonesian)\b\s*[:\-]?\s*(.+)/i', $msg, $m)) {
            $word = trim($m[1], ' ?.!,\'"');
            return $this->performTranslation($word, $lang);
        }

        if (preg_match('/\b(?:apa bahasa inggris)\b.*?(?:dari|nya)?\s+(.+)/i', $msg, $m)) {
            $word = trim($m[1], ' ?.!,\'"');
            return $this->translateIdToEn($word);
        }

        if (preg_match('/\b(?:apa bahasa indonesia)\b.*?(?:dari|nya)?\s+(.+)/i', $msg, $m)) {
            $word = trim($m[1], ' ?.!,\'"');
            return $this->translateEnToId($word);
        }

        return null;
    }

    private function performTranslation(string $text, string $lang): string
    {
        $lower = mb_strtolower($text);
        $reverseDictionary = array_flip($this->dictionary);

        if (isset($this->dictionary[$lower])) {
            return "🌐 **{$text}** (ID) → **{$this->dictionary[$lower]}** (EN)";
        }
        if (isset($reverseDictionary[$lower])) {
            return "🌐 **{$text}** (EN) → **{$reverseDictionary[$lower]}** (ID)";
        }

        $idResult = $this->translateIdToEn($text);
        if ($idResult && !str_contains($idResult, '[?]')) {
            return $idResult;
        }
        $enResult = $this->translateEnToId($text);
        if ($enResult && !str_contains($enResult, '[?]')) {
            return $enResult;
        }

        $closest = $this->findClosestWord($lower);
        if ($closest) {
            $isId = isset($this->dictionary[$closest]);
            $translated = $isId ? $this->dictionary[$closest] : $reverseDictionary[$closest];
            $direction = $isId ? "(ID) → **{$translated}** (EN)" : "(EN) → **{$translated}** (ID)";
            return $lang === 'en'
                ? "🌐 Did you mean **{$closest}**? {$closest} {$direction}"
                : "🌐 Mungkin maksud Anda **{$closest}**? {$closest} {$direction}";
        }

        return $lang === 'en'
            ? "🌐 Sorry, '{$text}' is not in my dictionary yet. Try common words or phrases!"
            : "🌐 Maaf, '{$text}' belum ada di kamus saya. Coba kata atau frasa umum!";
    }

    private function translateIdToEn(string $text): ?string
    {
        $words = preg_split('/\s+/', mb_strtolower($text));
        $results = [];
        $allFound = true;

        foreach ($words as $word) {
            $clean = trim($word, '.,!?;:');
            if (isset($this->dictionary[$clean])) {
                $results[] = $this->dictionary[$clean];
            } else {
                $results[] = "[{$clean}]";
                $allFound = false;
            }
        }

        if (empty($results)) return null;

        $translated = implode(' ', $results);
        $note = $allFound ? '' : "\n_(Words in [brackets] are not in dictionary)_";
        return "🌐 **ID → EN**:\n\"{$text}\" → \"{$translated}\"{$note}";
    }

    private function translateEnToId(string $text): ?string
    {
        $reverseDictionary = array_flip($this->dictionary);
        $words = preg_split('/\s+/', mb_strtolower($text));
        $results = [];
        $allFound = true;

        foreach ($words as $word) {
            $clean = trim($word, '.,!?;:');
            if (isset($reverseDictionary[$clean])) {
                $results[] = $reverseDictionary[$clean];
            } else {
                $results[] = "[{$clean}]";
                $allFound = false;
            }
        }

        if (empty($results)) return null;

        $translated = implode(' ', $results);
        $note = $allFound ? '' : "\n_(Words in [brackets] are not in dictionary)_";
        return "🌐 **EN → ID**:\n\"{$text}\" → \"{$translated}\"{$note}";
    }

    private function findClosestWord(string $input): ?string
    {
        $allWords = array_merge(array_keys($this->dictionary), array_values($this->dictionary));
        $bestMatch = null;
        $bestDistance = 3;

        foreach ($allWords as $word) {
            $dist = levenshtein($input, $word);
            if ($dist < $bestDistance) {
                $bestDistance = $dist;
                $bestMatch = $word;
            }
        }

        return $bestMatch;
    }

    private function trySynonym(string $msg, string $lang): ?string
    {
        if (!preg_match('/\b(?:sinonim|synonym|persamaan kata|similar word)\b\s*(?:dari|of|untuk|for)?\s*(.+)/i', $msg, $m)) {
            return null;
        }

        $word = trim($m[1], ' ?.!,\'"');
        $synonyms = [
            'cantik' => ['indah', 'elok', 'rupawan', 'molek', 'ayu'],
            'beautiful' => ['pretty', 'gorgeous', 'stunning', 'lovely', 'attractive'],
            'besar' => ['raksasa', 'agung', 'akbar', 'jumbo', 'luas'],
            'big' => ['large', 'huge', 'enormous', 'massive', 'gigantic'],
            'kecil' => ['mungil', 'mini', 'cilik', 'imut', 'ringkas'],
            'small' => ['tiny', 'little', 'miniature', 'petite', 'compact'],
            'bagus' => ['baik', 'hebat', 'keren', 'mantap', 'oke'],
            'good' => ['great', 'excellent', 'fine', 'wonderful', 'superb'],
            'buruk' => ['jelek', 'rusak', 'bobrok', 'payah', 'parah'],
            'bad' => ['terrible', 'awful', 'poor', 'dreadful', 'horrible'],
            'cepat' => ['kilat', 'sigap', 'tangkas', 'gesit', 'laju'],
            'fast' => ['quick', 'rapid', 'swift', 'speedy', 'hasty'],
            'senang' => ['gembira', 'bahagia', 'riang', 'girang', 'suka cita'],
            'happy' => ['joyful', 'cheerful', 'delighted', 'glad', 'pleased'],
            'sedih' => ['pilu', 'duka', 'murung', 'galau', 'nelangsa'],
            'sad' => ['unhappy', 'sorrowful', 'melancholy', 'gloomy', 'depressed'],
            'pintar' => ['cerdas', 'pandai', 'bijak', 'brilian', 'genius'],
            'smart' => ['intelligent', 'clever', 'brilliant', 'wise', 'bright'],
            'takut' => ['gentar', 'khawatir', 'cemas', 'ngeri', 'was-was'],
            'scared' => ['afraid', 'frightened', 'terrified', 'anxious', 'fearful'],
            'marah' => ['murka', 'berang', 'geram', 'gusar', 'jengkel'],
            'angry' => ['furious', 'enraged', 'irritated', 'mad', 'annoyed'],
        ];

        $lower = mb_strtolower($word);
        if (isset($synonyms[$lower])) {
            $list = implode(', ', $synonyms[$lower]);
            return "📖 **" . ($lang === 'en' ? "Synonyms" : "Sinonim") . " '{$word}'**: {$list}";
        }

        return $lang === 'en'
            ? "📖 Sorry, I don't have synonyms for '{$word}' in my database. Try common words like: beautiful, happy, sad, big, small, fast, smart."
            : "📖 Maaf, saya belum punya sinonim untuk '{$word}'. Coba kata umum seperti: cantik, senang, sedih, besar, kecil, cepat, pintar.";
    }

    private function tryAntonym(string $msg, string $lang): ?string
    {
        if (!preg_match('/\b(?:antonim|antonym|lawan kata|opposite)\b\s*(?:dari|of|untuk|for)?\s*(.+)/i', $msg, $m)) {
            return null;
        }

        $word = trim($m[1], ' ?.!,\'"');
        $antonyms = [
            'besar' => 'kecil', 'big' => 'small', 'panas' => 'dingin', 'hot' => 'cold',
            'cepat' => 'lambat', 'fast' => 'slow', 'tinggi' => 'rendah', 'tall' => 'short',
            'cantik' => 'jelek', 'beautiful' => 'ugly', 'senang' => 'sedih', 'happy' => 'sad',
            'kaya' => 'miskin', 'rich' => 'poor', 'kuat' => 'lemah', 'strong' => 'weak',
            'gelap' => 'terang', 'dark' => 'light', 'baru' => 'lama', 'new' => 'old',
            'baik' => 'buruk', 'good' => 'bad', 'benar' => 'salah', 'right' => 'wrong',
            'atas' => 'bawah', 'up' => 'down', 'dalam' => 'luar', 'inside' => 'outside',
            'hidup' => 'mati', 'alive' => 'dead', 'pintar' => 'bodoh', 'smart' => 'stupid',
            'berani' => 'pengecut', 'brave' => 'cowardly', 'mahal' => 'murah', 'expensive' => 'cheap',
            'keras' => 'lembut', 'hard' => 'soft', 'ramai' => 'sepi', 'crowded' => 'empty',
        ];

        $lower = mb_strtolower($word);
        if (isset($antonyms[$lower])) {
            return "📖 **" . ($lang === 'en' ? "Antonym" : "Antonim") . " '{$word}'**: {$antonyms[$lower]}";
        }

        $flipped = array_flip($antonyms);
        if (isset($flipped[$lower])) {
            return "📖 **" . ($lang === 'en' ? "Antonym" : "Antonim") . " '{$word}'**: {$flipped[$lower]}";
        }

        return $lang === 'en'
            ? "📖 Sorry, I don't have antonyms for '{$word}'. Try: big, hot, fast, happy, good, strong, rich, new."
            : "📖 Maaf, saya belum punya antonim untuk '{$word}'. Coba: besar, panas, cepat, senang, baik, kuat, kaya, baru.";
    }

    private function tryIdiom(string $msg, string $lang): ?string
    {
        if (!preg_match('/\b(idiom|peribahasa|proverb|ungkapan)\b/', $msg)) {
            return null;
        }

        $idioms = [
            ['idiom' => 'Air susu dibalas air tuba', 'meaning_id' => 'Kebaikan dibalas dengan kejahatan', 'meaning_en' => 'Kindness repaid with cruelty', 'equivalent' => 'To bite the hand that feeds you'],
            ['idiom' => 'Bagai air di daun talas', 'meaning_id' => 'Orang yang tidak tetap pendiriannya', 'meaning_en' => 'Someone who is inconsistent', 'equivalent' => 'Wishy-washy / flip-flopper'],
            ['idiom' => 'Berakit-rakit ke hulu, berenang-renang ke tepian', 'meaning_id' => 'Bersakit-sakit dahulu, bersenang-senang kemudian', 'meaning_en' => 'Endure hardship first, enjoy later', 'equivalent' => 'No pain, no gain'],
            ['idiom' => 'Sedia payung sebelum hujan', 'meaning_id' => 'Bersiap-siap sebelum sesuatu terjadi', 'meaning_en' => 'Prepare before something happens', 'equivalent' => 'Better safe than sorry'],
            ['idiom' => 'Tak ada gading yang tak retak', 'meaning_id' => 'Tidak ada yang sempurna di dunia ini', 'meaning_en' => 'Nothing is perfect in this world', 'equivalent' => "Nobody's perfect"],
            ['idiom' => 'Sambil menyelam minum air', 'meaning_id' => 'Melakukan dua hal sekaligus', 'meaning_en' => 'Doing two things at once', 'equivalent' => 'Kill two birds with one stone'],
            ['idiom' => 'Habis gelap terbitlah terang', 'meaning_id' => 'Setelah masa sulit, akan datang masa yang baik', 'meaning_en' => 'After darkness comes light', 'equivalent' => 'Every cloud has a silver lining'],
            ['idiom' => 'Besar pasak daripada tiang', 'meaning_id' => 'Pengeluaran lebih besar dari pendapatan', 'meaning_en' => 'Spending more than earning', 'equivalent' => 'Living beyond your means'],
            ['idiom' => 'Seperti air dan minyak', 'meaning_id' => 'Dua hal yang tidak bisa bersatu', 'meaning_en' => 'Two things that cannot unite', 'equivalent' => 'Like oil and water'],
            ['idiom' => 'Ada udang di balik batu', 'meaning_id' => 'Ada maksud tersembunyi', 'meaning_en' => 'There is a hidden agenda', 'equivalent' => "There's more than meets the eye"],
            ['idiom' => 'Buah jatuh tidak jauh dari pohonnya', 'meaning_id' => 'Anak biasanya mirip orang tuanya', 'meaning_en' => 'Children usually resemble their parents', 'equivalent' => "The apple doesn't fall far from the tree"],
            ['idiom' => 'Nasi sudah menjadi bubur', 'meaning_id' => 'Sesuatu yang sudah terjadi tidak bisa diubah', 'meaning_en' => "What's done is done", 'equivalent' => 'No use crying over spilled milk'],
        ];

        $pick = $idioms[array_rand($idioms)];
        return $lang === 'en'
            ? "📚 **Idiom/Proverb**:\n\n🇮🇩 \"{$pick['idiom']}\"\n💡 Meaning: {$pick['meaning_en']}\n🇬🇧 English equivalent: \"{$pick['equivalent']}\""
            : "📚 **Idiom/Peribahasa**:\n\n🇮🇩 \"{$pick['idiom']}\"\n💡 Arti: {$pick['meaning_id']}\n🇬🇧 Padanan Inggris: \"{$pick['equivalent']}\"";
    }

    private function tryJavanese(string $msg, string $lang): ?string
    {
        if (!preg_match('/\b(jawa|javanese|bahasa jawa)\b/', $msg)) {
            return null;
        }

        $lines = [];
        $lines[] = $lang === 'en' ? "🏛️ **Common Javanese Phrases**:\n" : "🏛️ **Frasa Umum Bahasa Jawa**:\n";

        $sample = array_rand($this->javanese, min(8, count($this->javanese)));
        if (!is_array($sample)) $sample = [$sample];

        foreach ($sample as $jv) {
            $id = $this->javanese[$jv];
            $lines[] = "• **{$jv}** → {$id}";
        }

        return implode("\n", $lines);
    }

    private function trySundanese(string $msg, string $lang): ?string
    {
        if (!preg_match('/\b(sunda|sundanese|bahasa sunda)\b/', $msg)) {
            return null;
        }

        $lines = [];
        $lines[] = $lang === 'en' ? "🏛️ **Common Sundanese Phrases**:\n" : "🏛️ **Frasa Umum Bahasa Sunda**:\n";

        $sample = array_rand($this->sundanese, min(8, count($this->sundanese)));
        if (!is_array($sample)) $sample = [$sample];

        foreach ($sample as $sd) {
            $id = $this->sundanese[$sd];
            $lines[] = "• **{$sd}** → {$id}";
        }

        return implode("\n", $lines);
    }

    private function tryLanguageTips(string $msg, string $lang): ?string
    {
        if (!preg_match('/\b(belajar bahasa|learn language|tips bahasa|language tips)\b/', $msg)) {
            return null;
        }

        return $lang === 'en'
            ? "📚 **Language Learning Tips**:\n\n1. **Immersion**: Surround yourself with the language (music, movies, podcasts)\n2. **Flashcards**: Use spaced repetition (Anki app)\n3. **Daily Practice**: Even 15 min/day makes a huge difference\n4. **Speak Early**: Don't wait until 'perfect' — practice speaking from day 1\n5. **Label Things**: Put sticky notes on objects with their names\n6. **Think in the Language**: Try narrating daily activities\n7. **Find a Partner**: Language exchange with native speakers\n8. **Read**: Start with children's books, then progress\n9. **Write**: Keep a journal in the target language\n10. **Be Patient**: Fluency takes time — enjoy the journey!\n\n🎯 Consistency beats intensity. 15 min daily > 2 hours weekly!"
            : "📚 **Tips Belajar Bahasa**:\n\n1. **Immersion**: Kelilingi diri dengan bahasa target (musik, film, podcast)\n2. **Flashcard**: Gunakan spaced repetition (aplikasi Anki)\n3. **Latihan Harian**: Bahkan 15 menit/hari sudah membuat perbedaan besar\n4. **Bicara Sejak Awal**: Jangan tunggu sempurna — latihan bicara dari hari pertama\n5. **Label Benda**: Tempel sticky note pada benda dengan namanya\n6. **Berpikir dalam Bahasa Target**: Coba narasi kegiatan harian\n7. **Cari Partner**: Tukar bahasa dengan penutur asli\n8. **Baca**: Mulai dari buku anak-anak, lalu bertahap\n9. **Tulis**: Buat jurnal dalam bahasa target\n10. **Sabar**: Kefasihan butuh waktu — nikmati prosesnya!\n\n🎯 Konsistensi mengalahkan intensitas. 15 menit/hari > 2 jam/minggu!";
    }
}
