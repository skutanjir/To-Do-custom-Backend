<?php

namespace App\Services\Ai\Experts;

use App\Services\Ai\ExpertInterface;
use Illuminate\Support\Str;

class MathScienceExpert implements ExpertInterface
{
    private const CONFIDENCE_THRESHOLD = 30;

    private array $mathKeywords = [
        'hitung', 'calculate', 'berapa', 'how much', 'how many', 'formula', 'rumus',
        'konversi', 'convert', 'tambah', 'add', 'kurang', 'subtract', 'kali', 'multiply',
        'bagi', 'divide', 'persen', 'percent', 'akar', 'root', 'pangkat', 'power',
        'logaritma', 'logarithm', 'sin', 'cos', 'tan', 'pi', 'luas', 'area',
        'keliling', 'perimeter', 'volume', 'diameter', 'radius', 'segitiga', 'triangle',
        'persegi', 'square', 'lingkaran', 'circle', 'kubus', 'cube', 'tabung', 'cylinder',
        'matematika', 'math', 'aljabar', 'algebra', 'geometri', 'geometry',
        'statistik', 'statistics', 'probabilitas', 'probability', 'kalkulus', 'calculus',
        'turunan', 'derivative', 'integral', 'matriks', 'matrix', 'vektor', 'vector',
        'rata-rata', 'average', 'mean', 'median', 'modus', 'mode',
        'biner', 'binary', 'hex', 'oktal', 'octal', 'desimal', 'decimal',
    ];

    private array $scienceKeywords = [
        'fisika', 'physics', 'kimia', 'chemistry', 'biologi', 'biology',
        'sains', 'science', 'atom', 'molekul', 'molecule', 'sel', 'cell',
        'dna', 'evolusi', 'evolution', 'gravitasi', 'gravity', 'cahaya', 'light',
        'energi', 'energy', 'listrik', 'electricity', 'magnet', 'magnetism',
        'termodinamika', 'thermodynamics', 'reaksi', 'reaction', 'unsur', 'element',
        'tabel periodik', 'periodic table', 'fotosintesis', 'photosynthesis',
        'ekosistem', 'ecosystem', 'tektonik', 'tectonic', 'iklim', 'climate',
        'suhu', 'temperature', 'tekanan', 'pressure', 'gelombang', 'wave',
        'proton', 'neutron', 'elektron', 'electron', 'ion', 'isotop', 'isotope',
        'gaya', 'force', 'kecepatan', 'velocity', 'percepatan', 'acceleration',
        'massa', 'mass', 'berat', 'weight', 'newton', 'joule', 'watt',
    ];

    public function evaluate(string $message, array $context): array
    {
        $msg = mb_strtolower(trim($message));
        $lang = $context['lang'] ?? 'id';
        $confidence = $this->scoreMathScienceIntent($msg);

        if ($confidence < self::CONFIDENCE_THRESHOLD) {
            return ['findings' => [], 'actions' => [], 'suggestions' => [], 'confidence' => 0];
        }

        $findings = [];
        $suggestions = [];

        $calcResult = $this->tryArithmetic($msg, $lang);
        if ($calcResult) {
            $findings[] = $calcResult;
            $suggestions = $lang === 'en'
                ? ['More calculations', 'Unit conversion', 'Math formulas']
                : ['Hitung lagi', 'Konversi satuan', 'Rumus matematika'];
            return ['findings' => $findings, 'actions' => [], 'suggestions' => $suggestions, 'confidence' => $confidence];
        }

        $percentResult = $this->tryPercentage($msg, $lang);
        if ($percentResult) {
            $findings[] = $percentResult;
            $suggestions = $lang === 'en' ? ['More calculations', 'Percentage tips'] : ['Hitung lagi', 'Tips persentase'];
            return ['findings' => $findings, 'actions' => [], 'suggestions' => $suggestions, 'confidence' => $confidence];
        }

        $convResult = $this->tryUnitConversion($msg, $lang);
        if ($convResult) {
            $findings[] = $convResult;
            $suggestions = $lang === 'en' ? ['More conversions', 'Math formulas'] : ['Konversi lagi', 'Rumus matematika'];
            return ['findings' => $findings, 'actions' => [], 'suggestions' => $suggestions, 'confidence' => $confidence];
        }

        $numberSys = $this->tryNumberConversion($msg, $lang);
        if ($numberSys) {
            $findings[] = $numberSys;
            $suggestions = $lang === 'en' ? ['More conversions', 'Binary explained'] : ['Konversi lagi', 'Penjelasan biner'];
            return ['findings' => $findings, 'actions' => [], 'suggestions' => $suggestions, 'confidence' => $confidence];
        }

        $formulaResult = $this->tryFormulaLookup($msg, $lang);
        if ($formulaResult) {
            $findings[] = $formulaResult;
            $suggestions = $lang === 'en' ? ['More formulas', 'Calculate something'] : ['Rumus lainnya', 'Hitung sesuatu'];
            return ['findings' => $findings, 'actions' => [], 'suggestions' => $suggestions, 'confidence' => $confidence];
        }

        $constantResult = $this->tryConstantLookup($msg, $lang);
        if ($constantResult) {
            $findings[] = $constantResult;
            $suggestions = $lang === 'en' ? ['More constants', 'Science facts'] : ['Konstanta lainnya', 'Fakta sains'];
            return ['findings' => $findings, 'actions' => [], 'suggestions' => $suggestions, 'confidence' => $confidence];
        }

        $scienceResult = $this->tryScienceExplanation($msg, $lang);
        if ($scienceResult) {
            $findings[] = $scienceResult;
            $suggestions = $lang === 'en' ? ['More science', 'Physics', 'Chemistry'] : ['Sains lagi', 'Fisika', 'Kimia'];
            return ['findings' => $findings, 'actions' => [], 'suggestions' => $suggestions, 'confidence' => $confidence];
        }

        $mathConcept = $this->tryMathConcept($msg, $lang);
        if ($mathConcept) {
            $findings[] = $mathConcept;
            $suggestions = $lang === 'en' ? ['More math', 'Formulas', 'Calculate'] : ['Matematika lagi', 'Rumus', 'Hitung'];
            return ['findings' => $findings, 'actions' => [], 'suggestions' => $suggestions, 'confidence' => $confidence];
        }

        $findings[] = $lang === 'en'
            ? "I can help with math and science! Try: calculations (2+2), unit conversions (km to miles), formulas, or science explanations. "
            : "Saya bisa bantu matematika dan sains! Coba: perhitungan (2+2), konversi satuan (km ke mil), rumus, atau penjelasan sains. ";
        $suggestions = $lang === 'en' ? ['Calculate', 'Convert units', 'Science facts'] : ['Hitung', 'Konversi satuan', 'Fakta sains'];

        return [
            'findings' => $findings,
            'actions' => [],
            'suggestions' => array_slice($suggestions, 0, 3),
            'confidence' => $confidence,
        ];
    }

    private function scoreMathScienceIntent(string $msg): int
    {
        $score = 0;
        foreach ($this->mathKeywords as $kw) {
            if (str_contains($msg, $kw)) {
                $score += (mb_strlen($kw) >= 5) ? 15 : 8;
            }
        }
        foreach ($this->scienceKeywords as $kw) {
            if (str_contains($msg, $kw)) {
                $score += (mb_strlen($kw) >= 5) ? 15 : 8;
            }
        }

        if (preg_match('/\d+\s*[\+\-\*\/\^]\s*\d+/', $msg)) $score += 30;
        if (preg_match('/\b(berapa|hitung|calculate|what is|how much)\b.*\d/', $msg)) $score += 25;
        if (preg_match('/\b(konversi|convert)\b.*\b(ke|to|dari|from)\b/', $msg)) $score += 25;
        if (preg_match('/\d+\s*%/', $msg)) $score += 20;
        if (preg_match('/\b(apa itu|what is|jelaskan|explain)\b.*\b(fisika|kimia|biologi|physics|chemistry|biology|atom|dna|gravitasi|gravity)\b/', $msg)) $score += 30;

        return min(100, $score);
    }

    private function tryArithmetic(string $msg, string $lang): ?string
    {
        if (preg_match('/(\d+(?:\.\d+)?)\s*([\+\-\*\/x×÷\^])\s*(\d+(?:\.\d+)?)/', $msg, $m)) {
            $a = (float)$m[1];
            $op = $m[2];
            $b = (float)$m[3];
            $result = $this->safeCalculate($a, $op, $b);
            if ($result !== null) {
                $opName = $this->operatorName($op, $lang);
                return $lang === 'en'
                    ? " {$a} {$opName} {$b} = **{$result}**"
                    : " {$a} {$opName} {$b} = **{$result}**";
            }
        }

        if (preg_match('/\b(?:berapa|hitung|calculate|what is)\b.*?(\d+(?:\.\d+)?)\s*(?:tambah|plus|\+)\s*(\d+(?:\.\d+)?)/', $msg, $m)) {
            $result = (float)$m[1] + (float)$m[2];
            return " {$m[1]} + {$m[2]} = **{$result}**";
        }
        if (preg_match('/\b(?:berapa|hitung|calculate|what is)\b.*?(\d+(?:\.\d+)?)\s*(?:kurang|minus|\-)\s*(\d+(?:\.\d+)?)/', $msg, $m)) {
            $result = (float)$m[1] - (float)$m[2];
            return " {$m[1]} - {$m[2]} = **{$result}**";
        }
        if (preg_match('/\b(?:berapa|hitung|calculate|what is)\b.*?(\d+(?:\.\d+)?)\s*(?:kali|times|x|×|\*)\s*(\d+(?:\.\d+)?)/', $msg, $m)) {
            $result = (float)$m[1] * (float)$m[2];
            return " {$m[1]} × {$m[2]} = **{$result}**";
        }
        if (preg_match('/\b(?:berapa|hitung|calculate|what is)\b.*?(\d+(?:\.\d+)?)\s*(?:bagi|divided|÷|\/)\s*(\d+(?:\.\d+)?)/', $msg, $m)) {
            if ((float)$m[2] == 0) {
                return $lang === 'en' ? " Division by zero is undefined!" : " Pembagian dengan nol tidak terdefinisi!";
            }
            $result = (float)$m[1] / (float)$m[2];
            return " {$m[1]} ÷ {$m[2]} = **" . round($result, 4) . "**";
        }

        return null;
    }

    private function safeCalculate(float $a, string $op, float $b): ?float
    {
        return match ($op) {
            '+' => $a + $b,
            '-' => $a - $b,
            '*', 'x', '×' => $a * $b,
            '/', '÷' => $b != 0 ? round($a / $b, 6) : null,
            '^' => pow($a, $b),
            default => null,
        };
    }

    private function operatorName(string $op, string $lang): string
    {
        return match ($op) {
            '+' => '+',
            '-' => '-',
            '*', 'x', '×' => '×',
            '/', '÷' => '÷',
            '^' => '^',
            default => $op,
        };
    }

    private function tryPercentage(string $msg, string $lang): ?string
    {
        if (preg_match('/(\d+(?:\.\d+)?)\s*%\s*(?:dari|of|from)\s*(\d+(?:\.\d+)?)/', $msg, $m)) {
            $percent = (float)$m[1];
            $base = (float)$m[2];
            $result = ($percent / 100) * $base;
            return $lang === 'en'
                ? " {$percent}% of {$base} = **{$result}**"
                : " {$percent}% dari {$base} = **{$result}**";
        }
        if (preg_match('/\b(?:berapa|what is|how much)\b.*?(\d+(?:\.\d+)?)\s*(?:persen|percent|%)\s*(?:dari|of)\s*(\d+(?:\.\d+)?)/', $msg, $m)) {
            $percent = (float)$m[1];
            $base = (float)$m[2];
            $result = ($percent / 100) * $base;
            return $lang === 'en'
                ? " {$percent}% of {$base} = **{$result}**"
                : " {$percent}% dari {$base} = **{$result}**";
        }
        return null;
    }

    private function tryUnitConversion(string $msg, string $lang): ?string
    {
        // Temperature
        if (preg_match('/(\d+(?:\.\d+)?)\s*(?:derajat\s*)?(?:celsius|°?c)\s*(?:ke|to|in|=)\s*(?:fahrenheit|°?f)/i', $msg, $m)) {
            $c = (float)$m[1];
            $f = round(($c * 9 / 5) + 32, 2);
            return " {$c}°C = **{$f}°F**";
        }
        if (preg_match('/(\d+(?:\.\d+)?)\s*(?:derajat\s*)?(?:fahrenheit|°?f)\s*(?:ke|to|in|=)\s*(?:celsius|°?c)/i', $msg, $m)) {
            $f = (float)$m[1];
            $c = round(($f - 32) * 5 / 9, 2);
            return " {$f}°F = **{$c}°C**";
        }
        if (preg_match('/(\d+(?:\.\d+)?)\s*(?:celsius|°?c)\s*(?:ke|to)\s*(?:kelvin|k)\b/i', $msg, $m)) {
            $c = (float)$m[1];
            $k = round($c + 273.15, 2);
            return " {$c}°C = **{$k}K**";
        }

        // Distance
        if (preg_match('/(\d+(?:\.\d+)?)\s*(?:km|kilometer)\s*(?:ke|to|in|=)\s*(?:mil|mile)/i', $msg, $m)) {
            $km = (float)$m[1];
            $mi = round($km * 0.621371, 4);
            return " {$km} km = **{$mi} miles**";
        }
        if (preg_match('/(\d+(?:\.\d+)?)\s*(?:mil|mile)s?\s*(?:ke|to|in|=)\s*(?:km|kilometer)/i', $msg, $m)) {
            $mi = (float)$m[1];
            $km = round($mi * 1.60934, 4);
            return " {$mi} miles = **{$km} km**";
        }
        if (preg_match('/(\d+(?:\.\d+)?)\s*(?:meter|m)\s*(?:ke|to|in|=)\s*(?:feet|ft|kaki)/i', $msg, $m)) {
            $meter = (float)$m[1];
            $ft = round($meter * 3.28084, 4);
            return " {$meter} m = **{$ft} ft**";
        }
        if (preg_match('/(\d+(?:\.\d+)?)\s*(?:cm|centimeter)\s*(?:ke|to|in|=)\s*(?:inch|inci)/i', $msg, $m)) {
            $cm = (float)$m[1];
            $inch = round($cm / 2.54, 4);
            return " {$cm} cm = **{$inch} inches**";
        }

        // Weight
        if (preg_match('/(\d+(?:\.\d+)?)\s*(?:kg|kilogram)\s*(?:ke|to|in|=)\s*(?:lbs?|pound)/i', $msg, $m)) {
            $kg = (float)$m[1];
            $lbs = round($kg * 2.20462, 4);
            return " {$kg} kg = **{$lbs} lbs**";
        }
        if (preg_match('/(\d+(?:\.\d+)?)\s*(?:lbs?|pound)\s*(?:ke|to|in|=)\s*(?:kg|kilogram)/i', $msg, $m)) {
            $lbs = (float)$m[1];
            $kg = round($lbs * 0.453592, 4);
            return " {$lbs} lbs = **{$kg} kg**";
        }
        if (preg_match('/(\d+(?:\.\d+)?)\s*(?:gram|g)\s*(?:ke|to|in|=)\s*(?:oz|ounce)/i', $msg, $m)) {
            $g = (float)$m[1];
            $oz = round($g * 0.035274, 4);
            return " {$g} g = **{$oz} oz**";
        }

        // Digital storage
        if (preg_match('/(\d+(?:\.\d+)?)\s*(?:gb|gigabyte)\s*(?:ke|to|in|=)\s*(?:mb|megabyte)/i', $msg, $m)) {
            $gb = (float)$m[1];
            $mb = $gb * 1024;
            return " {$gb} GB = **{$mb} MB**";
        }
        if (preg_match('/(\d+(?:\.\d+)?)\s*(?:tb|terabyte)\s*(?:ke|to|in|=)\s*(?:gb|gigabyte)/i', $msg, $m)) {
            $tb = (float)$m[1];
            $gb = $tb * 1024;
            return " {$tb} TB = **{$gb} GB**";
        }
        if (preg_match('/(\d+(?:\.\d+)?)\s*(?:mb|megabyte)\s*(?:ke|to|in|=)\s*(?:kb|kilobyte)/i', $msg, $m)) {
            $mb = (float)$m[1];
            $kb = $mb * 1024;
            return " {$mb} MB = **{$kb} KB**";
        }

        // Time
        if (preg_match('/(\d+(?:\.\d+)?)\s*(?:jam|hour)s?\s*(?:ke|to|in|=)\s*(?:menit|minute)/i', $msg, $m)) {
            $hours = (float)$m[1];
            $minutes = $hours * 60;
            return " {$hours} hours = **{$minutes} minutes**";
        }
        if (preg_match('/(\d+(?:\.\d+)?)\s*(?:menit|minute)s?\s*(?:ke|to|in|=)\s*(?:detik|second)/i', $msg, $m)) {
            $minutes = (float)$m[1];
            $seconds = $minutes * 60;
            return " {$minutes} minutes = **{$seconds} seconds**";
        }

        return null;
    }

    private function tryNumberConversion(string $msg, string $lang): ?string
    {
        if (preg_match('/(\d+)\s*(?:ke|to|in)\s*(?:biner|binary)/i', $msg, $m)) {
            $dec = (int)$m[1];
            $bin = decbin($dec);
            return " {$dec} (decimal) = **{$bin}** (binary)";
        }
        if (preg_match('/(\d+)\s*(?:ke|to|in)\s*(?:hex|heksadesimal|hexadecimal)/i', $msg, $m)) {
            $dec = (int)$m[1];
            $hex = strtoupper(dechex($dec));
            return " {$dec} (decimal) = **0x{$hex}** (hex)";
        }
        if (preg_match('/(\d+)\s*(?:ke|to|in)\s*(?:oktal|octal)/i', $msg, $m)) {
            $dec = (int)$m[1];
            $oct = decoct($dec);
            return " {$dec} (decimal) = **{$oct}** (octal)";
        }
        if (preg_match('/(?:biner|binary)\s*(\d+)\s*(?:ke|to|in)\s*(?:desimal|decimal)/i', $msg, $m)) {
            $bin = $m[1];
            $dec = bindec($bin);
            return " {$bin} (binary) = **{$dec}** (decimal)";
        }

        return null;
    }

    private function tryFormulaLookup(string $msg, string $lang): ?string
    {
        $formulas = [
            'pythagoras|pythagorean' => [
                'id' => " **Teorema Pythagoras**: a² + b² = c²\nDi mana c adalah sisi miring (hypotenuse) segitiga siku-siku.\nContoh: jika a=3, b=4, maka c = √(9+16) = √25 = 5",
                'en' => " **Pythagorean Theorem**: a² + b² = c²\nWhere c is the hypotenuse of a right triangle.\nExample: if a=3, b=4, then c = √(9+16) = √25 = 5",
            ],
            'kuadrat|quadratic' => [
                'id' => " **Rumus Kuadrat (ABC)**: x = (-b ± √(b²-4ac)) / 2a\nUntuk persamaan ax² + bx + c = 0\nDiskriminan D = b²-4ac: D>0 → 2 akar real, D=0 → 1 akar, D<0 → tidak ada akar real",
                'en' => " **Quadratic Formula**: x = (-b ± √(b²-4ac)) / 2a\nFor equation ax² + bx + c = 0\nDiscriminant D = b²-4ac: D>0 → 2 real roots, D=0 → 1 root, D<0 → no real roots",
            ],
            'luas lingkaran|area.*circle' => [
                'id' => " **Luas Lingkaran**: A = π × r²\n**Keliling Lingkaran**: C = 2 × π × r\nDi mana r = jari-jari, π ≈ 3.14159",
                'en' => " **Circle Area**: A = π × r²\n**Circle Circumference**: C = 2 × π × r\nWhere r = radius, π ≈ 3.14159",
            ],
            'luas segitiga|area.*triangle' => [
                'id' => " **Luas Segitiga**: A = ½ × alas × tinggi\n**Rumus Heron**: A = √(s(s-a)(s-b)(s-c)), di mana s = (a+b+c)/2",
                'en' => " **Triangle Area**: A = ½ × base × height\n**Heron's Formula**: A = √(s(s-a)(s-b)(s-c)), where s = (a+b+c)/2",
            ],
            'luas persegi|area.*square|area.*rectangle' => [
                'id' => " **Luas Persegi**: A = s²\n**Luas Persegi Panjang**: A = panjang × lebar\n**Keliling Persegi**: P = 4s\n**Keliling Persegi Panjang**: P = 2(p+l)",
                'en' => " **Square Area**: A = s²\n**Rectangle Area**: A = length × width\n**Square Perimeter**: P = 4s\n**Rectangle Perimeter**: P = 2(l+w)",
            ],
            'volume.*kubus|volume.*cube' => [
                'id' => " **Volume Kubus**: V = s³\n**Luas Permukaan Kubus**: SA = 6s²\nDi mana s = panjang sisi",
                'en' => " **Cube Volume**: V = s³\n**Cube Surface Area**: SA = 6s²\nWhere s = side length",
            ],
            'volume.*tabung|volume.*cylinder' => [
                'id' => " **Volume Tabung**: V = π × r² × t\n**Luas Permukaan Tabung**: SA = 2πr² + 2πrt\nDi mana r = jari-jari, t = tinggi",
                'en' => " **Cylinder Volume**: V = π × r² × h\n**Cylinder Surface Area**: SA = 2πr² + 2πrh\nWhere r = radius, h = height",
            ],
            'volume.*bola|volume.*sphere' => [
                'id' => " **Volume Bola**: V = 4/3 × π × r³\n**Luas Permukaan Bola**: SA = 4 × π × r²",
                'en' => " **Sphere Volume**: V = 4/3 × π × r³\n**Sphere Surface Area**: SA = 4 × π × r²",
            ],
            'bunga.*majemuk|compound.*interest' => [
                'id' => " **Bunga Majemuk**: A = P(1 + r/n)^(nt)\nP = modal awal, r = suku bunga, n = frekuensi per tahun, t = tahun",
                'en' => " **Compound Interest**: A = P(1 + r/n)^(nt)\nP = principal, r = interest rate, n = compounds per year, t = years",
            ],
            'kecepatan|speed|velocity' => [
                'id' => " **Kecepatan**: v = s / t (jarak / waktu)\n**Percepatan**: a = Δv / Δt\n**Jarak**: s = v × t\nSakak SI: m/s, m/s²",
                'en' => " **Speed**: v = s / t (distance / time)\n**Acceleration**: a = Δv / Δt\n**Distance**: s = v × t\nSI units: m/s, m/s²",
            ],
        ];

        foreach ($formulas as $pattern => $texts) {
            $keywords = explode('|', $pattern);
            foreach ($keywords as $kw) {
                if (preg_match('/' . preg_quote($kw, '/') . '/i', $msg) || str_contains($msg, $kw)) {
                    return $lang === 'en' ? $texts['en'] : $texts['id'];
                }
            }
        }

        return null;
    }

    private function tryConstantLookup(string $msg, string $lang): ?string
    {
        $constants = [
            'kecepatan cahaya|speed of light' => [
                'id' => " **Kecepatan Cahaya (c)**: 299,792,458 m/s ≈ 3 × 10⁸ m/s\nCahaya menempuh jarak Bumi-Bulan (~384,400 km) dalam ~1.28 detik.",
                'en' => " **Speed of Light (c)**: 299,792,458 m/s ≈ 3 × 10⁸ m/s\nLight travels Earth-Moon distance (~384,400 km) in ~1.28 seconds.",
            ],
            'gravitasi|gravitational constant' => [
                'id' => " **Konstanta Gravitasi (G)**: 6.674 × 10⁻¹¹ N⋅m²/kg²\n**Percepatan gravitasi Bumi (g)**: 9.81 m/s²",
                'en' => " **Gravitational Constant (G)**: 6.674 × 10⁻¹¹ N⋅m²/kg²\n**Earth's gravitational acceleration (g)**: 9.81 m/s²",
            ],
            'avogadro' => [
                'id' => " **Bilangan Avogadro (Nₐ)**: 6.022 × 10²³ /mol\nJumlah partikel dalam satu mol zat.",
                'en' => " **Avogadro's Number (Nₐ)**: 6.022 × 10²³ /mol\nNumber of particles in one mole of substance.",
            ],
            'planck' => [
                'id' => " **Konstanta Planck (h)**: 6.626 × 10⁻³⁴ J⋅s\nKonstanta fundamental dalam mekanika kuantum. E = hf (energi = konstanta × frekuensi)",
                'en' => " **Planck's Constant (h)**: 6.626 × 10⁻³⁴ J⋅s\nFundamental constant in quantum mechanics. E = hf (energy = constant × frequency)",
            ],
            'boltzmann' => [
                'id' => " **Konstanta Boltzmann (k)**: 1.381 × 10⁻²³ J/K\nMenghubungkan energi kinetik rata-rata partikel dengan suhu.",
                'en' => " **Boltzmann Constant (k)**: 1.381 × 10⁻²³ J/K\nRelates average kinetic energy of particles to temperature.",
            ],
            'euler|\\be\\b' => [
                'id' => " **Bilangan Euler (e)**: 2.71828...\nBasis logaritma natural. Muncul dalam pertumbuhan eksponensial, bunga majemuk, dan kalkulus.",
                'en' => " **Euler's Number (e)**: 2.71828...\nBase of natural logarithm. Appears in exponential growth, compound interest, and calculus.",
            ],
            'phi|golden ratio|rasio emas' => [
                'id' => " **Rasio Emas (φ)**: 1.6180339...\nDitemukan dalam seni, arsitektur, dan alam (spiral keong, kelopak bunga). φ = (1 + √5) / 2",
                'en' => " **Golden Ratio (φ)**: 1.6180339...\nFound in art, architecture, and nature (snail spirals, flower petals). φ = (1 + √5) / 2",
            ],
        ];

        foreach ($constants as $pattern => $texts) {
            $keywords = explode('|', $pattern);
            foreach ($keywords as $kw) {
                if (str_contains($msg, $kw)) {
                    return $lang === 'en' ? $texts['en'] : $texts['id'];
                }
            }
        }

        return null;
    }

    private function tryScienceExplanation(string $msg, string $lang): ?string
    {
        $topics = [
            'gravitasi|gravity' => [
                'id' => " **Gravitasi** adalah gaya tarik-menarik antara dua benda bermassa. Ditemukan oleh Isaac Newton (1687). Hukum Gravitasi: F = G × (m₁ × m₂) / r². Di Bumi, gravitasi membuat benda jatuh dengan percepatan 9.81 m/s². Gravitasi juga yang membuat planet mengorbit Matahari dan bulan mengorbit Bumi.",
                'en' => " **Gravity** is the attractive force between two masses. Discovered by Isaac Newton (1687). Law of Gravitation: F = G × (m₁ × m₂) / r². On Earth, gravity causes objects to fall at 9.81 m/s². Gravity also keeps planets orbiting the Sun and the Moon orbiting Earth.",
            ],
            'fotosintesis|photosynthesis' => [
                'id' => " **Fotosintesis** adalah proses tumbuhan mengubah CO₂ + H₂O → C₆H₁₂O₆ + O₂ menggunakan cahaya matahari. Terjadi di kloroplas, menggunakan klorofil. Ini adalah sumber utama oksigen di atmosfer dan dasar rantai makanan.",
                'en' => " **Photosynthesis** is how plants convert CO₂ + H₂O → C₆H₁₂O₆ + O₂ using sunlight. Occurs in chloroplasts using chlorophyll. It's the primary source of atmospheric oxygen and the base of the food chain.",
            ],
            'dna|gen|genetik|genetic' => [
                'id' => " **DNA** (Deoxyribonucleic Acid) adalah molekul yang membawa informasi genetik semua makhluk hidup. Struktur double helix ditemukan oleh Watson & Crick (1953). DNA tersusun dari 4 basa: Adenin (A), Timin (T), Guanin (G), Sitosin (C). A berpasangan dengan T, G dengan C.",
                'en' => " **DNA** (Deoxyribonucleic Acid) carries genetic information in all living things. Double helix structure discovered by Watson & Crick (1953). DNA consists of 4 bases: Adenine (A), Thymine (T), Guanine (G), Cytosine (C). A pairs with T, G pairs with C.",
            ],
            'atom' => [
                'id' => " **Atom** adalah unit terkecil dari materi. Terdiri dari: Proton (+) dan Neutron (0) di inti, serta Elektron (-) mengorbit. Nomor atom = jumlah proton. Nomor massa = proton + neutron. Model atom modern menggunakan orbital probabilistik (mekanika kuantum).",
                'en' => " **Atom** is the smallest unit of matter. Consists of: Protons (+) and Neutrons (0) in the nucleus, and Electrons (-) orbiting. Atomic number = proton count. Mass number = protons + neutrons. Modern atomic model uses probabilistic orbitals (quantum mechanics).",
            ],
            'evolusi|evolution' => [
                'id' => " **Evolusi** adalah perubahan genetik populasi makhluk hidup dari generasi ke generasi. Teori Charles Darwin (1859): seleksi alam memilih individu yang paling adaptif. Bukti: fosil, DNA komparatif, organ vestigial, embriologi komparatif.",
                'en' => " **Evolution** is the genetic change in populations over generations. Charles Darwin's theory (1859): natural selection favors the most adaptive individuals. Evidence: fossils, comparative DNA, vestigial organs, comparative embryology.",
            ],
            'tabel periodik|periodic table' => [
                'id' => " **Tabel Periodik** disusun oleh Dmitri Mendeleev (1869). Mengelompokkan 118 unsur berdasarkan nomor atom. Baris = Periode (tingkat energi), Kolom = Golongan (sifat serupa). Golongan 1: logam alkali, Golongan 18: gas mulia. Sifat periodik: jari-jari atom, elektronegativitas, energi ionisasi.",
                'en' => " **Periodic Table** organized by Dmitri Mendeleev (1869). Groups 118 elements by atomic number. Rows = Periods (energy levels), Columns = Groups (similar properties). Group 1: alkali metals, Group 18: noble gases. Periodic trends: atomic radius, electronegativity, ionization energy.",
            ],
            'listrik|electricity|arus|current' => [
                'id' => " **Listrik** adalah aliran elektron. Hukum Ohm: V = I × R (tegangan = arus × hambatan). Sakak: Volt (V), Ampere (A), Ohm (Ω). Daya: P = V × I (Watt). AC = arus bolak-balik (PLN), DC = arus searah (baterai).",
                'en' => " **Electricity** is the flow of electrons. Ohm's Law: V = I × R (voltage = current × resistance). Units: Volt (V), Ampere (A), Ohm (Ω). Power: P = V × I (Watts). AC = alternating current (grid), DC = direct current (battery).",
            ],
            'termodinamika|thermodynamics' => [
                'id' => " **Termodinamika**: Hukum 0: Keseimbangan termal. Hukum 1: Energi kekal (tidak diciptakan/dimusnahkan). Hukum 2: Entropi selalu meningkat. Hukum 3: Entropi mendekati nol pada suhu nol mutlak (0 K = -273.15°C).",
                'en' => " **Thermodynamics**: 0th Law: Thermal equilibrium. 1st Law: Energy is conserved (not created/destroyed). 2nd Law: Entropy always increases. 3rd Law: Entropy approaches zero at absolute zero (0 K = -273.15°C).",
            ],
            'sel|cell|mitosis|meiosis' => [
                'id' => " **Sel** adalah unit dasar kehidupan. Sel prokariotik (bakteri): tanpa inti. Sel eukariotik (manusia): punya inti. Organel: mitokondria (energi), ribosom (protein), RE, golgi. Mitosis = pembelahan sel tubuh (2n→2n). Meiosis = pembelahan sel kelamin (2n→n).",
                'en' => " **Cell** is the basic unit of life. Prokaryotic (bacteria): no nucleus. Eukaryotic (human): has nucleus. Organelles: mitochondria (energy), ribosomes (protein), ER, Golgi. Mitosis = body cell division (2n→2n). Meiosis = sex cell division (2n→n).",
            ],
            'tektonik|tectonic|gempa|earthquake' => [
                'id' => " **Tektonik Lempeng**: Kerak bumi terdiri dari lempeng-lempeng yang bergerak di atas mantel. Pergerakan lempeng menyebabkan gempa bumi, gunung berapi, dan pembentukan pegunungan. Skala Richter mengukur kekuatan gempa. Indonesia berada di Ring of Fire (Cincin Api Pasifik).",
                'en' => " **Plate Tectonics**: Earth's crust consists of plates moving over the mantle. Plate movement causes earthquakes, volcanoes, and mountain formation. Richter scale measures earthquake magnitude. Indonesia sits on the Ring of Fire (Pacific Ring of Fire).",
            ],
            'reaksi kimia|chemical reaction' => [
                'id' => " **Reaksi Kimia**: Proses perubahan zat menjadi zat baru. Jenis: Sintesis (A+B→AB), Dekomposisi (AB→A+B), Substitusi tunggal (A+BC→AC+B), Substitusi ganda (AB+CD→AD+CB). Hukum Lavoisier: massa sebelum = massa sesudah reaksi.",
                'en' => " **Chemical Reactions**: Process of substances changing into new ones. Types: Synthesis (A+B→AB), Decomposition (AB→A+B), Single replacement (A+BC→AC+B), Double replacement (AB+CD→AD+CB). Law of Conservation: mass before = mass after reaction.",
            ],
            'gelombang|wave|frekuensi|frequency' => [
                'id' => " **Gelombang**: Transfer energi tanpa transfer materi. v = f × λ (kecepatan = frekuensi × panjang gelombang). Jenis: transversal (cahaya) dan longitudinal (suara). Spektrum elektromagnetik: radio → mikro → infrared → cahaya tampak → UV → X-ray → gamma.",
                'en' => " **Waves**: Energy transfer without matter transfer. v = f × λ (velocity = frequency × wavelength). Types: transverse (light) and longitudinal (sound). EM spectrum: radio → micro → infrared → visible → UV → X-ray → gamma.",
            ],
        ];

        foreach ($topics as $pattern => $texts) {
            $keywords = explode('|', $pattern);
            foreach ($keywords as $kw) {
                if (str_contains($msg, $kw)) {
                    return $lang === 'en' ? $texts['en'] : $texts['id'];
                }
            }
        }

        return null;
    }

    private function tryMathConcept(string $msg, string $lang): ?string
    {
        $concepts = [
            'aljabar|algebra' => [
                'id' => " **Aljabar** adalah cabang matematika yang menggunakan simbol untuk merepresentasikan bilangan. Konsep dasar: variabel, koefisien, persamaan linear (ax+b=0), persamaan kuadrat (ax²+bx+c=0), sistem persamaan, fungsi, dan grafik.",
                'en' => " **Algebra** is a branch of math using symbols to represent numbers. Core concepts: variables, coefficients, linear equations (ax+b=0), quadratic equations (ax²+bx+c=0), systems of equations, functions, and graphs.",
            ],
            'geometri|geometry' => [
                'id' => " **Geometri** mempelajari bentuk, ukuran, dan sifat ruang. Geometri bidang: titik, garis, sudut, segitiga, lingkaran. Geometri ruang: kubus, balok, tabung, kerucut, bola. Teorema penting: Pythagoras, Thales, kongruensi, kesebangunan.",
                'en' => " **Geometry** studies shapes, sizes, and properties of space. Plane geometry: points, lines, angles, triangles, circles. Solid geometry: cubes, prisms, cylinders, cones, spheres. Key theorems: Pythagoras, Thales, congruence, similarity.",
            ],
            'kalkulus|calculus' => [
                'id' => " **Kalkulus** ditemukan oleh Newton & Leibniz. Dua cabang utama: Diferensial (turunan  laju perubahan) dan Integral (anti-turunan  luas di bawah kurva). Turunan: f'(x) = lim[h→0] (f(x+h)-f(x))/h. Integral: ∫f(x)dx. Teorema Fundamental: turunan dan integral saling kebalikan.",
                'en' => " **Calculus** invented by Newton & Leibniz. Two main branches: Differential (derivatives  rates of change) and Integral (antiderivatives  area under curves). Derivative: f'(x) = lim[h→0] (f(x+h)-f(x))/h. Integral: ∫f(x)dx. Fundamental Theorem: derivatives and integrals are inverses.",
            ],
            'statistik|statistics' => [
                'id' => " **Statistik**: Mean (rata-rata) = Σx/n. Median = nilai tengah. Modus = nilai terbanyak. Standar deviasi: ukuran sebaran data. Distribusi normal (bell curve): 68% data dalam 1σ, 95% dalam 2σ, 99.7% dalam 3σ.",
                'en' => " **Statistics**: Mean (average) = Σx/n. Median = middle value. Mode = most frequent. Standard deviation: measure of data spread. Normal distribution (bell curve): 68% within 1σ, 95% within 2σ, 99.7% within 3σ.",
            ],
            'probabilitas|probability' => [
                'id' => " **Probabilitas**: P(A) = kejadian diinginkan / total kejadian. P(A∪B) = P(A)+P(B)-P(A∩B). P(A∩B) = P(A)×P(B) jika independen. Permutasi: P(n,r) = n!/(n-r)!. Kombinasi: C(n,r) = n!/(r!(n-r)!).",
                'en' => " **Probability**: P(A) = favorable outcomes / total outcomes. P(A∪B) = P(A)+P(B)-P(A∩B). P(A∩B) = P(A)×P(B) if independent. Permutation: P(n,r) = n!/(n-r)!. Combination: C(n,r) = n!/(r!(n-r)!).",
            ],
            'trigonometri|trigonometry' => [
                'id' => " **Trigonometri**: sin(θ) = sisi depan / sisi miring. cos(θ) = sisi samping / sisi miring. tan(θ) = sin/cos. Identitas: sin²+cos²=1. Sudut istimewa: sin(30°)=½, cos(60°)=½, sin(45°)=√2/2.",
                'en' => " **Trigonometry**: sin(θ) = opposite / hypotenuse. cos(θ) = adjacent / hypotenuse. tan(θ) = sin/cos. Identity: sin²+cos²=1. Special angles: sin(30°)=½, cos(60°)=½, sin(45°)=√2/2.",
            ],
            'logaritma|logarithm' => [
                'id' => " **Logaritma**: log_b(x) = y berarti b^y = x. Sifat: log(ab) = log(a)+log(b). log(a/b) = log(a)-log(b). log(a^n) = n×log(a). ln = log natural (basis e). log = log basis 10.",
                'en' => " **Logarithm**: log_b(x) = y means b^y = x. Properties: log(ab) = log(a)+log(b). log(a/b) = log(a)-log(b). log(a^n) = n×log(a). ln = natural log (base e). log = common log (base 10).",
            ],
            'matriks|matrix' => [
                'id' => " **Matriks**: Array bilangan dalam baris dan kolom. Operasi: penjumlahan, perkalian skalar, perkalian matriks (baris × kolom). Determinan 2×2: ad-bc. Matriks identitas: diagonal = 1, sisanya = 0. Invers: A⁻¹ × A = I.",
                'en' => " **Matrix**: Array of numbers in rows and columns. Operations: addition, scalar multiplication, matrix multiplication (row × column). 2×2 determinant: ad-bc. Identity matrix: diagonal = 1, rest = 0. Inverse: A⁻¹ × A = I.",
            ],
        ];

        foreach ($concepts as $pattern => $texts) {
            $keywords = explode('|', $pattern);
            foreach ($keywords as $kw) {
                if (str_contains($msg, $kw)) {
                    return $lang === 'en' ? $texts['en'] : $texts['id'];
                }
            }
        }

        return null;
    }
}
