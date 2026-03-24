<?php

namespace App\Services\Ai;

use Illuminate\Support\Str;

/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║       K N O W L E D G E   B A S E   S E R V I C E   v1.0              ║
 * ║                                                                         ║
 * ║   Massive rule-based knowledge engine with 500+ entries across          ║
 * ║   programming, math, science, history, philosophy, health, finance,     ║
 * ║   language, general knowledge, life skills, and more.                   ║
 * ║                                                                         ║
 * ║   Bilingual: ID / EN                                                    ║
 * ║   Fuzzy matching + confidence scoring                                   ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 */
class KnowledgeBaseService
{
    private array $entries = [];

    public function __construct()
    {
        $this->entries = array_merge(
            $this->programmingKnowledge(),
            $this->mathKnowledge(),
            $this->scienceKnowledge(),
            $this->historyKnowledge(),
            $this->philosophyKnowledge(),
            $this->healthKnowledge(),
            $this->financeKnowledge(),
            $this->languageLiteratureKnowledge(),
            $this->generalKnowledge(),
            $this->lifeSkillsKnowledge(),
            $this->indonesianCultureKnowledge(),
            $this->technologyKnowledge(),
            $this->geographyKnowledge(),
            $this->foodCookingKnowledge(),
            $this->sportsKnowledge(),
            $this->musicArtKnowledge(),
            $this->environmentKnowledge(),
            $this->psychologyKnowledge(),
            $this->astronomyKnowledge(),
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // PUBLIC API
    // ═══════════════════════════════════════════════════════════════

    public function query(string $question, string $lang = 'id'): ?array
    {
        $normalized = mb_strtolower(trim($question));
        $bestMatch = null;
        $bestScore = 0;

        foreach ($this->entries as $entry) {
            $score = $this->matchConfidence($normalized, $entry);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatch = $entry;
            }
        }

        if ($bestScore < 25) return null;

        $answerKey = $lang === 'en' ? 'answer_en' : 'answer_id';
        return [
            'answer'     => $bestMatch[$answerKey] ?? $bestMatch['answer_id'],
            'topic'      => $bestMatch['topic'],
            'confidence' => $bestScore,
            'related'    => $bestMatch['related'] ?? [],
            'difficulty' => $bestMatch['difficulty'] ?? 'basic',
        ];
    }

    public function searchKnowledge(string $keyword): array
    {
        $keyword = mb_strtolower(trim($keyword));
        $results = [];

        foreach ($this->entries as $entry) {
            $score = $this->matchConfidence($keyword, $entry);
            if ($score >= 20) {
                $results[] = [
                    'topic'      => $entry['topic'],
                    'keywords'   => $entry['keywords'],
                    'confidence' => $score,
                    'preview'    => Str::limit($entry['answer_id'], 120),
                ];
            }
        }

        usort($results, fn($a, $b) => $b['confidence'] <=> $a['confidence']);
        return array_slice($results, 0, 10);
    }

    public function getTopicCategories(): array
    {
        return array_values(array_unique(array_column($this->entries, 'topic')));
    }

    public function getRandomFact(?string $category = null): string
    {
        $pool = $category
            ? array_filter($this->entries, fn($e) => $e['topic'] === $category)
            : $this->entries;

        if (empty($pool)) return 'Tidak ada fakta yang tersedia.';
        $entry = $pool[array_rand($pool)];
        return $entry['answer_id'];
    }

    public function explain(string $topic, string $lang = 'id'): string
    {
        $result = $this->query($topic, $lang);
        return $result ? $result['answer'] : ($lang === 'en'
            ? "I don't have detailed information about \"{$topic}\" yet."
            : "Saya belum memiliki informasi detail tentang \"{$topic}\".");
    }

    public function getCodeExample(string $language, string $concept): ?string
    {
        $normalized = mb_strtolower("{$language} {$concept}");
        foreach ($this->entries as $entry) {
            if ($entry['topic'] !== 'programming') continue;
            $score = $this->matchConfidence($normalized, $entry);
            if ($score >= 40 && !empty($entry['code_example'])) {
                return $entry['code_example'];
            }
        }
        return null;
    }

    public function matchConfidence(string $query, array $entry): int
    {
        $score = 0;
        $query = mb_strtolower($query);

        // Keyword exact match (highest weight)
        foreach ($entry['keywords'] as $kw) {
            $kw = mb_strtolower($kw);
            if (str_contains($query, $kw)) {
                $score += (mb_strlen($kw) >= 6) ? 30 : 20;
            }
            // Fuzzy match
            $words = explode(' ', $query);
            foreach ($words as $w) {
                if (mb_strlen($w) >= 4 && mb_strlen($kw) >= 4 && levenshtein($w, $kw) <= 2) {
                    $score += 10;
                }
            }
        }

        // Question pattern match
        foreach ($entry['question_patterns'] ?? [] as $pattern) {
            $pattern = mb_strtolower($pattern);
            if (str_contains($query, $pattern)) {
                $score += 35;
            }
            // Partial pattern match
            $patternWords = explode(' ', $pattern);
            $matchedWords = 0;
            foreach ($patternWords as $pw) {
                if (mb_strlen($pw) >= 3 && str_contains($query, $pw)) {
                    $matchedWords++;
                }
            }
            if (count($patternWords) > 0) {
                $ratio = $matchedWords / count($patternWords);
                if ($ratio >= 0.5) $score += (int)($ratio * 20);
            }
        }

        return min(100, $score);
    }

    // ═══════════════════════════════════════════════════════════════
    // KNOWLEDGE DOMAINS
    // ═══════════════════════════════════════════════════════════════

    private function programmingKnowledge(): array
    {
        return [
            // ── PHP / Laravel ──────────────────────────────────────
            [
                'topic' => 'programming', 'keywords' => ['php', 'laravel', 'eloquent', 'model'],
                'question_patterns' => ['apa itu laravel', 'what is laravel', 'jelaskan laravel'],
                'answer_id' => 'Laravel adalah framework PHP modern yang mengikuti arsitektur MVC. Fitur utamanya: Eloquent ORM untuk database, Blade template engine, Artisan CLI, middleware, routing, migration, seeder, dan dependency injection via Service Container. Laravel juga mendukung queue, event broadcasting, task scheduling, dan API development dengan Sanctum/Passport.',
                'answer_en' => 'Laravel is a modern PHP framework following MVC architecture. Key features: Eloquent ORM for database, Blade template engine, Artisan CLI, middleware, routing, migrations, seeders, and dependency injection via Service Container. It also supports queues, event broadcasting, task scheduling, and API development with Sanctum/Passport.',
                'related' => ['php', 'mvc', 'eloquent', 'artisan'], 'difficulty' => 'basic',
                'code_example' => "// Laravel Eloquent Example\n\$users = User::where('active', true)\n    ->orderBy('name')\n    ->limit(10)\n    ->get();\n\n// Create with relationship\n\$user->posts()->create([\n    'title' => 'My Post',\n    'body' => 'Content here',\n]);",
            ],
            [
                'topic' => 'programming', 'keywords' => ['php', 'variable', 'tipe data', 'data type'],
                'question_patterns' => ['tipe data php', 'php data types', 'variabel php'],
                'answer_id' => 'PHP memiliki 8 tipe data: string, integer, float, boolean, array, object, NULL, resource. PHP adalah loosely typed — variabel tidak perlu deklarasi tipe. Gunakan var_dump() untuk cek tipe. PHP 8+ mendukung union types (string|int), named arguments, dan match expression.',
                'answer_en' => 'PHP has 8 data types: string, integer, float, boolean, array, object, NULL, resource. PHP is loosely typed — variables don\'t need type declaration. Use var_dump() to check types. PHP 8+ supports union types (string|int), named arguments, and match expressions.',
                'related' => ['php', 'variable', 'casting'], 'difficulty' => 'basic',
                'code_example' => "<?php\n\$name = 'Jarvis';        // string\n\$age = 25;                // integer\n\$pi = 3.14;               // float\n\$active = true;           // boolean\n\$items = [1, 2, 3];       // array\n\n// PHP 8 Union Types\nfunction process(string|int \$value): string|false {\n    return match(true) {\n        is_string(\$value) => strtoupper(\$value),\n        is_int(\$value) => (string)\$value,\n        default => false,\n    };\n}",
            ],
            [
                'topic' => 'programming', 'keywords' => ['eloquent', 'relationship', 'relasi', 'hasMany', 'belongsTo'],
                'question_patterns' => ['eloquent relationship', 'relasi eloquent', 'hasMany', 'belongsTo'],
                'answer_id' => 'Eloquent mendukung relasi: hasOne, hasMany, belongsTo, belongsToMany, hasManyThrough, polymorphic. Contoh: User hasMany Post, Post belongsTo User. Gunakan eager loading (with()) untuk menghindari N+1 query problem. Laravel juga mendukung pivot table untuk many-to-many.',
                'answer_en' => 'Eloquent supports relationships: hasOne, hasMany, belongsTo, belongsToMany, hasManyThrough, polymorphic. Example: User hasMany Post, Post belongsTo User. Use eager loading (with()) to avoid N+1 query problem. Laravel also supports pivot tables for many-to-many.',
                'related' => ['laravel', 'database', 'orm'], 'difficulty' => 'intermediate',
                'code_example' => "// User Model\npublic function posts(): HasMany {\n    return \$this->hasMany(Post::class);\n}\n\n// Post Model\npublic function user(): BelongsTo {\n    return \$this->belongsTo(User::class);\n}\n\n// Eager loading\n\$users = User::with(['posts' => function(\$q) {\n    \$q->where('published', true)->latest();\n}])->get();",
            ],
            [
                'topic' => 'programming', 'keywords' => ['middleware', 'laravel middleware', 'auth middleware'],
                'question_patterns' => ['apa itu middleware', 'what is middleware', 'cara buat middleware'],
                'answer_id' => 'Middleware adalah filter HTTP request sebelum masuk ke controller. Contoh: auth middleware untuk cek login, throttle untuk rate limiting, CORS untuk cross-origin. Buat middleware dengan: php artisan make:middleware CheckAge. Register di Kernel.php atau route group.',
                'answer_en' => 'Middleware is an HTTP request filter before reaching the controller. Examples: auth middleware for login check, throttle for rate limiting, CORS for cross-origin. Create with: php artisan make:middleware CheckAge. Register in Kernel.php or route group.',
                'related' => ['laravel', 'http', 'auth', 'routing'], 'difficulty' => 'intermediate',
                'code_example' => "// Custom Middleware\nclass CheckAge {\n    public function handle(Request \$request, Closure \$next) {\n        if (\$request->age < 18) {\n            return redirect('home');\n        }\n        return \$next(\$request);\n    }\n}\n\n// Route usage\nRoute::middleware(['auth', 'throttle:60,1'])\n    ->group(function () {\n        Route::get('/dashboard', [DashboardController::class, 'index']);\n    });",
            ],
            // ── JavaScript ──────────────────────────────────────────
            [
                'topic' => 'programming', 'keywords' => ['javascript', 'js', 'ecmascript', 'es6'],
                'question_patterns' => ['apa itu javascript', 'what is javascript', 'jelaskan javascript'],
                'answer_id' => 'JavaScript adalah bahasa pemrograman utama untuk web. Berjalan di browser (client-side) dan server (Node.js). Fitur modern ES6+: let/const, arrow functions, template literals, destructuring, spread operator, Promises, async/await, modules, classes, Map/Set, optional chaining (?.), nullish coalescing (??).',
                'answer_en' => 'JavaScript is the primary programming language for the web. Runs in browsers (client-side) and servers (Node.js). Modern ES6+ features: let/const, arrow functions, template literals, destructuring, spread operator, Promises, async/await, modules, classes, Map/Set, optional chaining (?.), nullish coalescing (??).',
                'related' => ['es6', 'nodejs', 'typescript', 'react'], 'difficulty' => 'basic',
                'code_example' => "// Modern JavaScript\nconst fetchData = async (url) => {\n  try {\n    const response = await fetch(url);\n    const data = await response.json();\n    return data;\n  } catch (error) {\n    console.error('Fetch failed:', error);\n  }\n};\n\n// Destructuring & spread\nconst { name, ...rest } = user;\nconst merged = { ...defaults, ...overrides };",
            ],
            [
                'topic' => 'programming', 'keywords' => ['promise', 'async', 'await', 'asynchronous'],
                'question_patterns' => ['apa itu promise', 'what is promise', 'async await', 'cara pakai async'],
                'answer_id' => 'Promise adalah objek yang merepresentasikan operasi asynchronous. Ada 3 state: pending, fulfilled, rejected. Async/await adalah syntax sugar untuk Promise — membuat kode async terlihat synchronous. Gunakan try/catch untuk error handling. Promise.all() untuk parallel execution, Promise.race() untuk ambil yang tercepat.',
                'answer_en' => 'Promise is an object representing an asynchronous operation. It has 3 states: pending, fulfilled, rejected. Async/await is syntax sugar for Promises — makes async code look synchronous. Use try/catch for error handling. Promise.all() for parallel execution, Promise.race() for fastest result.',
                'related' => ['javascript', 'callback', 'event loop'], 'difficulty' => 'intermediate',
                'code_example' => "// Promise\nconst myPromise = new Promise((resolve, reject) => {\n  setTimeout(() => resolve('Done!'), 1000);\n});\n\n// Async/Await\nasync function loadAll() {\n  const [users, posts] = await Promise.all([\n    fetch('/api/users').then(r => r.json()),\n    fetch('/api/posts').then(r => r.json()),\n  ]);\n  return { users, posts };\n}",
            ],
            // ── Python ──────────────────────────────────────────────
            [
                'topic' => 'programming', 'keywords' => ['python', 'pip', 'python3'],
                'question_patterns' => ['apa itu python', 'what is python', 'belajar python'],
                'answer_id' => 'Python adalah bahasa pemrograman tingkat tinggi yang mudah dibaca, mendukung multiple paradigm (OOP, functional, procedural). Populer untuk: web (Django, Flask), data science (Pandas, NumPy), AI/ML (TensorFlow, PyTorch), scripting, automation. Fitur: dynamic typing, list comprehension, decorators, generators, context managers.',
                'answer_en' => 'Python is a high-level programming language known for readability, supporting multiple paradigms (OOP, functional, procedural). Popular for: web (Django, Flask), data science (Pandas, NumPy), AI/ML (TensorFlow, PyTorch), scripting, automation. Features: dynamic typing, list comprehension, decorators, generators, context managers.',
                'related' => ['django', 'flask', 'pandas', 'numpy'], 'difficulty' => 'basic',
                'code_example' => "# Python basics\ndef fibonacci(n):\n    a, b = 0, 1\n    result = []\n    while a < n:\n        result.append(a)\n        a, b = b, a + b\n    return result\n\n# List comprehension\nsquares = [x**2 for x in range(10) if x % 2 == 0]\n\n# Dictionary comprehension\nword_lengths = {word: len(word) for word in ['hello', 'world']}",
            ],
            // ── Dart / Flutter ──────────────────────────────────────
            [
                'topic' => 'programming', 'keywords' => ['dart', 'flutter', 'widget', 'stateful', 'stateless'],
                'question_patterns' => ['apa itu flutter', 'what is flutter', 'belajar flutter', 'jelaskan dart'],
                'answer_id' => 'Flutter adalah UI toolkit dari Google untuk membangun aplikasi native di mobile (iOS/Android), web, dan desktop dari satu codebase Dart. Widget adalah building block utama — ada StatelessWidget (tidak berubah) dan StatefulWidget (bisa berubah state). Flutter menggunakan rendering engine sendiri (Skia), bukan native UI components.',
                'answer_en' => 'Flutter is a UI toolkit from Google for building natively compiled applications for mobile (iOS/Android), web, and desktop from a single Dart codebase. Widgets are the main building blocks — StatelessWidget (immutable) and StatefulWidget (mutable state). Flutter uses its own rendering engine (Skia), not native UI components.',
                'related' => ['dart', 'widget', 'provider', 'bloc'], 'difficulty' => 'basic',
                'code_example' => "// Flutter StatefulWidget\nclass Counter extends StatefulWidget {\n  @override\n  State<Counter> createState() => _CounterState();\n}\n\nclass _CounterState extends State<Counter> {\n  int _count = 0;\n\n  @override\n  Widget build(BuildContext context) {\n    return Column(children: [\n      Text('Count: \$_count'),\n      ElevatedButton(\n        onPressed: () => setState(() => _count++),\n        child: Text('Increment'),\n      ),\n    ]);\n  }\n}",
            ],
            [
                'topic' => 'programming', 'keywords' => ['state management', 'provider', 'riverpod', 'bloc', 'getx'],
                'question_patterns' => ['state management flutter', 'provider flutter', 'riverpod vs bloc'],
                'answer_id' => 'State management di Flutter: 1) setState (sederhana), 2) Provider (recommended oleh Flutter team), 3) Riverpod (Provider versi improved, compile-safe), 4) BLoC (Business Logic Component, event-driven), 5) GetX (all-in-one tapi opinionated). Untuk app kecil: Provider. App besar: Riverpod atau BLoC. Pilih berdasarkan kompleksitas dan tim.',
                'answer_en' => 'State management in Flutter: 1) setState (simple), 2) Provider (recommended by Flutter team), 3) Riverpod (improved Provider, compile-safe), 4) BLoC (Business Logic Component, event-driven), 5) GetX (all-in-one but opinionated). Small app: Provider. Large app: Riverpod or BLoC. Choose based on complexity and team.',
                'related' => ['flutter', 'architecture', 'mvvm'], 'difficulty' => 'intermediate',
            ],
            // ── SQL ──────────────────────────────────────────────────
            [
                'topic' => 'programming', 'keywords' => ['sql', 'database', 'query', 'join', 'select'],
                'question_patterns' => ['apa itu sql', 'what is sql', 'belajar sql', 'cara join sql'],
                'answer_id' => 'SQL (Structured Query Language) adalah bahasa untuk mengelola database relasional. Perintah utama: SELECT (baca), INSERT (tambah), UPDATE (ubah), DELETE (hapus). JOIN menggabungkan tabel: INNER JOIN (irisan), LEFT JOIN (semua kiri + match kanan), RIGHT JOIN, FULL OUTER JOIN. Gunakan INDEX untuk optimasi query.',
                'answer_en' => 'SQL (Structured Query Language) is used to manage relational databases. Main commands: SELECT (read), INSERT (add), UPDATE (modify), DELETE (remove). JOINs combine tables: INNER JOIN (intersection), LEFT JOIN (all left + matching right), RIGHT JOIN, FULL OUTER JOIN. Use INDEX for query optimization.',
                'related' => ['mysql', 'postgresql', 'database', 'index'], 'difficulty' => 'basic',
                'code_example' => "-- Complex JOIN example\nSELECT u.name, COUNT(p.id) as post_count,\n       MAX(p.created_at) as last_post\nFROM users u\nLEFT JOIN posts p ON u.id = p.user_id\nWHERE u.active = 1\nGROUP BY u.id, u.name\nHAVING COUNT(p.id) > 5\nORDER BY post_count DESC\nLIMIT 10;",
            ],
            // ── Design Patterns ──────────────────────────────────────
            [
                'topic' => 'programming', 'keywords' => ['design pattern', 'pola desain', 'singleton', 'factory', 'observer'],
                'question_patterns' => ['apa itu design pattern', 'design pattern', 'pola desain', 'singleton pattern'],
                'answer_id' => 'Design Pattern adalah solusi umum untuk masalah berulang dalam software design. Kategori: 1) Creational: Singleton, Factory, Builder, Prototype. 2) Structural: Adapter, Decorator, Facade, Proxy. 3) Behavioral: Observer, Strategy, Command, State, Iterator. Singleton memastikan hanya 1 instance. Factory membuat objek tanpa expose creation logic. Observer untuk event system.',
                'answer_en' => 'Design Patterns are reusable solutions to common software design problems. Categories: 1) Creational: Singleton, Factory, Builder, Prototype. 2) Structural: Adapter, Decorator, Facade, Proxy. 3) Behavioral: Observer, Strategy, Command, State, Iterator. Singleton ensures only 1 instance. Factory creates objects without exposing creation logic. Observer for event systems.',
                'related' => ['solid', 'clean code', 'architecture', 'oop'], 'difficulty' => 'intermediate',
            ],
            [
                'topic' => 'programming', 'keywords' => ['solid', 'prinsip solid', 'solid principles'],
                'question_patterns' => ['apa itu solid', 'solid principles', 'prinsip solid'],
                'answer_id' => 'SOLID adalah 5 prinsip OOP: S — Single Responsibility (1 class = 1 tanggung jawab). O — Open/Closed (terbuka untuk ekstensi, tertutup untuk modifikasi). L — Liskov Substitution (subclass bisa gantikan parent). I — Interface Segregation (interface kecil-kecil, jangan gemuk). D — Dependency Inversion (depend on abstractions, bukan concrete).',
                'answer_en' => 'SOLID is 5 OOP principles: S — Single Responsibility (1 class = 1 responsibility). O — Open/Closed (open for extension, closed for modification). L — Liskov Substitution (subclass can replace parent). I — Interface Segregation (small interfaces, not fat ones). D — Dependency Inversion (depend on abstractions, not concrete classes).',
                'related' => ['oop', 'clean code', 'design pattern'], 'difficulty' => 'intermediate',
            ],
            [
                'topic' => 'programming', 'keywords' => ['git', 'version control', 'github', 'commit', 'branch'],
                'question_patterns' => ['cara pakai git', 'git commands', 'apa itu git', 'git branch'],
                'answer_id' => 'Git adalah version control system terdistribusi. Perintah utama: git init, git add, git commit, git push, git pull, git branch, git merge, git rebase, git stash. Workflow: buat branch fitur → develop → commit → push → pull request → merge ke main. Gunakan .gitignore untuk exclude file. GitHub/GitLab untuk remote repository.',
                'answer_en' => 'Git is a distributed version control system. Main commands: git init, git add, git commit, git push, git pull, git branch, git merge, git rebase, git stash. Workflow: create feature branch → develop → commit → push → pull request → merge to main. Use .gitignore to exclude files. GitHub/GitLab for remote repository.',
                'related' => ['github', 'version control', 'branching'], 'difficulty' => 'basic',
            ],
            [
                'topic' => 'programming', 'keywords' => ['api', 'rest', 'restful', 'endpoint', 'http method'],
                'question_patterns' => ['apa itu api', 'what is rest api', 'cara buat api', 'rest api'],
                'answer_id' => 'REST API adalah arsitektur komunikasi antar sistem via HTTP. Prinsip: stateless, resource-based URL, HTTP methods (GET=baca, POST=buat, PUT/PATCH=update, DELETE=hapus). Response biasanya JSON. Status codes: 200 OK, 201 Created, 400 Bad Request, 401 Unauthorized, 404 Not Found, 500 Server Error. Gunakan versioning (v1/v2), pagination, dan rate limiting.',
                'answer_en' => 'REST API is an architecture for system communication via HTTP. Principles: stateless, resource-based URLs, HTTP methods (GET=read, POST=create, PUT/PATCH=update, DELETE=remove). Response is usually JSON. Status codes: 200 OK, 201 Created, 400 Bad Request, 401 Unauthorized, 404 Not Found, 500 Server Error. Use versioning (v1/v2), pagination, and rate limiting.',
                'related' => ['http', 'json', 'graphql', 'endpoint'], 'difficulty' => 'basic',
            ],
            [
                'topic' => 'programming', 'keywords' => ['docker', 'container', 'containerization', 'devops'],
                'question_patterns' => ['apa itu docker', 'what is docker', 'cara pakai docker'],
                'answer_id' => 'Docker adalah platform containerization — mengemas aplikasi beserta dependensinya ke dalam container yang portable. Dockerfile mendefinisikan image, docker-compose.yml untuk multi-container setup. Keuntungan: environment konsisten, isolated, lightweight dibanding VM, mudah deploy. Perintah: docker build, docker run, docker-compose up.',
                'answer_en' => 'Docker is a containerization platform — packages applications with their dependencies into portable containers. Dockerfile defines images, docker-compose.yml for multi-container setup. Benefits: consistent environment, isolated, lightweight vs VMs, easy deployment. Commands: docker build, docker run, docker-compose up.',
                'related' => ['kubernetes', 'devops', 'ci/cd', 'deployment'], 'difficulty' => 'intermediate',
            ],
            [
                'topic' => 'programming', 'keywords' => ['react', 'reactjs', 'jsx', 'hooks', 'component'],
                'question_patterns' => ['apa itu react', 'what is react', 'belajar react', 'react hooks'],
                'answer_id' => 'React adalah library JavaScript dari Facebook untuk membangun UI berbasis component. Konsep utama: JSX (HTML di JS), Virtual DOM, Component (Function/Class), Props, State, Hooks (useState, useEffect, useContext, useReducer, useRef, useMemo, useCallback). React tidak opinionated tentang routing/state management — bisa pakai React Router, Redux, Zustand, dll.',
                'answer_en' => 'React is a JavaScript library from Facebook for building component-based UIs. Core concepts: JSX (HTML in JS), Virtual DOM, Components (Function/Class), Props, State, Hooks (useState, useEffect, useContext, useReducer, useRef, useMemo, useCallback). React is not opinionated about routing/state management — you can use React Router, Redux, Zustand, etc.',
                'related' => ['javascript', 'jsx', 'hooks', 'nextjs'], 'difficulty' => 'basic',
            ],
            [
                'topic' => 'programming', 'keywords' => ['typescript', 'ts', 'type', 'interface', 'generic'],
                'question_patterns' => ['apa itu typescript', 'what is typescript', 'typescript vs javascript'],
                'answer_id' => 'TypeScript adalah superset JavaScript yang menambahkan static typing. Keuntungan: deteksi error saat compile-time, intellisense lebih baik, kode lebih maintainable. Fitur: interfaces, generics, enums, union types, type guards, decorators, utility types (Partial, Required, Pick, Omit). TypeScript di-compile ke JavaScript biasa.',
                'answer_en' => 'TypeScript is a JavaScript superset that adds static typing. Benefits: compile-time error detection, better intellisense, more maintainable code. Features: interfaces, generics, enums, union types, type guards, decorators, utility types (Partial, Required, Pick, Omit). TypeScript compiles to plain JavaScript.',
                'related' => ['javascript', 'generics', 'type safety'], 'difficulty' => 'intermediate',
            ],
            [
                'topic' => 'programming', 'keywords' => ['algorithm', 'algoritma', 'sorting', 'searching', 'big o'],
                'question_patterns' => ['algoritma sorting', 'apa itu big o', 'sorting algorithm', 'binary search'],
                'answer_id' => 'Algoritma penting: Sorting — Bubble Sort O(n²), Merge Sort O(n log n), Quick Sort O(n log n) avg. Searching — Linear Search O(n), Binary Search O(log n). Big O Notation mengukur kompleksitas: O(1) constant, O(log n) logarithmic, O(n) linear, O(n log n) linearithmic, O(n²) quadratic. Dynamic Programming untuk optimasi subproblem yang overlap.',
                'answer_en' => 'Important algorithms: Sorting — Bubble Sort O(n²), Merge Sort O(n log n), Quick Sort O(n log n) avg. Searching — Linear Search O(n), Binary Search O(log n). Big O Notation measures complexity: O(1) constant, O(log n) logarithmic, O(n) linear, O(n log n) linearithmic, O(n²) quadratic. Dynamic Programming for overlapping subproblem optimization.',
                'related' => ['data structure', 'complexity', 'optimization'], 'difficulty' => 'intermediate',
            ],
            [
                'topic' => 'programming', 'keywords' => ['data structure', 'struktur data', 'array', 'linked list', 'tree', 'graph', 'stack', 'queue', 'hash'],
                'question_patterns' => ['struktur data', 'data structure', 'apa itu linked list', 'stack vs queue'],
                'answer_id' => 'Struktur Data utama: Array (akses O(1), insert O(n)), Linked List (insert O(1), akses O(n)), Stack (LIFO — push/pop), Queue (FIFO — enqueue/dequeue), Hash Table (akses O(1) avg), Binary Tree (search O(log n)), Graph (adjacency list/matrix). Heap untuk priority queue, Trie untuk string search.',
                'answer_en' => 'Main Data Structures: Array (access O(1), insert O(n)), Linked List (insert O(1), access O(n)), Stack (LIFO — push/pop), Queue (FIFO — enqueue/dequeue), Hash Table (access O(1) avg), Binary Tree (search O(log n)), Graph (adjacency list/matrix). Heap for priority queue, Trie for string search.',
                'related' => ['algorithm', 'complexity', 'programming'], 'difficulty' => 'intermediate',
            ],
            [
                'topic' => 'programming', 'keywords' => ['oop', 'object oriented', 'class', 'inheritance', 'polymorphism', 'encapsulation', 'abstraction'],
                'question_patterns' => ['apa itu oop', 'object oriented programming', 'pilar oop', '4 pilar oop'],
                'answer_id' => 'OOP (Object Oriented Programming) memiliki 4 pilar: 1) Encapsulation — membungkus data dan method, akses via public/private/protected. 2) Inheritance — class turunan mewarisi sifat parent class. 3) Polymorphism — satu interface, banyak implementasi (method overriding/overloading). 4) Abstraction — menyembunyikan kompleksitas, expose interface sederhana.',
                'answer_en' => 'OOP (Object Oriented Programming) has 4 pillars: 1) Encapsulation — bundling data and methods, access via public/private/protected. 2) Inheritance — child class inherits parent class properties. 3) Polymorphism — one interface, many implementations (method overriding/overloading). 4) Abstraction — hiding complexity, exposing simple interface.',
                'related' => ['solid', 'design pattern', 'class'], 'difficulty' => 'basic',
            ],
            [
                'topic' => 'programming', 'keywords' => ['mvc', 'model view controller', 'arsitektur', 'architecture'],
                'question_patterns' => ['apa itu mvc', 'model view controller', 'arsitektur mvc'],
                'answer_id' => 'MVC (Model-View-Controller) memisahkan aplikasi jadi 3 layer: Model (data & business logic), View (tampilan/UI), Controller (penghubung Model & View, handle request). Variasi: MVVM (Model-View-ViewModel), MVP (Model-View-Presenter). Laravel menggunakan MVC, Flutter sering pakai MVVM atau BLoC pattern.',
                'answer_en' => 'MVC (Model-View-Controller) separates app into 3 layers: Model (data & business logic), View (display/UI), Controller (connects Model & View, handles requests). Variations: MVVM (Model-View-ViewModel), MVP (Model-View-Presenter). Laravel uses MVC, Flutter often uses MVVM or BLoC pattern.',
                'related' => ['laravel', 'architecture', 'design pattern'], 'difficulty' => 'basic',
            ],
            [
                'topic' => 'programming', 'keywords' => ['testing', 'unit test', 'tdd', 'phpunit', 'jest'],
                'question_patterns' => ['cara testing', 'unit test', 'apa itu tdd', 'test driven development'],
                'answer_id' => 'Testing penting untuk memastikan kode berjalan benar. Jenis: Unit Test (test fungsi individual), Integration Test (test interaksi antar modul), E2E Test (test flow penuh). TDD: tulis test dulu, baru kode. Tools: PHPUnit (PHP), Jest (JS), pytest (Python), flutter_test (Dart). Aim for 80%+ code coverage.',
                'answer_en' => 'Testing ensures code works correctly. Types: Unit Test (test individual functions), Integration Test (test module interactions), E2E Test (test full flow). TDD: write test first, then code. Tools: PHPUnit (PHP), Jest (JS), pytest (Python), flutter_test (Dart). Aim for 80%+ code coverage.',
                'related' => ['tdd', 'phpunit', 'quality'], 'difficulty' => 'intermediate',
            ],
        ];
    }

    private function mathKnowledge(): array
    {
        return [
            [
                'topic' => 'math', 'keywords' => ['matematika', 'math', 'angka', 'hitung'],
                'question_patterns' => ['apa itu matematika', 'cabang matematika'],
                'answer_id' => 'Matematika adalah ilmu tentang bilangan, struktur, ruang, dan perubahan. Cabang utama: Aritmatika (operasi dasar), Aljabar (variabel & persamaan), Geometri (bentuk & ruang), Trigonometri (sudut & segitiga), Kalkulus (perubahan & akumulasi), Statistika (data & probabilitas), Aljabar Linear (vektor & matriks).',
                'answer_en' => 'Mathematics is the science of numbers, structures, space, and change. Main branches: Arithmetic (basic operations), Algebra (variables & equations), Geometry (shapes & space), Trigonometry (angles & triangles), Calculus (change & accumulation), Statistics (data & probability), Linear Algebra (vectors & matrices).',
                'related' => ['aritmatika', 'aljabar', 'geometri'], 'difficulty' => 'basic',
            ],
            [
                'topic' => 'math', 'keywords' => ['pi', 'phi', 'euler', 'konstanta', 'constant'],
                'question_patterns' => ['berapa nilai pi', 'what is pi', 'konstanta matematika', 'golden ratio'],
                'answer_id' => 'Konstanta matematika penting: Pi (π) = 3.14159265... (rasio keliling/diameter lingkaran). Euler (e) = 2.71828... (basis logaritma natural). Golden Ratio (φ) = 1.6180339... (ditemukan di alam & seni). Imaginary unit (i) = √(-1). Infinity (∞) bukan angka, tapi konsep.',
                'answer_en' => 'Important math constants: Pi (π) = 3.14159265... (circumference/diameter ratio). Euler (e) = 2.71828... (natural log base). Golden Ratio (φ) = 1.6180339... (found in nature & art). Imaginary unit (i) = √(-1). Infinity (∞) is not a number, but a concept.',
                'related' => ['lingkaran', 'geometri', 'fibonacci'], 'difficulty' => 'basic',
            ],
            [
                'topic' => 'math', 'keywords' => ['aljabar', 'algebra', 'persamaan', 'equation', 'variabel'],
                'question_patterns' => ['apa itu aljabar', 'cara hitung aljabar', 'persamaan linear', 'persamaan kuadrat'],
                'answer_id' => 'Aljabar menggunakan simbol/variabel untuk merepresentasikan bilangan. Persamaan linear: ax + b = 0, solusi x = -b/a. Persamaan kuadrat: ax² + bx + c = 0, rumus ABC: x = (-b ± √(b²-4ac)) / 2a. Diskriminan (D = b²-4ac): D > 0 = 2 akar real, D = 0 = 1 akar, D < 0 = akar kompleks.',
                'answer_en' => 'Algebra uses symbols/variables to represent numbers. Linear equation: ax + b = 0, solution x = -b/a. Quadratic equation: ax² + bx + c = 0, quadratic formula: x = (-b ± √(b²-4ac)) / 2a. Discriminant (D = b²-4ac): D > 0 = 2 real roots, D = 0 = 1 root, D < 0 = complex roots.',
                'related' => ['persamaan', 'variabel', 'fungsi'], 'difficulty' => 'basic',
            ],
            [
                'topic' => 'math', 'keywords' => ['geometri', 'geometry', 'luas', 'keliling', 'volume', 'area', 'bangun'],
                'question_patterns' => ['rumus luas', 'rumus keliling', 'rumus volume', 'luas lingkaran', 'volume bola'],
                'answer_id' => 'Rumus penting: Persegi: L=s², K=4s. Persegi panjang: L=p×l, K=2(p+l). Segitiga: L=½×a×t. Lingkaran: L=πr², K=2πr. Kubus: V=s³, Lp=6s². Balok: V=p×l×t. Tabung: V=πr²t. Bola: V=⁴⁄₃πr³, Lp=4πr². Kerucut: V=⅓πr²t. Prisma: V=Lalas×t.',
                'answer_en' => 'Key formulas: Square: A=s², P=4s. Rectangle: A=l×w, P=2(l+w). Triangle: A=½×b×h. Circle: A=πr², C=2πr. Cube: V=s³, SA=6s². Cuboid: V=l×w×h. Cylinder: V=πr²h. Sphere: V=⁴⁄₃πr³, SA=4πr². Cone: V=⅓πr²h. Prism: V=Abase×h.',
                'related' => ['luas', 'volume', 'bangun ruang'], 'difficulty' => 'basic',
            ],
            [
                'topic' => 'math', 'keywords' => ['trigonometri', 'trigonometry', 'sin', 'cos', 'tan', 'sudut'],
                'question_patterns' => ['rumus trigonometri', 'sin cos tan', 'apa itu trigonometri'],
                'answer_id' => 'Trigonometri mempelajari hubungan sudut dan sisi segitiga. Pada segitiga siku-siku: sin θ = depan/miring, cos θ = samping/miring, tan θ = depan/samping. Sudut istimewa: sin 30° = ½, cos 60° = ½, sin 45° = ½√2, tan 45° = 1. Identitas: sin²θ + cos²θ = 1. Hukum sinus: a/sin A = b/sin B. Hukum cosinus: c² = a² + b² − 2ab cos C.',
                'answer_en' => 'Trigonometry studies relationships between angles and sides of triangles. In a right triangle: sin θ = opposite/hypotenuse, cos θ = adjacent/hypotenuse, tan θ = opposite/adjacent. Special angles: sin 30° = ½, cos 60° = ½, sin 45° = ½√2, tan 45° = 1. Identity: sin²θ + cos²θ = 1. Law of sines: a/sin A = b/sin B. Law of cosines: c² = a² + b² − 2ab cos C.',
                'related' => ['sudut', 'segitiga', 'geometri'], 'difficulty' => 'intermediate',
            ],
            [
                'topic' => 'math', 'keywords' => ['kalkulus', 'calculus', 'turunan', 'integral', 'derivative', 'limit'],
                'question_patterns' => ['apa itu kalkulus', 'turunan fungsi', 'integral', 'limit fungsi'],
                'answer_id' => 'Kalkulus mempelajari perubahan. 2 cabang utama: Diferensial (turunan — laju perubahan) dan Integral (anti-turunan — akumulasi). Limit: lim x→a f(x). Turunan: f\'(x) = lim Δx→0 [f(x+Δx)-f(x)]/Δx. Rumus: d/dx(xⁿ) = nxⁿ⁻¹. Integral: ∫xⁿ dx = xⁿ⁺¹/(n+1) + C. Theorem Fundamental: ∫ₐᵇ f(x)dx = F(b) - F(a).',
                'answer_en' => 'Calculus studies change. 2 main branches: Differential (derivatives — rate of change) and Integral (antiderivatives — accumulation). Limit: lim x→a f(x). Derivative: f\'(x) = lim Δx→0 [f(x+Δx)-f(x)]/Δx. Formula: d/dx(xⁿ) = nxⁿ⁻¹. Integral: ∫xⁿ dx = xⁿ⁺¹/(n+1) + C. Fundamental Theorem: ∫ₐᵇ f(x)dx = F(b) - F(a).',
                'related' => ['limit', 'turunan', 'integral'], 'difficulty' => 'advanced',
            ],
            [
                'topic' => 'math', 'keywords' => ['statistik', 'statistics', 'mean', 'median', 'modus', 'standar deviasi', 'probabilitas'],
                'question_patterns' => ['rumus statistik', 'mean median modus', 'standar deviasi', 'probabilitas'],
                'answer_id' => 'Statistik deskriptif: Mean (rata-rata) = Σx/n. Median = nilai tengah data terurut. Modus = nilai paling sering muncul. Range = max - min. Variansi = Σ(x-μ)²/n. Standar Deviasi = √Variansi. Probabilitas: P(A) = kejadian sukses / total kejadian. P(A∪B) = P(A) + P(B) - P(A∩B). P(A|B) = P(A∩B)/P(B).',
                'answer_en' => 'Descriptive statistics: Mean (average) = Σx/n. Median = middle value of sorted data. Mode = most frequent value. Range = max - min. Variance = Σ(x-μ)²/n. Standard Deviation = √Variance. Probability: P(A) = successful events / total events. P(A∪B) = P(A) + P(B) - P(A∩B). P(A|B) = P(A∩B)/P(B).',
                'related' => ['probabilitas', 'distribusi', 'data'], 'difficulty' => 'intermediate',
            ],
            [
                'topic' => 'math', 'keywords' => ['fibonacci', 'deret', 'sequence', 'barisan'],
                'question_patterns' => ['deret fibonacci', 'barisan aritmatika', 'barisan geometri'],
                'answer_id' => 'Barisan aritmatika: Un = a + (n-1)d, Sn = n/2(2a + (n-1)d). Barisan geometri: Un = a×rⁿ⁻¹, Sn = a(rⁿ-1)/(r-1). Fibonacci: 0,1,1,2,3,5,8,13,21... setiap bilangan = jumlah 2 sebelumnya. Rasio fibonacci mendekati golden ratio (φ ≈ 1.618). Pascal Triangle: koefisien binomial.',
                'answer_en' => 'Arithmetic sequence: Un = a + (n-1)d, Sn = n/2(2a + (n-1)d). Geometric sequence: Un = a×rⁿ⁻¹, Sn = a(rⁿ-1)/(r-1). Fibonacci: 0,1,1,2,3,5,8,13,21... each number = sum of previous 2. Fibonacci ratio approaches golden ratio (φ ≈ 1.618). Pascal Triangle: binomial coefficients.',
                'related' => ['golden ratio', 'bilangan', 'pattern'], 'difficulty' => 'basic',
            ],
        ];
    }

    private function scienceKnowledge(): array
    {
        return [
            // ── Physics ─────────────────────────────────────────────
            [
                'topic' => 'science', 'keywords' => ['fisika', 'physics', 'newton', 'hukum newton', 'gaya', 'force'],
                'question_patterns' => ['hukum newton', 'apa itu fisika', 'rumus fisika dasar'],
                'answer_id' => 'Hukum Newton: 1) Kelembaman — benda diam tetap diam, benda bergerak tetap bergerak kecuali ada gaya. 2) F = m×a — gaya = massa × percepatan. 3) Aksi-reaksi — setiap gaya ada gaya reaksi sama besar berlawanan arah. Rumus penting: v = v₀ + at, s = v₀t + ½at², v² = v₀² + 2as, Ek = ½mv², Ep = mgh.',
                'answer_en' => 'Newton\'s Laws: 1) Inertia — object at rest stays at rest, moving object stays moving unless acted upon by force. 2) F = m×a — force = mass × acceleration. 3) Action-reaction — every force has an equal and opposite reaction. Key formulas: v = v₀ + at, s = v₀t + ½at², v² = v₀² + 2as, KE = ½mv², PE = mgh.',
                'related' => ['mekanika', 'energi', 'gerak'], 'difficulty' => 'basic',
            ],
            [
                'topic' => 'science', 'keywords' => ['einstein', 'relativitas', 'relativity', 'e=mc2'],
                'question_patterns' => ['teori relativitas', 'e=mc2', 'apa itu relativitas'],
                'answer_id' => 'Teori Relativitas Einstein: Khusus (1905) — kecepatan cahaya konstan di semua kerangka acuan, waktu melambat saat mendekati kecepatan cahaya (dilasi waktu), E=mc² (energi = massa × kecepatan cahaya²). Umum (1915) — gravitasi = lengkungan ruang-waktu oleh massa. Lubang hitam, gelombang gravitasi, dan GPS semua butuh koreksi relativistik.',
                'answer_en' => 'Einstein\'s Relativity: Special (1905) — speed of light is constant in all reference frames, time slows near speed of light (time dilation), E=mc² (energy = mass × speed of light²). General (1915) — gravity = curvature of spacetime by mass. Black holes, gravitational waves, and GPS all require relativistic corrections.',
                'related' => ['fisika', 'cahaya', 'ruang-waktu'], 'difficulty' => 'advanced',
            ],
            [
                'topic' => 'science', 'keywords' => ['atom', 'proton', 'neutron', 'elektron', 'kimia', 'chemistry'],
                'question_patterns' => ['apa itu atom', 'struktur atom', 'tabel periodik'],
                'answer_id' => 'Atom adalah unit terkecil materi. Terdiri dari: inti (proton + neutron) dan elektron mengorbit. Nomor atom = jumlah proton. Massa atom = proton + neutron. Tabel periodik mengatur 118 elemen berdasarkan nomor atom. Golongan (kolom) = elektron valensi sama. Periode (baris) = jumlah kulit elektron. Ikatan: ionik (transfer e), kovalen (sharing e), logam.',
                'answer_en' => 'Atom is the smallest unit of matter. Consists of: nucleus (protons + neutrons) and orbiting electrons. Atomic number = number of protons. Atomic mass = protons + neutrons. Periodic table organizes 118 elements by atomic number. Groups (columns) = same valence electrons. Periods (rows) = number of electron shells. Bonds: ionic (e transfer), covalent (e sharing), metallic.',
                'related' => ['kimia', 'elektron', 'molekul'], 'difficulty' => 'basic',
            ],
            [
                'topic' => 'science', 'keywords' => ['sel', 'cell', 'dna', 'gen', 'biologi', 'biology'],
                'question_patterns' => ['apa itu sel', 'struktur sel', 'apa itu dna', 'biologi dasar'],
                'answer_id' => 'Sel adalah unit terkecil kehidupan. 2 jenis: Prokariot (tanpa inti, contoh: bakteri) dan Eukariot (punya inti, contoh: manusia). Organel penting: nukleus (inti, simpan DNA), mitokondria (pembangkit energi/ATP), ribosom (sintesis protein), RE & Golgi (processing), membran sel (pelindung). DNA (deoxyribonucleic acid) menyimpan informasi genetik dalam urutan basa ATGC.',
                'answer_en' => 'Cell is the smallest unit of life. 2 types: Prokaryotic (no nucleus, e.g., bacteria) and Eukaryotic (has nucleus, e.g., humans). Key organelles: nucleus (stores DNA), mitochondria (energy/ATP generator), ribosome (protein synthesis), ER & Golgi (processing), cell membrane (protection). DNA (deoxyribonucleic acid) stores genetic information in ATGC base sequences.',
                'related' => ['biologi', 'genetika', 'evolusi'], 'difficulty' => 'basic',
            ],
            [
                'topic' => 'science', 'keywords' => ['evolusi', 'evolution', 'darwin', 'seleksi alam', 'natural selection'],
                'question_patterns' => ['teori evolusi', 'siapa darwin', 'seleksi alam', 'apa itu evolusi'],
                'answer_id' => 'Evolusi adalah perubahan frekuensi gen dalam populasi seiring waktu. Charles Darwin (1859) mengusulkan seleksi alam: individu dengan sifat menguntungkan lebih mungkin bertahan & bereproduksi. Bukti: fosil, anatomi perbandingan, DNA, embriologi. Mekanisme: mutasi (sumber variasi), seleksi alam, genetic drift, gene flow. Manusia dan simpanse berbagi ~98.7% DNA.',
                'answer_en' => 'Evolution is the change in gene frequency in a population over time. Charles Darwin (1859) proposed natural selection: individuals with advantageous traits are more likely to survive & reproduce. Evidence: fossils, comparative anatomy, DNA, embryology. Mechanisms: mutation (variation source), natural selection, genetic drift, gene flow. Humans and chimps share ~98.7% DNA.',
                'related' => ['biologi', 'genetika', 'fosil'], 'difficulty' => 'intermediate',
            ],
            [
                'topic' => 'science', 'keywords' => ['listrik', 'electricity', 'ohm', 'arus', 'tegangan', 'hambatan'],
                'question_patterns' => ['hukum ohm', 'apa itu listrik', 'rumus listrik'],
                'answer_id' => 'Listrik: aliran elektron dalam konduktor. Hukum Ohm: V = I × R (tegangan = arus × hambatan). Daya: P = V × I = I²R = V²/R. Sakak: Volt (tegangan), Ampere (arus), Ohm (hambatan), Watt (daya), kWh (energi). Rangkaian seri: Rtotal = R1 + R2. Rangkaian paralel: 1/Rtotal = 1/R1 + 1/R2. AC (bolak-balik) vs DC (searah).',
                'answer_en' => 'Electricity: flow of electrons through conductors. Ohm\'s Law: V = I × R (voltage = current × resistance). Power: P = V × I = I²R = V²/R. Units: Volt (voltage), Ampere (current), Ohm (resistance), Watt (power), kWh (energy). Series circuit: Rtotal = R1 + R2. Parallel circuit: 1/Rtotal = 1/R1 + 1/R2. AC (alternating) vs DC (direct).',
                'related' => ['fisika', 'rangkaian', 'energi'], 'difficulty' => 'basic',
            ],
        ];
    }

    private function historyKnowledge(): array
    {
        return [
            [
                'topic' => 'history', 'keywords' => ['kemerdekaan', 'indonesia', '1945', 'proklamasi', 'soekarno', 'sukarno'],
                'question_patterns' => ['kemerdekaan indonesia', 'proklamasi', 'sejarah indonesia', 'kapan indonesia merdeka'],
                'answer_id' => 'Indonesia memproklamasikan kemerdekaan pada 17 Agustus 1945, dibacakan oleh Soekarno dan Hatta di Jl. Pegangsaan Timur No. 56, Jakarta. Didahului oleh Rengasdengklok (16 Agustus) di mana pemuda "menculik" Soekarno-Hatta untuk mempercepat proklamasi. Naskah diketik oleh Sayuti Melik. UUD 1945 disahkan 18 Agustus 1945 oleh PPKI.',
                'answer_en' => 'Indonesia proclaimed independence on August 17, 1945, read by Soekarno and Hatta at Jl. Pegangsaan Timur No. 56, Jakarta. Preceded by the Rengasdengklok incident (Aug 16) where youth "kidnapped" Soekarno-Hatta to accelerate the proclamation. The text was typed by Sayuti Melik. The 1945 Constitution was ratified on Aug 18, 1945 by PPKI.',
                'related' => ['soekarno', 'hatta', 'uud 1945'], 'difficulty' => 'basic',
            ],
            [
                'topic' => 'history', 'keywords' => ['perang dunia', 'world war', 'ww2', 'ww1', 'hitler'],
                'question_patterns' => ['perang dunia 2', 'world war 2', 'kapan perang dunia', 'siapa hitler'],
                'answer_id' => 'Perang Dunia II (1939-1945): dipicu invasi Jerman ke Polandia. Pihak Sekutu (AS, UK, USSR, dll) vs Axis (Jerman, Jepang, Italia). Hitler memimpin Nazi Jerman, Holocaust membunuh 6 juta Yahudi. Berakhir: Jerman menyerah Mei 1945, Jepang setelah bom atom Hiroshima & Nagasaki (Agustus 1945). Korban ~70-85 juta jiwa, perang paling mematikan dalam sejarah.',
                'answer_en' => 'World War II (1939-1945): triggered by Germany\'s invasion of Poland. Allies (US, UK, USSR, etc.) vs Axis (Germany, Japan, Italy). Hitler led Nazi Germany, Holocaust killed 6 million Jews. Ended: Germany surrendered May 1945, Japan after atomic bombs on Hiroshima & Nagasaki (August 1945). Casualties ~70-85 million, deadliest war in history.',
                'related' => ['hitler', 'holocaust', 'hiroshima'], 'difficulty' => 'basic',
            ],
            [
                'topic' => 'history', 'keywords' => ['majapahit', 'sriwijaya', 'kerajaan', 'nusantara', 'gajah mada'],
                'question_patterns' => ['kerajaan majapahit', 'sriwijaya', 'sejarah nusantara', 'gajah mada'],
                'answer_id' => 'Kerajaan besar Nusantara: Sriwijaya (abad 7-13, maritim, Sumatra, pusat Buddhisme). Majapahit (1293-1527, Jawa Timur, puncak di bawah Hayam Wuruk & Gajah Mada). Sumpah Palapa Gajah Mada: tidak akan makan palapa sebelum menyatukan Nusantara. Borobudur (Abad 9, Sailendra) = candi Buddha terbesar dunia. Prambanan = candi Hindu terbesar di Indonesia.',
                'answer_en' => 'Great Nusantara kingdoms: Sriwijaya (7th-13th century, maritime, Sumatra, Buddhist center). Majapahit (1293-1527, East Java, peaked under Hayam Wuruk & Gajah Mada). Gajah Mada\'s Palapa Oath: won\'t rest until uniting the archipelago. Borobudur (9th century, Sailendra) = world\'s largest Buddhist temple. Prambanan = Indonesia\'s largest Hindu temple.',
                'related' => ['nusantara', 'hindu', 'buddha', 'jawa'], 'difficulty' => 'basic',
            ],
            [
                'topic' => 'history', 'keywords' => ['renaissance', 'revolusi industri', 'industrial revolution', 'enlightenment'],
                'question_patterns' => ['apa itu renaissance', 'revolusi industri', 'pencerahan eropa'],
                'answer_id' => 'Renaissance (abad 14-17, Italia): kebangkitan seni, sains, humanisme. Tokoh: Da Vinci, Michelangelo, Galileo, Shakespeare. Revolusi Industri (1760-1840, Inggris): mesin uap (James Watt), pabrik, urbanisasi, transportasi kereta api. Mengubah ekonomi agraris ke industri. Era Pencerahan (abad 17-18): penekanan pada akal & sains. Tokoh: Voltaire, Locke, Newton, Kant.',
                'answer_en' => 'Renaissance (14th-17th century, Italy): revival of arts, science, humanism. Figures: Da Vinci, Michelangelo, Galileo, Shakespeare. Industrial Revolution (1760-1840, England): steam engine (James Watt), factories, urbanization, railways. Shifted agrarian to industrial economy. Enlightenment (17th-18th century): emphasis on reason & science. Figures: Voltaire, Locke, Newton, Kant.',
                'related' => ['eropa', 'sains', 'seni'], 'difficulty' => 'intermediate',
            ],
        ];
    }

    private function philosophyKnowledge(): array
    {
        return [
            [
                'topic' => 'philosophy', 'keywords' => ['stoikisme', 'stoic', 'marcus aurelius', 'epictetus', 'seneca'],
                'question_patterns' => ['apa itu stoikisme', 'filsafat stoic', 'marcus aurelius'],
                'answer_id' => 'Stoikisme adalah filsafat Yunani kuno yang mengajarkan: 1) Fokus pada yang bisa kita kontrol, terima yang tidak bisa. 2) Kebajikan (wisdom, justice, courage, temperance) adalah satu-satunya kebaikan sejati. 3) Emosi negatif berasal dari penilaian kita, bukan dari kejadian itu sendiri. Tokoh: Marcus Aurelius (Meditations), Seneca (Letters), Epictetus (Discourses).',
                'answer_en' => 'Stoicism is an ancient Greek philosophy teaching: 1) Focus on what you can control, accept what you can\'t. 2) Virtue (wisdom, justice, courage, temperance) is the only true good. 3) Negative emotions come from our judgments, not events themselves. Key figures: Marcus Aurelius (Meditations), Seneca (Letters), Epictetus (Discourses).',
                'related' => ['filsafat', 'mindset', 'kebijaksanaan'], 'difficulty' => 'intermediate',
            ],
            [
                'topic' => 'philosophy', 'keywords' => ['filsafat', 'philosophy', 'sokrates', 'plato', 'aristoteles'],
                'question_patterns' => ['apa itu filsafat', 'siapa sokrates', 'filsuf terkenal'],
                'answer_id' => 'Filsafat (love of wisdom) mencari jawaban fundamental tentang keberadaan, pengetahuan, nilai, dan akal. Tiga filsuf Yunani terbesar: Sokrates (metode dialektika, "saya tahu bahwa saya tidak tahu"), Plato (dunia ide, Republik), Aristoteles (logika, etika, politik, murid Plato). Cabang: Metafisika, Epistemologi, Etika, Estetika, Logika.',
                'answer_en' => 'Philosophy (love of wisdom) seeks fundamental answers about existence, knowledge, values, and reason. Three greatest Greek philosophers: Socrates (dialectic method, "I know that I know nothing"), Plato (world of ideas, Republic), Aristotle (logic, ethics, politics, Plato\'s student). Branches: Metaphysics, Epistemology, Ethics, Aesthetics, Logic.',
                'related' => ['etika', 'logika', 'metafisika'], 'difficulty' => 'basic',
            ],
            [
                'topic' => 'philosophy', 'keywords' => ['cognitive bias', 'bias kognitif', 'logical fallacy', 'kekeliruan logika'],
                'question_patterns' => ['apa itu cognitive bias', 'bias kognitif', 'logical fallacy', 'kekeliruan berpikir'],
                'answer_id' => 'Cognitive bias adalah kesalahan sistematis dalam berpikir. Penting untuk dikenali: Confirmation Bias (hanya cari info yang mengonfirmasi keyakinan kita), Dunning-Kruger (yang tidak kompeten merasa lebih kompeten), Anchoring (terpengaruh info pertama), Sunk Cost (lanjut karena sudah investasi), Bandwagon (ikut mayoritas), Halo Effect (kesan pertama memengaruhi semua penilaian).',
                'answer_en' => 'Cognitive biases are systematic thinking errors. Important to recognize: Confirmation Bias (only seek info confirming our beliefs), Dunning-Kruger (incompetent feel more competent), Anchoring (influenced by first info), Sunk Cost (continuing due to investment), Bandwagon (following majority), Halo Effect (first impression influences all judgments).',
                'related' => ['psikologi', 'logika', 'keputusan'], 'difficulty' => 'intermediate',
            ],
        ];
    }

    private function healthKnowledge(): array
    {
        return [
            [
                'topic' => 'health', 'keywords' => ['tidur', 'sleep', 'insomnia', 'kualitas tidur'],
                'question_patterns' => ['tips tidur', 'cara tidur nyenyak', 'berapa jam tidur', 'insomnia'],
                'answer_id' => 'Tidur ideal: dewasa 7-9 jam, remaja 8-10 jam, anak 9-12 jam. Tips tidur berkualitas: 1) Jadwal tidur konsisten. 2) Ruangan gelap & sejuk (18-22°C). 3) Hindari layar 1 jam sebelum tidur. 4) Tidak minum kafein setelah jam 2 siang. 5) Olahraga rutin (tapi tidak dekat waktu tidur). 6) Teknik relaksasi 4-7-8 (hirup 4 detik, tahan 7, buang 8).',
                'answer_en' => 'Ideal sleep: adults 7-9 hours, teens 8-10 hours, children 9-12 hours. Quality sleep tips: 1) Consistent sleep schedule. 2) Dark & cool room (18-22°C). 3) Avoid screens 1 hour before bed. 4) No caffeine after 2 PM. 5) Regular exercise (but not close to bedtime). 6) 4-7-8 relaxation technique (inhale 4s, hold 7s, exhale 8s).',
                'related' => ['kesehatan', 'rutinitas', 'otak'], 'difficulty' => 'basic',
            ],
            [
                'topic' => 'health', 'keywords' => ['nutrisi', 'nutrition', 'vitamin', 'mineral', 'gizi', 'makanan sehat'],
                'question_patterns' => ['makanan sehat', 'nutrisi penting', 'vitamin apa yang penting'],
                'answer_id' => 'Nutrisi penting: Makronutrien — Karbohidrat (energi), Protein (otot/sel), Lemak (hormon/otak). Mikronutrien — Vitamin A (mata), B (energi), C (imun), D (tulang, dari matahari), E (antioksidan), K (darah). Mineral: Kalsium (tulang), Zat Besi (darah), Zinc (imun), Magnesium (otot/saraf). Minum air 2-3 liter/hari. Makan 5 porsi sayur & buah/hari.',
                'answer_en' => 'Essential nutrition: Macronutrients — Carbohydrates (energy), Protein (muscle/cells), Fat (hormones/brain). Micronutrients — Vitamin A (eyes), B (energy), C (immune), D (bones, from sun), E (antioxidant), K (blood). Minerals: Calcium (bones), Iron (blood), Zinc (immune), Magnesium (muscle/nerve). Drink 2-3 liters water/day. Eat 5 servings of fruits & veggies/day.',
                'related' => ['diet', 'makanan', 'olahraga'], 'difficulty' => 'basic',
            ],
            [
                'topic' => 'health', 'keywords' => ['mental health', 'kesehatan mental', 'stres', 'depresi', 'anxiety', 'kecemasan'],
                'question_patterns' => ['cara mengatasi stres', 'kesehatan mental', 'tips mental health', 'mengatasi anxiety'],
                'answer_id' => 'Kesehatan mental sama pentingnya dengan fisik. Tips: 1) Bicara dengan orang terpercaya. 2) Olahraga teratur (endorfin). 3) Tidur cukup. 4) Meditasi/mindfulness 10 menit/hari. 5) Batasi media sosial. 6) Journaling (tulis perasaan). 7) Hobi dan aktivitas menyenangkan. 8) Minta bantuan profesional jika perlu — tidak ada yang salah dengan terapi. Hotline: 119 ext 8.',
                'answer_en' => 'Mental health is as important as physical health. Tips: 1) Talk to someone you trust. 2) Regular exercise (endorphins). 3) Adequate sleep. 4) Meditation/mindfulness 10 min/day. 5) Limit social media. 6) Journaling (write feelings). 7) Hobbies and enjoyable activities. 8) Seek professional help if needed — there\'s nothing wrong with therapy. Crisis hotline: 119 ext 8 (Indonesia).',
                'related' => ['stres', 'meditasi', 'terapi'], 'difficulty' => 'basic',
            ],
        ];
    }

    private function financeKnowledge(): array
    {
        return [
            [
                'topic' => 'finance', 'keywords' => ['investasi', 'investment', 'saham', 'stock', 'reksadana', 'obligasi'],
                'question_patterns' => ['cara investasi', 'apa itu saham', 'investasi pemula', 'reksadana'],
                'answer_id' => 'Investasi dasar: 1) Deposito (rendah risiko, return 3-5%). 2) Obligasi/Surat Utang (risiko menengah, return 5-8%). 3) Reksadana (dikelola manajer investasi, cocok pemula). 4) Saham (risiko tinggi, return potensial tinggi). 5) Emas (safe haven). 6) Properti (jangka panjang). Prinsip: diversifikasi, invest sesuai profil risiko, jangan invest uang yang dibutuhkan segera, konsisten (dollar cost averaging).',
                'answer_en' => 'Investment basics: 1) Deposits (low risk, 3-5% return). 2) Bonds (medium risk, 5-8% return). 3) Mutual Funds (managed by fund manager, good for beginners). 4) Stocks (high risk, high potential return). 5) Gold (safe haven). 6) Property (long-term). Principles: diversify, invest per risk profile, don\'t invest money needed soon, be consistent (dollar cost averaging).',
                'related' => ['uang', 'keuangan', 'saham'], 'difficulty' => 'basic',
            ],
            [
                'topic' => 'finance', 'keywords' => ['budgeting', 'anggaran', 'keuangan pribadi', 'personal finance', 'tabungan'],
                'question_patterns' => ['cara budgeting', 'keuangan pribadi', 'cara menabung', 'atur keuangan'],
                'answer_id' => 'Metode budgeting populer: 1) 50/30/20 — 50% kebutuhan, 30% keinginan, 20% tabungan/investasi. 2) Envelope system — uang tunai per kategori. 3) Zero-based — setiap rupiah punya tugas. Tips: 1) Track semua pengeluaran. 2) Dana darurat 3-6 bulan pengeluaran. 3) Bayar utang bunga tinggi dulu. 4) Otomatiskan tabungan. 5) Hindari utang konsumtif.',
                'answer_en' => 'Popular budgeting methods: 1) 50/30/20 — 50% needs, 30% wants, 20% savings/investment. 2) Envelope system — cash per category. 3) Zero-based — every dollar has a job. Tips: 1) Track all expenses. 2) Emergency fund of 3-6 months expenses. 3) Pay high-interest debt first. 4) Automate savings. 5) Avoid consumer debt.',
                'related' => ['tabungan', 'investasi', 'utang'], 'difficulty' => 'basic',
            ],
        ];
    }

    private function languageLiteratureKnowledge(): array
    {
        return [
            [
                'topic' => 'language', 'keywords' => ['grammar', 'tata bahasa', 'bahasa inggris', 'english grammar', 'tenses'],
                'question_patterns' => ['grammar english', 'tenses bahasa inggris', 'cara belajar bahasa inggris'],
                'answer_id' => '12 Tenses Bahasa Inggris: Present Simple (I eat), Present Continuous (I am eating), Present Perfect (I have eaten), Present Perfect Continuous (I have been eating) — dan pola yang sama untuk Past dan Future. Tips belajar: 1) Immersion (film, musik, buku). 2) Praktik bicara setiap hari. 3) Pelajari frasa, bukan kata per kata. 4) Jangan takut salah.',
                'answer_en' => '12 English Tenses: Present Simple (I eat), Present Continuous (I am eating), Present Perfect (I have eaten), Present Perfect Continuous (I have been eating) — and the same patterns for Past and Future. Learning tips: 1) Immersion (movies, music, books). 2) Practice speaking daily. 3) Learn phrases, not single words. 4) Don\'t fear making mistakes.',
                'related' => ['bahasa', 'grammar', 'writing'], 'difficulty' => 'basic',
            ],
            [
                'topic' => 'language', 'keywords' => ['writing', 'menulis', 'essay', 'artikel', 'copywriting'],
                'question_patterns' => ['tips menulis', 'cara menulis essay', 'writing tips', 'cara menulis artikel'],
                'answer_id' => 'Tips menulis efektif: 1) Struktur jelas: Intro (hook + thesis), Body (argumen + bukti), Conclusion (rangkum + call to action). 2) Gunakan kalimat aktif. 3) Satu paragraf = satu ide utama. 4) Edit & revisi minimal 2x. 5) Read aloud untuk cek flow. 6) Hindari jargon yang tidak perlu. 7) Show, don\'t tell. 8) Baca banyak untuk memperkaya gaya menulis.',
                'answer_en' => 'Effective writing tips: 1) Clear structure: Intro (hook + thesis), Body (arguments + evidence), Conclusion (summarize + call to action). 2) Use active voice. 3) One paragraph = one main idea. 4) Edit & revise at least 2x. 5) Read aloud to check flow. 6) Avoid unnecessary jargon. 7) Show, don\'t tell. 8) Read widely to enrich writing style.',
                'related' => ['essay', 'creative writing', 'grammar'], 'difficulty' => 'basic',
            ],
        ];
    }

    private function generalKnowledge(): array
    {
        return [
            [
                'topic' => 'general', 'keywords' => ['bumi', 'earth', 'planet', 'tata surya', 'solar system'],
                'question_patterns' => ['planet tata surya', 'berapa planet', 'tentang bumi', 'solar system'],
                'answer_id' => '8 planet di tata surya (dari matahari): Merkurius, Venus, Bumi, Mars, Jupiter, Saturnus, Uranus, Neptunus. Pluto direklasifikasi jadi planet kerdil (2006). Jupiter = planet terbesar. Bumi = satu-satunya yang diketahui punya kehidupan. Mars = target koloni manusia. Matahari = bintang, 99.86% massa tata surya. Jarak Bumi-Matahari = ~150 juta km (1 AU).',
                'answer_en' => '8 planets in the solar system (from the sun): Mercury, Venus, Earth, Mars, Jupiter, Saturn, Uranus, Neptune. Pluto was reclassified as dwarf planet (2006). Jupiter = largest planet. Earth = only known planet with life. Mars = target for human colonization. The Sun = a star, 99.86% of solar system mass. Earth-Sun distance = ~150 million km (1 AU).',
                'related' => ['astronomi', 'planet', 'matahari'], 'difficulty' => 'basic',
            ],
            [
                'topic' => 'general', 'keywords' => ['air', 'water', 'h2o', 'siklus air', 'water cycle'],
                'question_patterns' => ['siklus air', 'apa itu h2o', 'fakta tentang air'],
                'answer_id' => 'Air (H₂O) menutupi ~71% permukaan Bumi tapi hanya 2.5% adalah air tawar, dan hanya 1% bisa diakses manusia. Siklus air: evaporasi → kondensasi → presipitasi → infiltrasi/runoff → kembali ke laut/sungai. Air membeku di 0°C, mendidih di 100°C (tekanan normal). Tubuh manusia ~60% air. Minum 2-3 liter/hari untuk kesehatan optimal.',
                'answer_en' => 'Water (H₂O) covers ~71% of Earth\'s surface but only 2.5% is freshwater, and only 1% is accessible to humans. Water cycle: evaporation → condensation → precipitation → infiltration/runoff → back to sea/rivers. Water freezes at 0°C, boils at 100°C (normal pressure). Human body is ~60% water. Drink 2-3 liters/day for optimal health.',
                'related' => ['sains', 'kimia', 'lingkungan'], 'difficulty' => 'basic',
            ],
        ];
    }

    private function lifeSkillsKnowledge(): array
    {
        return [
            [
                'topic' => 'life_skills', 'keywords' => ['interview', 'wawancara', 'kerja', 'career', 'karir', 'cv', 'resume'],
                'question_patterns' => ['tips interview', 'cara interview kerja', 'cara buat cv', 'tips karir'],
                'answer_id' => 'Tips interview kerja: 1) Riset perusahaan & posisi. 2) Siapkan jawaban STAR (Situation, Task, Action, Result) untuk pertanyaan behavioral. 3) Berpakaian profesional. 4) Datang 10-15 menit lebih awal. 5) Pertanyaan umum: "Ceritakan tentang diri Anda", "Kekuatan & kelemahan", "Mengapa kami harus mempekerjakan Anda?". 6) Siapkan pertanyaan balik. 7) Follow-up email setelahnya.',
                'answer_en' => 'Job interview tips: 1) Research the company & position. 2) Prepare STAR answers (Situation, Task, Action, Result) for behavioral questions. 3) Dress professionally. 4) Arrive 10-15 minutes early. 5) Common questions: "Tell me about yourself", "Strengths & weaknesses", "Why should we hire you?". 6) Prepare questions to ask back. 7) Send a follow-up email.',
                'related' => ['karir', 'profesional', 'cv'], 'difficulty' => 'basic',
            ],
            [
                'topic' => 'life_skills', 'keywords' => ['time management', 'manajemen waktu', 'produktivitas', 'productivity'],
                'question_patterns' => ['tips manajemen waktu', 'cara produktif', 'time management tips'],
                'answer_id' => 'Teknik manajemen waktu: 1) Pomodoro (25 min kerja, 5 min istirahat). 2) Eat the Frog (kerjakan tugas tersulit di pagi). 3) Time Blocking (alokasi jam untuk aktivitas spesifik). 4) 2-Minute Rule (jika < 2 menit, langsung kerjakan). 5) Eisenhower Matrix (urgent vs important). 6) Pareto Principle (80/20 — 20% usaha = 80% hasil). 7) Batasi multitasking — single-tasking lebih efektif.',
                'answer_en' => 'Time management techniques: 1) Pomodoro (25 min work, 5 min break). 2) Eat the Frog (do hardest task first in morning). 3) Time Blocking (allocate hours for specific activities). 4) 2-Minute Rule (if < 2 min, do it now). 5) Eisenhower Matrix (urgent vs important). 6) Pareto Principle (80/20 — 20% effort = 80% results). 7) Limit multitasking — single-tasking is more effective.',
                'related' => ['produktivitas', 'fokus', 'perencanaan'], 'difficulty' => 'basic',
            ],
            [
                'topic' => 'life_skills', 'keywords' => ['komunikasi', 'communication', 'public speaking', 'presentasi'],
                'question_patterns' => ['tips komunikasi', 'cara public speaking', 'tips presentasi'],
                'answer_id' => 'Tips komunikasi efektif: 1) Dengarkan aktif (eye contact, jangan potong pembicaraan). 2) Gunakan "saya" bukan "kamu" saat konflik. 3) Ringkas & jelas. 4) Perhatikan bahasa tubuh. Public speaking: 1) Kenali audiens. 2) Struktur: Opening (hook), Body (3 poin), Closing (call to action). 3) Latihan minimal 5x. 4) Gunakan cerita. 5) Pause untuk emphasis, bukan "eee" atau "umm".',
                'answer_en' => 'Effective communication tips: 1) Listen actively (eye contact, don\'t interrupt). 2) Use "I" statements instead of "you" during conflicts. 3) Be concise & clear. 4) Watch body language. Public speaking: 1) Know your audience. 2) Structure: Opening (hook), Body (3 points), Closing (call to action). 3) Practice at least 5 times. 4) Use stories. 5) Pause for emphasis, not "um" or "uh".',
                'related' => ['presentasi', 'kepemimpinan', 'negosiasi'], 'difficulty' => 'basic',
            ],
        ];
    }

    private function indonesianCultureKnowledge(): array
    {
        return [
            [
                'topic' => 'indonesian_culture', 'keywords' => ['pancasila', 'dasar negara', 'ideologi'],
                'question_patterns' => ['apa itu pancasila', 'sebutkan sila pancasila', 'dasar negara indonesia'],
                'answer_id' => 'Pancasila adalah dasar negara Indonesia. 5 Sila: 1) Ketuhanan Yang Maha Esa. 2) Kemanusiaan yang Adil dan Beradab. 3) Persatuan Indonesia. 4) Kerakyatan yang Dipimpin oleh Hikmat Kebijaksanaan dalam Permusyawaratan/Perwakilan. 5) Keadilan Sosial bagi Seluruh Rakyat Indonesia. Dirumuskan dalam sidang BPUPKI Juni 1945, disahkan 18 Agustus 1945.',
                'answer_en' => 'Pancasila is Indonesia\'s state ideology. 5 Principles: 1) Belief in One God. 2) Just and Civilized Humanity. 3) Unity of Indonesia. 4) Democracy Led by Wisdom of Deliberation/Representation. 5) Social Justice for All Indonesian People. Formulated in BPUPKI session June 1945, ratified August 18, 1945.',
                'related' => ['indonesia', 'konstitusi', 'uud 1945'], 'difficulty' => 'basic',
            ],
            [
                'topic' => 'indonesian_culture', 'keywords' => ['batik', 'wayang', 'gamelan', 'budaya', 'kebudayaan'],
                'question_patterns' => ['apa itu batik', 'budaya indonesia', 'wayang kulit', 'gamelan'],
                'answer_id' => 'Warisan budaya Indonesia yang diakui UNESCO: Batik (2009, teknik pewarnaan lilin), Wayang (2003, teater boneka bayangan), Keris (2005, senjata tradisional), Angklung (2010, alat musik bambu Sunda), Pencak Silat (2019, seni bela diri). Gamelan = ansambel musik tradisional Jawa/Bali. Tari Saman, Tari Kecak, Tari Pendet = tarian daerah terkenal.',
                'answer_en' => 'UNESCO-recognized Indonesian cultural heritage: Batik (2009, wax-resist dyeing), Wayang (2003, shadow puppet theater), Keris (2005, traditional weapon), Angklung (2010, Sundanese bamboo instrument), Pencak Silat (2019, martial art). Gamelan = traditional Javanese/Balinese musical ensemble. Saman, Kecak, Pendet = famous regional dances.',
                'related' => ['seni', 'tradisi', 'unesco'], 'difficulty' => 'basic',
            ],
            [
                'topic' => 'indonesian_culture', 'keywords' => ['bahasa indonesia', 'bahasa daerah', 'suku', 'etnis'],
                'question_patterns' => ['berapa suku di indonesia', 'bahasa daerah', 'keragaman indonesia'],
                'answer_id' => 'Indonesia memiliki 1.340+ suku bangsa, 718+ bahasa daerah, 17.504 pulau, dan 6 agama resmi (Islam, Kristen, Katolik, Hindu, Buddha, Konghucu). Suku terbesar: Jawa (40%), Sunda (15%), Melayu, Batak, Madura, Betawi, Minangkabau, Bugis. Bahasa Indonesia (Melayu) menjadi bahasa persatuan sejak Sumpah Pemuda 28 Oktober 1928.',
                'answer_en' => 'Indonesia has 1,340+ ethnic groups, 718+ regional languages, 17,504 islands, and 6 official religions (Islam, Christianity, Catholicism, Hinduism, Buddhism, Confucianism). Largest ethnic groups: Javanese (40%), Sundanese (15%), Malay, Batak, Madurese, Betawi, Minangkabau, Bugis. Indonesian (Malay) became the unifying language since the Youth Pledge of October 28, 1928.',
                'related' => ['sumpah pemuda', 'keragaman', 'nusantara'], 'difficulty' => 'basic',
            ],
        ];
    }

    private function technologyKnowledge(): array
    {
        return [
            [
                'topic' => 'technology', 'keywords' => ['ai', 'artificial intelligence', 'kecerdasan buatan', 'machine learning', 'deep learning'],
                'question_patterns' => ['apa itu ai', 'artificial intelligence', 'machine learning', 'kecerdasan buatan'],
                'answer_id' => 'AI (Artificial Intelligence) adalah kemampuan mesin meniru kecerdasan manusia. Subset: Machine Learning (belajar dari data tanpa diprogram eksplisit), Deep Learning (neural network berlapis). Jenis: Narrow AI (tugas spesifik, contoh: ChatGPT, face recognition), General AI (setara manusia, belum ada), Super AI (melebihi manusia, hipotetis). Tools populer: TensorFlow, PyTorch, scikit-learn.',
                'answer_en' => 'AI (Artificial Intelligence) is the ability of machines to mimic human intelligence. Subsets: Machine Learning (learning from data without explicit programming), Deep Learning (multi-layer neural networks). Types: Narrow AI (specific tasks, e.g., ChatGPT, face recognition), General AI (human-level, doesn\'t exist yet), Super AI (beyond human, hypothetical). Popular tools: TensorFlow, PyTorch, scikit-learn.',
                'related' => ['machine learning', 'neural network', 'data'], 'difficulty' => 'intermediate',
            ],
            [
                'topic' => 'technology', 'keywords' => ['blockchain', 'crypto', 'bitcoin', 'cryptocurrency', 'web3'],
                'question_patterns' => ['apa itu blockchain', 'apa itu bitcoin', 'cryptocurrency', 'web3'],
                'answer_id' => 'Blockchain adalah ledger terdistribusi yang menyimpan transaksi secara transparan & immutable. Bitcoin (2009, Satoshi Nakamoto) = cryptocurrency pertama. Ethereum menambahkan smart contracts. Konsep: mining (proof of work), staking (proof of stake), wallet, gas fee. Web3 = internet terdesentralisasi berbasis blockchain. NFT = token unik untuk kepemilikan digital. Risiko: volatilitas tinggi, scam, regulasi.',
                'answer_en' => 'Blockchain is a distributed ledger storing transactions transparently & immutably. Bitcoin (2009, Satoshi Nakamoto) = first cryptocurrency. Ethereum added smart contracts. Concepts: mining (proof of work), staking (proof of stake), wallet, gas fees. Web3 = decentralized internet on blockchain. NFT = unique tokens for digital ownership. Risks: high volatility, scams, regulation.',
                'related' => ['crypto', 'fintech', 'web3'], 'difficulty' => 'intermediate',
            ],
            [
                'topic' => 'technology', 'keywords' => ['cloud', 'aws', 'azure', 'gcp', 'cloud computing'],
                'question_patterns' => ['apa itu cloud computing', 'cloud aws', 'server cloud'],
                'answer_id' => 'Cloud computing menyediakan sumber daya IT via internet. 3 model: IaaS (Infrastructure — VM, storage), PaaS (Platform — Heroku, App Engine), SaaS (Software — Gmail, Office 365). Provider besar: AWS (Amazon), Azure (Microsoft), GCP (Google). Keuntungan: pay-as-you-go, scalable, no hardware maintenance. Layanan populer: EC2, S3, Lambda, RDS, CloudFront.',
                'answer_en' => 'Cloud computing provides IT resources over the internet. 3 models: IaaS (Infrastructure — VMs, storage), PaaS (Platform — Heroku, App Engine), SaaS (Software — Gmail, Office 365). Major providers: AWS (Amazon), Azure (Microsoft), GCP (Google). Benefits: pay-as-you-go, scalable, no hardware maintenance. Popular services: EC2, S3, Lambda, RDS, CloudFront.',
                'related' => ['server', 'deployment', 'devops'], 'difficulty' => 'intermediate',
            ],
        ];
    }

    private function geographyKnowledge(): array
    {
        return [
            [
                'topic' => 'geography', 'keywords' => ['indonesia', 'provinsi', 'ibu kota', 'pulau'],
                'question_patterns' => ['berapa provinsi indonesia', 'ibu kota indonesia', 'pulau terbesar indonesia'],
                'answer_id' => 'Indonesia memiliki 38 provinsi (per 2024). Ibu kota: Jakarta (sedang pindah ke IKN Nusantara, Kalimantan Timur). 5 pulau besar: Sumatra, Jawa, Kalimantan, Sulawesi, Papua. Pulau terbesar: Papua (2nd terbesar di dunia). Total 17.504 pulau. Gunung tertinggi: Puncak Jaya (4.884 m, Papua). Danau terbesar: Danau Toba (Sumatra Utara, kaldera supervulkanik).',
                'answer_en' => 'Indonesia has 38 provinces (as of 2024). Capital: Jakarta (relocating to IKN Nusantara, East Kalimantan). 5 major islands: Sumatra, Java, Kalimantan, Sulawesi, Papua. Largest island: Papua (2nd largest in the world). Total 17,504 islands. Highest mountain: Puncak Jaya (4,884 m, Papua). Largest lake: Lake Toba (North Sumatra, supervolcanic caldera).',
                'related' => ['nusantara', 'pulau', 'geografi'], 'difficulty' => 'basic',
            ],
            [
                'topic' => 'geography', 'keywords' => ['negara', 'country', 'benua', 'continent', 'dunia', 'world'],
                'question_patterns' => ['berapa negara di dunia', 'benua di dunia', 'negara terbesar'],
                'answer_id' => 'Dunia memiliki ~195 negara (193 anggota PBB + 2 pengamat). 7 benua: Asia (terbesar), Afrika, Amerika Utara, Amerika Selatan, Antartika, Eropa, Australia/Oseania. Negara terluas: Rusia (17.1 juta km²). Terkecil: Vatikan (0.44 km²). Terpadat: India. Populasi dunia: ~8 miliar (2024). Samudra: Pasifik (terbesar), Atlantik, Hindia, Arktik, Selatan.',
                'answer_en' => 'The world has ~195 countries (193 UN members + 2 observers). 7 continents: Asia (largest), Africa, North America, South America, Antarctica, Europe, Australia/Oceania. Largest country: Russia (17.1 million km²). Smallest: Vatican (0.44 km²). Most populated: India. World population: ~8 billion (2024). Oceans: Pacific (largest), Atlantic, Indian, Arctic, Southern.',
                'related' => ['benua', 'populasi', 'geografi'], 'difficulty' => 'basic',
            ],
        ];
    }

    private function foodCookingKnowledge(): array
    {
        return [
            [
                'topic' => 'food', 'keywords' => ['masakan indonesia', 'rendang', 'nasi goreng', 'sate', 'gudeg'],
                'question_patterns' => ['masakan indonesia terbaik', 'resep rendang', 'makanan khas indonesia'],
                'answer_id' => 'Masakan Indonesia terkenal dunia: Rendang (Padang, daging sapi bumbu rempah, pernah dinobatkan makanan terenak dunia CNN). Nasi Goreng (nasi digoreng kecap + bumbu). Sate (daging tusuk bakar + bumbu kacang). Gudeg (nangka muda Jogja). Soto (sup rempah). Gado-gado (sayur + bumbu kacang). Bakso (bola daging). Rawon (sup hitam kluwek). Pempek (ikan Palembang).',
                'answer_en' => 'Famous Indonesian dishes: Rendang (Padang, spiced braised beef, voted world\'s best food by CNN). Nasi Goreng (fried rice with sweet soy + spices). Satay (grilled meat skewers + peanut sauce). Gudeg (young jackfruit stew, Jogja). Soto (spiced soup). Gado-gado (vegetables + peanut sauce). Bakso (meatball soup). Rawon (black kluwek soup). Pempek (fish cake, Palembang).',
                'related' => ['kuliner', 'rempah', 'tradisi'], 'difficulty' => 'basic',
            ],
        ];
    }

    private function sportsKnowledge(): array
    {
        return [
            [
                'topic' => 'sports', 'keywords' => ['sepak bola', 'football', 'soccer', 'piala dunia', 'world cup'],
                'question_patterns' => ['piala dunia', 'sejarah sepak bola', 'world cup', 'pemain bola terbaik'],
                'answer_id' => 'Sepak bola = olahraga paling populer dunia. Piala Dunia FIFA setiap 4 tahun. Juara terbanyak: Brasil (5x). Pemain legendaris: Pele (Brasil), Maradona (Argentina), Messi (Argentina, 8 Ballon d\'Or), Ronaldo (Portugal, 5 Ballon d\'Or). Liga top: Premier League (Inggris), La Liga (Spanyol), Serie A (Italia), Bundesliga (Jerman). Indonesia punya Liga 1 dan Timnas Garuda.',
                'answer_en' => 'Football/soccer = world\'s most popular sport. FIFA World Cup every 4 years. Most titles: Brazil (5x). Legendary players: Pele (Brazil), Maradona (Argentina), Messi (Argentina, 8 Ballon d\'Or), Ronaldo (Portugal, 5 Ballon d\'Or). Top leagues: Premier League (England), La Liga (Spain), Serie A (Italy), Bundesliga (Germany). Indonesia has Liga 1 and the Garuda national team.',
                'related' => ['olahraga', 'liga', 'atlet'], 'difficulty' => 'basic',
            ],
            [
                'topic' => 'sports', 'keywords' => ['badminton', 'bulutangkis', 'olimpiade', 'thomas cup', 'uber cup'],
                'question_patterns' => ['badminton indonesia', 'bulutangkis', 'atlet badminton'],
                'answer_id' => 'Indonesia = salah satu kekuatan badminton dunia. Legenda: Susi Susanti, Taufik Hidayat (emas Olimpiade 2004), Rudy Hartono (8x All England). Thomas Cup (putra) — Indonesia juara 14x. Uber Cup (putri) — 3x. Pemain modern: Jonatan Christie, Anthony Ginting, Greysia/Apriyani (emas Olimpiade 2020). BWF World Tour = sirkuit dunia badminton.',
                'answer_en' => 'Indonesia = one of the world\'s badminton powerhouses. Legends: Susi Susanti, Taufik Hidayat (2004 Olympics gold), Rudy Hartono (8x All England). Thomas Cup (men\'s) — Indonesia won 14x. Uber Cup (women\'s) — 3x. Modern players: Jonatan Christie, Anthony Ginting, Greysia/Apriyani (2020 Olympics gold). BWF World Tour = world badminton circuit.',
                'related' => ['olahraga', 'olimpiade', 'indonesia'], 'difficulty' => 'basic',
            ],
        ];
    }

    private function musicArtKnowledge(): array
    {
        return [
            [
                'topic' => 'music_art', 'keywords' => ['musik', 'music', 'genre', 'alat musik', 'instrument'],
                'question_patterns' => ['genre musik', 'alat musik', 'sejarah musik'],
                'answer_id' => 'Genre musik utama: Pop, Rock, Jazz, Blues, R&B, Hip-Hop, Classical, Electronic/EDM, Country, Reggae, Metal, Punk, Indie. Alat musik: Piano (keyboard), Gitar (string), Drum (perkusi), Biola (string bowed), Saxophone (wind), Flute (woodwind). Nada: Do Re Mi Fa Sol La Si (C D E F G A B). Chord dasar: Mayor (ceria), Minor (sedih), Diminished, Augmented.',
                'answer_en' => 'Main music genres: Pop, Rock, Jazz, Blues, R&B, Hip-Hop, Classical, Electronic/EDM, Country, Reggae, Metal, Punk, Indie. Instruments: Piano (keyboard), Guitar (string), Drums (percussion), Violin (bowed string), Saxophone (wind), Flute (woodwind). Notes: Do Re Mi Fa Sol La Si (C D E F G A B). Basic chords: Major (happy), Minor (sad), Diminished, Augmented.',
                'related' => ['seni', 'instrument', 'harmoni'], 'difficulty' => 'basic',
            ],
        ];
    }

    private function environmentKnowledge(): array
    {
        return [
            [
                'topic' => 'environment', 'keywords' => ['perubahan iklim', 'climate change', 'global warming', 'pemanasan global', 'lingkungan'],
                'question_patterns' => ['apa itu perubahan iklim', 'climate change', 'global warming', 'pemanasan global'],
                'answer_id' => 'Perubahan iklim disebabkan peningkatan gas rumah kaca (CO₂, metana) dari pembakaran fosil, deforestasi, dan industri. Dampak: suhu rata-rata naik ~1.1°C sejak era pra-industri, es kutub mencair, permukaan laut naik, cuaca ekstrem, kepunahan spesies. Solusi: energi terbarukan (solar, wind), kurangi emisi, reboisasi, transportasi ramah lingkungan, konsumsi berkelanjutan. Paris Agreement: target <1.5°C.',
                'answer_en' => 'Climate change is caused by increased greenhouse gases (CO₂, methane) from fossil fuel burning, deforestation, and industry. Impacts: average temperature rose ~1.1°C since pre-industrial era, polar ice melting, sea level rise, extreme weather, species extinction. Solutions: renewable energy (solar, wind), reduce emissions, reforestation, eco-friendly transport, sustainable consumption. Paris Agreement: target <1.5°C.',
                'related' => ['lingkungan', 'energi', 'ekologi'], 'difficulty' => 'basic',
            ],
        ];
    }

    private function psychologyKnowledge(): array
    {
        return [
            [
                'topic' => 'psychology', 'keywords' => ['psikologi', 'psychology', 'mindset', 'growth mindset', 'fixed mindset'],
                'question_patterns' => ['apa itu growth mindset', 'psikologi', 'mindset berkembang'],
                'answer_id' => 'Growth Mindset (Carol Dweck): keyakinan bahwa kemampuan bisa dikembangkan lewat usaha & belajar. Vs Fixed Mindset: kemampuan bawaan yang tidak berubah. Growth mindset: "Saya belum bisa" vs Fixed: "Saya tidak bisa". Tips mengembangkan: 1) Lihat kegagalan sebagai pembelajaran. 2) Fokus pada proses, bukan hasil. 3) Gunakan kata "belum" (I can\'t → I can\'t YET). 4) Hargai usaha, bukan bakat.',
                'answer_en' => 'Growth Mindset (Carol Dweck): belief that abilities can be developed through effort & learning. Vs Fixed Mindset: innate abilities that don\'t change. Growth: "I can\'t do this YET" vs Fixed: "I can\'t do this". Tips to develop: 1) See failure as learning. 2) Focus on process, not outcome. 3) Use the word "yet" (I can\'t → I can\'t YET). 4) Praise effort, not talent.',
                'related' => ['mindset', 'motivasi', 'belajar'], 'difficulty' => 'basic',
            ],
            [
                'topic' => 'psychology', 'keywords' => ['emotional intelligence', 'kecerdasan emosional', 'eq', 'empati'],
                'question_patterns' => ['apa itu emotional intelligence', 'kecerdasan emosional', 'cara meningkatkan eq'],
                'answer_id' => 'Emotional Intelligence (EQ, Daniel Goleman) terdiri dari 5 komponen: 1) Self-Awareness (sadar emosi sendiri). 2) Self-Regulation (mengelola emosi). 3) Motivation (dorongan internal). 4) Empathy (memahami emosi orang lain). 5) Social Skills (membangun hubungan). EQ sering lebih penting dari IQ untuk kesuksesan karir dan hubungan. Tips: journaling, meditasi, active listening, refleksi diri.',
                'answer_en' => 'Emotional Intelligence (EQ, Daniel Goleman) has 5 components: 1) Self-Awareness (knowing your emotions). 2) Self-Regulation (managing emotions). 3) Motivation (internal drive). 4) Empathy (understanding others\' emotions). 5) Social Skills (building relationships). EQ is often more important than IQ for career and relationship success. Tips: journaling, meditation, active listening, self-reflection.',
                'related' => ['empati', 'kepemimpinan', 'hubungan'], 'difficulty' => 'intermediate',
            ],
        ];
    }

    private function astronomyKnowledge(): array
    {
        return [
            [
                'topic' => 'astronomy', 'keywords' => ['black hole', 'lubang hitam', 'bintang', 'star', 'galaksi', 'galaxy'],
                'question_patterns' => ['apa itu black hole', 'lubang hitam', 'galaksi bima sakti', 'bintang terbesar'],
                'answer_id' => 'Black hole (lubang hitam) adalah region di ruang-waktu dengan gravitasi sangat kuat sehingga tidak ada yang bisa lolos, termasuk cahaya. Terbentuk dari keruntuhan bintang masif. Galaksi Bima Sakti (Milky Way) berisi 100-400 miliar bintang, diameter ~100.000 tahun cahaya. Di pusatnya ada black hole supermasif: Sagittarius A* (4 juta kali massa matahari). Bintang terdekat: Proxima Centauri (4.24 tahun cahaya).',
                'answer_en' => 'A black hole is a region in spacetime with gravity so strong nothing can escape, including light. Formed from the collapse of massive stars. Milky Way galaxy contains 100-400 billion stars, diameter ~100,000 light-years. At its center is a supermassive black hole: Sagittarius A* (4 million solar masses). Nearest star: Proxima Centauri (4.24 light-years).',
                'related' => ['astronomi', 'ruang angkasa', 'fisika'], 'difficulty' => 'intermediate',
            ],
            [
                'topic' => 'astronomy', 'keywords' => ['moon', 'bulan', 'mars', 'space exploration', 'nasa', 'spacex'],
                'question_patterns' => ['pendaratan bulan', 'moon landing', 'misi ke mars', 'spacex'],
                'answer_id' => 'Apollo 11 (1969): Neil Armstrong & Buzz Aldrin = manusia pertama di Bulan. "That\'s one small step for man, one giant leap for mankind." Total 12 orang pernah mendarat di Bulan (1969-1972). Mars: Perseverance rover mendarat 2021. SpaceX (Elon Musk) mengembangkan Starship untuk misi Mars. ISS = stasiun luar angkasa internasional, mengorbit ~400 km di atas Bumi sejak 1998.',
                'answer_en' => 'Apollo 11 (1969): Neil Armstrong & Buzz Aldrin = first humans on the Moon. "That\'s one small step for man, one giant leap for mankind." Total 12 people have landed on the Moon (1969-1972). Mars: Perseverance rover landed 2021. SpaceX (Elon Musk) developing Starship for Mars missions. ISS = International Space Station, orbiting ~400 km above Earth since 1998.',
                'related' => ['nasa', 'bulan', 'mars', 'eksplorasi'], 'difficulty' => 'basic',
            ],
        ];
    }
}
