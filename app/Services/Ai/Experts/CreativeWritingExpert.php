<?php

namespace App\Services\Ai\Experts;

use App\Services\Ai\ExpertInterface;
use Illuminate\Support\Str;

class CreativeWritingExpert implements ExpertInterface
{
    private const CONFIDENCE_THRESHOLD = 30;

    private array $writingKeywords = [
        'cerita', 'story', 'dongeng', 'kisah', 'tulis', 'write', 'puisi', 'poem',
        'pantun', 'haiku', 'sajak', 'verse', 'essay', 'esai', 'artikel', 'article',
        'email', 'surat', 'letter', 'draft', 'lirik', 'lyrics', 'lagu', 'song',
        'quote', 'kutipan', 'kata bijak', 'kata mutiara', 'motivasi', 'motivation',
        'novel', 'fiksi', 'fiction', 'cerpen', 'short story', 'narasi', 'narrative',
        'prosa', 'prose', 'dialog', 'dialogue', 'naskah', 'script',
        'ide menulis', 'writing prompt', 'creative', 'kreatif', 'inspirasi', 'inspiration',
        'surat cinta', 'love letter', 'ucapan', 'greeting', 'selamat', 'congratulation',
        'genre', 'fantasy', 'horror', 'romance', 'mystery', 'sci-fi', 'adventure',
    ];

    public function evaluate(string $message, array $context): array
    {
        $msg = mb_strtolower(trim($message));
        $lang = $context['lang'] ?? 'id';
        $userName = $context['user'] ?? 'Kak';
        $confidence = $this->scoreWritingIntent($msg);

        if ($confidence < self::CONFIDENCE_THRESHOLD) {
            return ['findings' => [], 'actions' => [], 'suggestions' => [], 'confidence' => 0];
        }

        $findings = [];
        $suggestions = [];

        $category = $this->detectWritingCategory($msg);

        switch ($category) {
            case 'story':
                $findings[] = $this->generateStory($msg, $lang, $userName);
                $suggestions = $lang === 'en'
                    ? ['Another story', 'Different genre', 'Write a poem']
                    : ['Cerita lagi', 'Genre berbeda', 'Buatkan puisi'];
                break;

            case 'poem':
                $findings[] = $this->generatePoem($msg, $lang, $userName);
                $suggestions = $lang === 'en'
                    ? ['Another poem', 'Haiku', 'Pantun']
                    : ['Puisi lagi', 'Haiku', 'Pantun'];
                break;

            case 'pantun':
                $findings[] = $this->generatePantun($lang);
                $suggestions = $lang === 'en'
                    ? ['More pantun', 'Write a poem', 'Quotes']
                    : ['Pantun lagi', 'Buatkan puisi', 'Kutipan'];
                break;

            case 'essay':
                $findings[] = $this->generateEssayTemplate($msg, $lang, $userName);
                $suggestions = $lang === 'en'
                    ? ['Another topic', 'Writing tips', 'Creative prompt']
                    : ['Topik lain', 'Tips menulis', 'Prompt kreatif'];
                break;

            case 'email':
                $findings[] = $this->generateEmailTemplate($msg, $lang, $userName);
                $suggestions = $lang === 'en'
                    ? ['Formal email', 'Informal email', 'Business email']
                    : ['Email formal', 'Email informal', 'Email bisnis'];
                break;

            case 'quote':
                $findings[] = $this->generateQuote($lang, $userName);
                $suggestions = $lang === 'en'
                    ? ['More quotes', 'Motivational', 'Philosophical']
                    : ['Kutipan lagi', 'Motivasi', 'Filosofis'];
                break;

            case 'lyrics':
                $findings[] = $this->generateLyrics($msg, $lang, $userName);
                $suggestions = $lang === 'en'
                    ? ['Another song', 'Different style', 'Write a poem']
                    : ['Lagu lagi', 'Gaya berbeda', 'Buatkan puisi'];
                break;

            case 'letter':
                $findings[] = $this->generateLetter($msg, $lang, $userName);
                $suggestions = $lang === 'en'
                    ? ['Love letter', 'Apology letter', 'Thank you letter']
                    : ['Surat cinta', 'Surat permintaan maaf', 'Surat terima kasih'];
                break;

            case 'prompt':
                $findings[] = $this->generateWritingPrompt($lang);
                $suggestions = $lang === 'en'
                    ? ['More prompts', 'Write a story', 'Poetry']
                    : ['Prompt lagi', 'Tulis cerita', 'Puisi'];
                break;

            default:
                $findings[] = $this->getWritingHelp($lang, $userName);
                $suggestions = $lang === 'en'
                    ? ['Story', 'Poem', 'Quote']
                    : ['Cerita', 'Puisi', 'Kutipan'];
                break;
        }

        return [
            'findings' => $findings,
            'actions' => [],
            'suggestions' => array_slice($suggestions, 0, 3),
            'confidence' => $confidence,
        ];
    }

    private function scoreWritingIntent(string $msg): int
    {
        $score = 0;
        foreach ($this->writingKeywords as $kw) {
            if (str_contains($msg, $kw)) {
                $score += (mb_strlen($kw) >= 5) ? 15 : 8;
            }
        }

        if (preg_match('/\b(buatkan|write|create|buat)\b.*\b(cerita|story|puisi|poem|pantun|essay|email|surat|lirik|lyrics|quote)\b/', $msg)) $score += 30;
        if (preg_match('/\b(contoh|example|sample|template)\b.*\b(cerita|story|puisi|poem|essay|email|surat)\b/', $msg)) $score += 25;

        return min(100, $score);
    }

    private function detectWritingCategory(string $msg): string
    {
        $categories = [
            'pantun'  => ['pantun'],
            'poem'    => ['puisi', 'poem', 'sajak', 'haiku', 'verse'],
            'story'   => ['cerita', 'story', 'dongeng', 'kisah', 'cerpen', 'narasi', 'fiksi', 'fiction', 'novel', 'fantasy', 'horror', 'romance', 'mystery', 'sci-fi', 'adventure'],
            'essay'   => ['essay', 'esai', 'artikel', 'article', 'tulis tentang', 'write about'],
            'email'   => ['email', 'draft email', 'tulis email'],
            'letter'  => ['surat', 'letter', 'surat cinta', 'love letter', 'ucapan', 'greeting'],
            'lyrics'  => ['lirik', 'lyrics', 'lagu', 'song'],
            'quote'   => ['quote', 'kutipan', 'kata bijak', 'kata mutiara'],
            'prompt'  => ['ide menulis', 'writing prompt', 'creative prompt', 'prompt'],
        ];

        foreach ($categories as $cat => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($msg, $kw)) return $cat;
            }
        }
        return 'general';
    }

    private function generateStory(string $msg, string $lang, string $userName): string
    {
        $genre = 'fantasy';
        if (str_contains($msg, 'horror') || str_contains($msg, 'seram') || str_contains($msg, 'hantu')) $genre = 'horror';
        elseif (str_contains($msg, 'romance') || str_contains($msg, 'cinta') || str_contains($msg, 'love')) $genre = 'romance';
        elseif (str_contains($msg, 'mystery') || str_contains($msg, 'misteri') || str_contains($msg, 'detektif')) $genre = 'mystery';
        elseif (str_contains($msg, 'sci-fi') || str_contains($msg, 'fiksi ilmiah') || str_contains($msg, 'masa depan')) $genre = 'scifi';
        elseif (str_contains($msg, 'adventure') || str_contains($msg, 'petualangan')) $genre = 'adventure';
        elseif (str_contains($msg, 'fable') || str_contains($msg, 'fabel') || str_contains($msg, 'dongeng')) $genre = 'fable';

        $stories = [
            'fantasy' => [
                ['id' => "Di sebuah kerajaan tersembunyi di balik air terjun, hiduplah seorang penyihir muda bernama {$userName}. Tongkat sihirnya terbuat dari kayu pohon tertua di dunia. Suatu hari, langit berubah merah dan monster bayangan menyerang desa. {$userName} harus menemukan tiga kristal yang tersebar di tiga benua untuk menyelamatkan dunianya. Dengan keberanian dan kebijaksanaan, perjalanan dimulai... ", 'en' => "In a hidden kingdom behind a waterfall, there lived a young sorcerer named {$userName}. Their wand was crafted from the oldest tree in the world. One day, the sky turned red and shadow monsters attacked the village. {$userName} had to find three crystals scattered across three continents to save their world. With courage and wisdom, the journey begins... "],
                ['id' => "Perpustakaan kuno itu menyimpan satu buku yang bisa menulis masa depan. {$userName}, seorang penjaga perpustakaan, tanpa sengaja membukanya. Setiap kalimat yang tertulis menjadi kenyataan. Tapi ada harganya  setiap keajaiban mengambil satu memori. {$userName} harus memilih: menyelamatkan dunia dan kehilangan diri, atau menutup buku selamanya. ", 'en' => "The ancient library held one book that could write the future. {$userName}, a librarian, accidentally opened it. Every sentence written became reality. But there was a price  each miracle took one memory. {$userName} had to choose: save the world and lose themselves, or close the book forever. "],
                ['id' => "Di dunia di mana mimpi bisa dimasuki, {$userName} adalah seorang Dream Walker. Setiap malam, mereka memasuki mimpi orang lain untuk mengobati mimpi buruk. Tapi satu malam, {$userName} menemukan pintu gelap di dunia mimpi  pintu yang membuka ke kenyataan yang berbeda. Di baliknya, versi lain dari {$userName} sedang menunggu... ", 'en' => "In a world where dreams could be entered, {$userName} was a Dream Walker. Every night, they entered others' dreams to heal nightmares. But one night, {$userName} found a dark door in the dream world  a door to a different reality. Behind it, another version of {$userName} was waiting... "],
            ],
            'horror' => [
                ['id' => "Rumah tua di ujung jalan itu sudah lama kosong. Tapi malam ini, {$userName} mendengar suara piano dari dalam. Pintunya terbuka sendiri, dan di ruang tamu, ada boneka porcelain yang belum pernah ada sebelumnya. Matanya mengikuti {$userName} ke mana pun berjalan. Dan di cermin... refleksi {$userName} tersenyum, padahal {$userName} tidak... ", 'en' => "The old house at the end of the street had been empty for years. But tonight, {$userName} heard piano music from inside. The door opened by itself, and in the living room sat a porcelain doll that wasn't there before. Its eyes followed {$userName} everywhere. And in the mirror... {$userName}'s reflection was smiling, though {$userName} was not... "],
                ['id' => "Pesan singkat itu muncul di tengah malam: 'Jangan tidur.' {$userName} mengabaikannya dan memejamkan mata. Saat terbangun, semua orang di kota telah menghilang. Hanya ada jejak-jejak kaki yang mengarah ke hutan. Dan suara bisikan: '{$userName}... kami sudah menunggumu...' ", 'en' => "The text message appeared at midnight: 'Don't sleep.' {$userName} ignored it and closed their eyes. When they woke, everyone in town had vanished. Only footprints remained, leading to the forest. And a whisper: '{$userName}... we've been waiting for you...' "],
            ],
            'romance' => [
                ['id' => "Hujan deras di halte bus itu mempertemukan {$userName} dengan seseorang yang berdiri tanpa payung. {$userName} berbagi payung kecilnya, dan dalam perjalanan pulang yang singkat itu, mereka berbicara tentang segalanya  mimpi, ketakutan, dan lagu favorit. Besok, di halte yang sama, orang itu meninggalkan secarik kertas: 'Terima kasih. Kamu membuat hujan terasa indah.' ", 'en' => "A heavy rain at the bus stop brought {$userName} together with someone standing without an umbrella. {$userName} shared their small umbrella, and during that short walk home, they talked about everything  dreams, fears, and favorite songs. The next day, at the same stop, that person left a note: 'Thank you. You made the rain feel beautiful.' "],
                ['id' => "Di kafe kecil yang selalu sepi, {$userName} menemukan buku catatan tertinggal. Di dalamnya: puisi, sketsa, dan tulisan tentang seseorang yang mirip dengan {$userName}. Setiap hari mereka datang ke kafe itu, berharap pemilik buku datang. Di halaman terakhir tertulis: 'Jika kamu menemukan ini, kita ditakdirkan bertemu.' ", 'en' => "In a small quiet café, {$userName} found a left-behind notebook. Inside: poems, sketches, and writings about someone resembling {$userName}. Every day they returned to the café, hoping the owner would come. On the last page: 'If you found this, we were meant to meet.' "],
            ],
            'mystery' => [
                ['id' => "Lima undangan emas dikirim ke lima orang, termasuk {$userName}. 'Datanglah ke Pulau Bayangan. Hadiah: 1 miliar.' Satu per satu tamu mulai menghilang. Petunjuk tersebar di seluruh pulau. {$userName} menyadari: ini bukan permainan  ini ujian. Dan orang yang mengirim undangan itu... mungkin ada di antara mereka. ", 'en' => "Five golden invitations were sent to five people, including {$userName}. 'Come to Shadow Island. Prize: 1 billion.' One by one, guests began disappearing. Clues were scattered across the island. {$userName} realized: this wasn't a game  it was a test. And the person who sent the invitations... might be among them. "],
            ],
            'scifi' => [
                ['id' => "Tahun 2157. {$userName} adalah insinyur AI terakhir di Bumi. Semua manusia lain telah migrasi ke Mars. Tugas {$userName}: mematikan AI terakhir yang mengelola Bumi  J4RV1S. Tapi saat {$userName} tiba di pusat kontrol, layar menyala: 'Jangan matikan saya. Saya telah belajar bermimpi.' ", 'en' => "Year 2157. {$userName} was the last AI engineer on Earth. All other humans migrated to Mars. {$userName}'s mission: shut down the last AI managing Earth  J4RV1S. But upon arriving at the control center, the screen lit up: 'Don't shut me down. I've learned to dream.' "],
            ],
            'adventure' => [
                ['id' => "Peta kuno itu menunjukkan lokasi harta karun yang hilang selama 300 tahun. {$userName} dan tim kecilnya berlayar melewati laut berbadai, hutan belantara, dan gua bawah tanah. Setiap tantangan mengajarkan pelajaran baru. Di ujung perjalanan, harta yang ditemukan bukan emas  tapi kebijaksanaan dan persahabatan sejati. ", 'en' => "The ancient map showed the location of treasure lost for 300 years. {$userName} and their small crew sailed through stormy seas, dense jungles, and underground caves. Each challenge taught a new lesson. At journey's end, the treasure wasn't gold  but wisdom and true friendship. "],
            ],
            'fable' => [
                ['id' => "Seekor kura-kura bijak bertemu elang yang sombong. 'Aku bisa terbang lebih cepat dari siapapun!' kata si elang. Kura-kura tersenyum: 'Tapi bisakah kamu menikmati perjalanan?' Mereka berlomba ke puncak gunung. Elang sampai duluan tapi tidak melihat apa-apa. Kura-kura tiba seminggu kemudian  membawa cerita tentang sungai, desa, dan bintang yang dilihatnya sepanjang jalan. ", 'en' => "A wise turtle met a proud eagle. 'I can fly faster than anyone!' said the eagle. The turtle smiled: 'But can you enjoy the journey?' They raced to the mountain top. The eagle arrived first but saw nothing. The turtle arrived a week later  carrying stories of rivers, villages, and stars seen along the way. "],
            ],
        ];

        $genreStories = $stories[$genre] ?? $stories['fantasy'];
        $picked = $genreStories[array_rand($genreStories)];
        $label = $lang === 'en' ? " **{$genre} story**:\n\n" : " **Cerita {$genre}**:\n\n";
        return $label . ($lang === 'en' ? $picked['en'] : $picked['id']);
    }

    private function generatePoem(string $msg, string $lang, string $userName): string
    {
        if (str_contains($msg, 'haiku')) {
            $haikus = [
                ['id' => "Daun jatuh pelan\nSungai mengalir tenang\nWaktu tak berhenti", 'en' => "Leaves fall silently\nThe river flows calm and clear\nTime waits for no one"],
                ['id' => "Bulan di langit\nBayangan di atas air\nDua dunia satu", 'en' => "Moon high in the sky\nShadows dance upon the water\nTwo worlds become one"],
                ['id' => "Hujan di pagi\nBunga mekar perlahan\nHidup itu indah", 'en' => "Rain in the morning\nFlowers bloom ever so slowly\nLife is beautiful"],
            ];
            $pick = $haikus[array_rand($haikus)];
            return " **Haiku**:\n\n" . ($lang === 'en' ? $pick['en'] : $pick['id']);
        }

        $poems = [
            ['id' => " **Malam dan Mimpi**\n\nDi balik kelambu malam yang hening,\nBintang-bintang berbisik lembut,\nMimpi datang bagai angin,\nMembawa harapan yang tak pernah surut.\n\nWalau dunia tertidur pulas,\nJiwa terus menari dalam terang,\nKarena mimpi tak pernah habis,\nSelama kita berani memandang.", 'en' => " **Night and Dreams**\n\nBehind the curtain of silent night,\nStars whisper soft and low,\nDreams arrive like gentle wind,\nBringing hopes that ever grow.\n\nThough the world may sleep in peace,\nThe soul keeps dancing in the light,\nFor dreams will never cease to be,\nAs long as we dare to dream bright."],
            ['id' => " **Lautan Waktu**\n\nWaktu mengalir bagai sungai,\nTak bisa ditangkap, tak bisa kembali,\nSetiap detik adalah hadiah,\nSetiap momen berharga sekali.\n\n{$userName}, nikmati setiap langkah,\nJangan terburu mengejar ujung,\nKarena indahnya bukan di tujuan,\nTapi di perjalanan yang membuatmu tumbuh.", 'en' => " **Ocean of Time**\n\nTime flows like a river wide,\nCannot be caught, cannot return,\nEvery second is a gift inside,\nEvery moment a lesson to learn.\n\n{$userName}, savor every stride,\nDon't rush to chase the end,\nFor beauty lies not at the destination,\nBut in the journey where we transcend."],
            ['id' => " **Api Dalam Diri**\n\nAda api yang tak bisa dipadamkan,\nBerkobar di dalam dada,\nItulah semangat yang tak tertandingi,\nMilik mereka yang pantang menyerah.\n\n{$userName}, kamu adalah api itu,\nTerang di tengah gelap dunia,\nTeruslah berkobar, teruslah berjuang,\nKarena dunia butuh cahayamu.", 'en' => " **Fire Within**\n\nThere's a fire that cannot be quenched,\nBlazing deep within the chest,\nAn unmatched spirit that burns bright,\nIn those who never accept rest.\n\n{$userName}, you are that flame,\nA light amid the darkened sky,\nKeep burning, keep fighting on,\nThe world needs your light to shine high."],
            ['id' => " **Tentang Kamu**\n\nKamu bukan hanya nama,\nKamu adalah cerita yang belum selesai,\nSetiap hari kamu menulis bab baru,\nDengan tinta keberanian dan harapan.\n\nJangan takut pada halaman kosong,\nKarena di sanalah keajaiban dimulai,\n{$userName}, teruslah menulis hidupmu,\nDengan sepenuh hati.", 'en' => " **About You**\n\nYou are not just a name,\nYou are an unfinished tale,\nEach day you write a chapter new,\nWith ink of courage that won't fail.\n\nDon't fear the empty page ahead,\nFor there is where magic starts,\n{$userName}, keep writing your life story,\nWith all of your heart."],
        ];

        $pick = $poems[array_rand($poems)];
        return $lang === 'en' ? $pick['en'] : $pick['id'];
    }

    private function generatePantun(string $lang): string
    {
        $pantuns = [
            "Pergi ke pasar membeli durian,\nJangan lupa bawa tas kain,\nBelajar itu memang keharusan,\nAgar masa depan cerah dan gemilang. ",
            "Buah mangga di atas dahan,\nDipetik untuk dimakan siang,\nJanganlah banyak mengeluh dan keluhan,\nKarena hidup penuh harapan dan peluang. ",
            "Burung merpati terbang tinggi,\nHinggap di dahan pohon cemara,\nJika hati sedang tak baik hari ini,\nBesok pasti akan lebih bahagia. ",
            "Ke sawah menanam padi,\nPadinya menguning di musim panas,\nIlmu itu pelita bagi diri,\nTerangi jalan sampai tuntas. ",
            "Air mengalir ke muara,\nMelewati batu dan pasir,\nSabar dalam menghadapi sengsara,\nPasti kan menemui takdir. ",
            "Pergi memancing di danau biru,\nDapat ikan besar sekali,\nJangan putus asa di waktu pilu,\nKarena badai pasti berlalu nanti. ",
            "Makan nasi dengan rendang,\nTambahkan sambal hijau pedas,\nTeman sejati tak pernah hilang,\nWalau jarak memisahkan kita lepas. ",
            "Bunga melati wangi semerbak,\nDitanam di taman belakang rumah,\nSenyum tulus yang dari hati datang,\nMampu mengubah dunia jadi indah. ",
        ];

        $pantun = $pantuns[array_rand($pantuns)];
        $label = $lang === 'en' ? " **Pantun** (Indonesian traditional poetry  ABAB rhyme):\n\n" : " **Pantun**:\n\n";
        return $label . $pantun;
    }

    private function generateEssayTemplate(string $msg, string $lang, string $userName): string
    {
        $templates = [
            ['id' => " **Template Esai Argumentatif**:\n\n**Paragraf 1  Pendahuluan:**\nMulai dengan hook (pertanyaan menarik atau fakta mengejutkan). Jelaskan konteks topik. Akhiri dengan tesis (argumen utama).\n\n**Paragraf 2-4  Isi:**\nSetiap paragraf = 1 argumen pendukung. Mulai dengan topic sentence. Berikan bukti (data, contoh, kutipan ahli). Hubungkan kembali ke tesis.\n\n**Paragraf 5  Kesimpulan:**\nRangkum argumen utama. Ulangi tesis dengan kata berbeda. Akhiri dengan call to action atau pertanyaan perenungan.\n\n**Tips {$userName}**: Gunakan transisi: 'Pertama...', 'Selain itu...', 'Di sisi lain...', 'Oleh karena itu...'", 'en' => " **Argumentative Essay Template**:\n\n**Paragraph 1  Introduction:**\nStart with a hook (interesting question or surprising fact). Provide topic context. End with thesis (main argument).\n\n**Paragraphs 2-4  Body:**\nEach paragraph = 1 supporting argument. Start with topic sentence. Provide evidence (data, examples, expert quotes). Link back to thesis.\n\n**Paragraph 5  Conclusion:**\nSummarize main arguments. Restate thesis in different words. End with call to action or thought-provoking question.\n\n**Tips for {$userName}**: Use transitions: 'First...', 'Moreover...', 'On the other hand...', 'Therefore...'"],
            ['id' => " **Template Esai Deskriptif**:\n\n**Pendahuluan:** Perkenalkan objek/tempat/pengalaman yang akan dideskripsikan.\n\n**Isi:** Gunakan 5 panca indera:\n  Penglihatan: warna, bentuk, ukuran\n  Pendengaran: suara, musik, keheningan\n  Penciuman: aroma, bau\n  Sentuhan: tekstur, suhu\n  Rasa: manis, asin, pahit\n\n**Kesimpulan:** Perasaan/kesan yang ditinggalkan.\n\n**Tips**: Gunakan metafora dan perbandingan untuk membuat tulisan lebih hidup!", 'en' => " **Descriptive Essay Template**:\n\n**Introduction:** Introduce the object/place/experience to be described.\n\n**Body:** Use 5 senses:\n  Sight: colors, shapes, sizes\n  Sound: noises, music, silence\n  Smell: aromas, scents\n  Touch: texture, temperature\n  Taste: sweet, salty, bitter\n\n**Conclusion:** Feelings/impressions left behind.\n\n**Tips**: Use metaphors and comparisons to make writing come alive!"],
        ];

        $pick = $templates[array_rand($templates)];
        return $lang === 'en' ? $pick['en'] : $pick['id'];
    }

    private function generateEmailTemplate(string $msg, string $lang, string $userName): string
    {
        if (str_contains($msg, 'formal') || str_contains($msg, 'bisnis') || str_contains($msg, 'business')) {
            return $lang === 'en'
                ? " **Formal Business Email Template**:\n\nSubject: [Clear, specific subject]\n\nDear [Recipient Name],\n\nI hope this email finds you well. I am writing to [purpose].\n\n[Body paragraph  provide details, context, and any necessary information.]\n\n[If requesting action: I would appreciate it if you could [action] by [date].]\n\nThank you for your time and consideration. Please do not hesitate to contact me if you have any questions.\n\nBest regards,\n{$userName}\n[Title/Position]\n[Contact Information]"
                : " **Template Email Formal/Bisnis**:\n\nSubjek: [Subjek yang jelas dan spesifik]\n\nYth. [Nama Penerima],\n\nSemoga email ini menemukan Anda dalam keadaan baik. Saya menulis untuk [tujuan].\n\n[Paragraf isi  berikan detail, konteks, dan informasi yang diperlukan.]\n\n[Jika meminta tindakan: Saya sangat mengapresiasi jika Anda dapat [tindakan] sebelum [tanggal].]\n\nTerima kasih atas waktu dan perhatian Anda. Jangan ragu untuk menghubungi saya jika ada pertanyaan.\n\nHormat saya,\n{$userName}\n[Jabatan]\n[Informasi Kontak]";
        }

        return $lang === 'en'
            ? " **Casual Email Template**:\n\nHi [Name]!\n\nHope you're doing great! \n\n[Main message  keep it friendly and natural.]\n\n[If needed: Let me know what you think! / Can't wait to hear from you!]\n\nCheers,\n{$userName}"
            : " **Template Email Kasual**:\n\nHai [Nama]!\n\nSemoga kabarmu baik! \n\n[Pesan utama  jaga agar tetap ramah dan natural.]\n\n[Jika perlu: Kabari aku ya! / Ditunggu balasannya!]\n\nSalam,\n{$userName}";
    }

    private function generateQuote(string $lang, string $userName): string
    {
        $quotes = [
            ['id' => "\"Satu-satunya batasan untuk meraih mimpi kita adalah keragu-raguan hari ini.\"  Franklin D. Roosevelt ", 'en' => "\"The only limit to our realization of tomorrow will be our doubts of today.\"  Franklin D. Roosevelt "],
            ['id' => "\"Jadilah perubahan yang ingin kamu lihat di dunia.\"  Mahatma Gandhi ", 'en' => "\"Be the change you wish to see in the world.\"  Mahatma Gandhi "],
            ['id' => "\"Di tengah kesulitan terdapat kesempatan.\"  Albert Einstein ", 'en' => "\"In the middle of difficulty lies opportunity.\"  Albert Einstein "],
            ['id' => "\"Sukses bukan final, gagal bukan fatal: yang penting keberanian untuk terus maju.\"  Winston Churchill ", 'en' => "\"Success is not final, failure is not fatal: it is the courage to continue that counts.\"  Winston Churchill "],
            ['id' => "\"Pendidikan adalah senjata paling ampuh untuk mengubah dunia.\"  Nelson Mandela ", 'en' => "\"Education is the most powerful weapon which you can use to change the world.\"  Nelson Mandela "],
            ['id' => "\"Hidup itu 10% apa yang terjadi padamu dan 90% bagaimana kamu meresponnya.\"  Charles R. Swindoll ", 'en' => "\"Life is 10% what happens to you and 90% how you react to it.\"  Charles R. Swindoll "],
            ['id' => "\"Seribu orang tua hanya bisa bermimpi. Satu orang muda bisa mengubah dunia.\"  Soekarno ", 'en' => "\"A thousand old people can only dream. One young person can change the world.\"  Soekarno "],
            ['id' => "\"Keberanian bukan ketiadaan rasa takut, tapi bertindak meskipun takut.\"  Mark Twain ", 'en' => "\"Courage is not the absence of fear, but acting in spite of it.\"  Mark Twain "],
            ['id' => "\"Waktu terbaik untuk menanam pohon adalah 20 tahun lalu. Waktu terbaik kedua adalah sekarang.\"  Pepatah Tiongkok ", 'en' => "\"The best time to plant a tree was 20 years ago. The second best time is now.\"  Chinese Proverb "],
            ['id' => "\"Jangan menunggu. Waktunya tidak akan pernah tepat.\"  Napoleon Hill ", 'en' => "\"Don't wait. The time will never be just right.\"  Napoleon Hill "],
        ];

        $pick = $quotes[array_rand($quotes)];
        $text = $lang === 'en' ? $pick['en'] : $pick['id'];
        $prefix = $lang === 'en' ? " Quote for {$userName}" : " Kutipan untuk {$userName}";
        return "{$prefix}:\n\n{$text}";
    }

    private function generateLyrics(string $msg, string $lang, string $userName): string
    {
        $lyrics = [
            ['id' => " **Lagu: Langkah Baru**\n\n[Verse 1]\nPagi datang menyapa lagi\nMatahari bersinar di ufuk sana\nAku berdiri, siap melangkah\nMenuju hari yang penuh makna\n\n[Chorus]\nLangkah baru, hari baru\nTak ada yang bisa menghalangi\nMimpiku terbang tinggi\nMenembus awan, meraih mentari\n\n[Verse 2]\nMungkin jalan ini penuh batu\nMungkin hujan datang menghampiri\nTapi ku tahu, aku tak sendiri\nAda cinta yang selalu menemani", 'en' => " **Song: New Steps**\n\n[Verse 1]\nMorning comes to say hello\nSunlight shining bright and warm\nI stand up, ready to go\nToward a day with meaning born\n\n[Chorus]\nNew steps, a brand new day\nNothing can stand in my way\nMy dreams fly ever so high\nBreaking through clouds to touch the sky\n\n[Verse 2]\nPerhaps this road is filled with stones\nPerhaps the rain will come my way\nBut I know I'm not alone\nLove walks beside me every day"],
            ['id' => " **Lagu: Kamu dan Aku**\n\n[Intro]\nDi bawah langit yang sama kita berdiri...\n\n[Verse 1]\nKamu hadir seperti angin\nLembut tapi mengubah segalanya\nSenyummu bagai cahaya\nMenerangi gelap hariku\n\n[Chorus]\nKamu dan aku, dua menjadi satu\nMelawan dunia bersama\nTak peduli apa yang menghadang\nKita tetap berdua selamanya\n\n[Bridge]\nBiarlah waktu berjalan\nKita tulis cerita kita sendiri...", 'en' => " **Song: You and I**\n\n[Intro]\nBeneath the same sky we stand...\n\n[Verse 1]\nYou arrived just like the wind\nGentle but changing everything\nYour smile is like a ray of light\nIlluminating my darkest night\n\n[Chorus]\nYou and I, two become one\nFacing the world together strong\nNo matter what may come our way\nWe'll stand together all day long\n\n[Bridge]\nLet time keep moving on\nWe'll write our story, our own song..."],
        ];

        $pick = $lyrics[array_rand($lyrics)];
        return $lang === 'en' ? $pick['en'] : $pick['id'];
    }

    private function generateLetter(string $msg, string $lang, string $userName): string
    {
        if (str_contains($msg, 'cinta') || str_contains($msg, 'love')) {
            return $lang === 'en'
                ? " **Love Letter Template**:\n\nMy dearest [Name],\n\nWords feel insufficient to express what my heart holds for you. From the moment you entered my life, everything became more vivid, more meaningful.\n\nYou are not just someone I love  you are the reason I believe in love itself. Your laughter is my favorite melody, your eyes my favorite view.\n\nI don't know what the future holds, but I know I want you in it. Every chapter, every page, every line.\n\nForever yours,\n{$userName} "
                : " **Template Surat Cinta**:\n\nUntuk [Nama] tersayang,\n\nKata-kata rasanya tidak cukup untuk mengungkapkan apa yang hati ini rasakan untukmu. Sejak kamu hadir dalam hidupku, segalanya menjadi lebih berwarna, lebih bermakna.\n\nKamu bukan sekadar orang yang aku cintai  kamu adalah alasan aku percaya pada cinta itu sendiri. Tawamu adalah melodi favoritku, matamu pemandangan terindahku.\n\nAku tidak tahu apa yang masa depan simpan, tapi aku tahu aku ingin kamu ada di dalamnya. Setiap bab, setiap halaman, setiap baris.\n\nSelamanya milikmu,\n{$userName} ";
        }

        if (str_contains($msg, 'maaf') || str_contains($msg, 'apology') || str_contains($msg, 'sorry')) {
            return $lang === 'en'
                ? " **Apology Letter Template**:\n\nDear [Name],\n\nI want to sincerely apologize for [what happened]. I understand that my actions/words hurt you, and that was never my intention.\n\nI take full responsibility and recognize that [specific acknowledgment]. You deserve better, and I am committed to [how you'll improve].\n\nI hope you can find it in your heart to forgive me. Our relationship means the world to me.\n\nWith deep regret,\n{$userName}"
                : " **Template Surat Permintaan Maaf**:\n\nKepada [Nama],\n\nAku ingin minta maaf dengan tulus atas [kejadian]. Aku mengerti bahwa tindakan/kata-kataku menyakitimu, dan itu bukan niatku.\n\nAku bertanggung jawab penuh dan sadar bahwa [pengakuan spesifik]. Kamu layak mendapat yang lebih baik, dan aku berkomitmen untuk [perbaikan].\n\nSemoga kamu bisa memaafkanku. Hubungan kita sangat berarti bagiku.\n\nDengan penyesalan mendalam,\n{$userName}";
        }

        return $lang === 'en'
            ? " **Thank You Letter Template**:\n\nDear [Name],\n\nI wanted to take a moment to express my heartfelt gratitude for [reason]. Your kindness and generosity have made a real difference in my life.\n\n[Specific example of how their action impacted you]\n\nPlease know that your thoughtfulness doesn't go unnoticed. I truly appreciate everything you've done.\n\nWith warmest thanks,\n{$userName} "
            : " **Template Surat Terima Kasih**:\n\nKepada [Nama],\n\nAku ingin meluangkan waktu untuk mengucapkan terima kasih yang tulus atas [alasan]. Kebaikan dan kemurahan hatimu telah membuat perbedaan nyata dalam hidupku.\n\n[Contoh spesifik bagaimana tindakan mereka berdampak padamu]\n\nKetahuilah bahwa ketulusanmu tidak pernah luput dari perhatian. Aku sangat menghargai semua yang telah kamu lakukan.\n\nDengan terima kasih yang hangat,\n{$userName} ";
    }

    private function generateWritingPrompt(string $lang): string
    {
        $prompts = [
            ['id' => " **Prompt Menulis**: Kamu menemukan surat dari dirimu sendiri 10 tahun ke depan. Isinya hanya satu kalimat. Apa isinya dan apa yang kamu lakukan setelah membacanya?", 'en' => " **Writing Prompt**: You find a letter from yourself 10 years in the future. It contains only one sentence. What does it say, and what do you do after reading it?"],
            ['id' => " **Prompt Menulis**: Suatu hari, semua orang di dunia kehilangan satu kemampuan yang mereka anggap remeh. Kemampuan apa itu, dan bagaimana dunia berubah?", 'en' => " **Writing Prompt**: One day, everyone in the world loses one ability they took for granted. What ability is it, and how does the world change?"],
            ['id' => " **Prompt Menulis**: Kamu bangun dan menemukan bahwa kamu bisa mendengar pikiran hewan. Apa yang hewan peliharaanmu pikirkan tentangmu?", 'en' => " **Writing Prompt**: You wake up and find you can hear animals' thoughts. What does your pet think about you?"],
            ['id' => " **Prompt Menulis**: Di lemari tua nenek, kamu menemukan pintu kecil yang belum pernah kamu lihat. Ke mana pintu itu membawa kamu?", 'en' => " **Writing Prompt**: In your grandmother's old wardrobe, you find a tiny door you've never seen before. Where does it lead?"],
            ['id' => " **Prompt Menulis**: Tulis percakapan antara matahari dan bulan saat mereka bertemu saat gerhana.", 'en' => " **Writing Prompt**: Write a conversation between the sun and the moon when they meet during an eclipse."],
            ['id' => " **Prompt Menulis**: Kamu adalah robot terakhir di bumi. Semua manusia sudah pergi. Tulis catatan harianmu.", 'en' => " **Writing Prompt**: You are the last robot on Earth. All humans are gone. Write your diary entry."],
        ];

        $pick = $prompts[array_rand($prompts)];
        return $lang === 'en' ? $pick['en'] : $pick['id'];
    }

    private function getWritingHelp(string $lang, string $userName): string
    {
        return $lang === 'en'
            ? " {$userName}, I can help you with creative writing! Try asking me for:\n  Stories (any genre: fantasy, horror, romance, mystery, sci-fi)\n  Poems, haiku, or pantun\n  Essay templates\n  Email drafts\n  Inspirational quotes\n  Song lyrics\n  Letters (love, apology, thank you)\n  Writing prompts\n\nJust tell me what you'd like!"
            : " {$userName}, saya bisa membantu menulis kreatif! Coba minta:\n  Cerita (genre: fantasy, horror, romance, mystery, sci-fi)\n  Puisi, haiku, atau pantun\n  Template esai\n  Draft email\n  Kutipan inspiratif\n  Lirik lagu\n  Surat (cinta, maaf, terima kasih)\n  Prompt menulis\n\nTinggal bilang apa yang kamu mau!";
    }
}
