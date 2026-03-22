<?php

namespace App\Services\Ai\Experts;

use App\Services\Ai\ExpertInterface;
use Illuminate\Support\Str;

class CodeAssistantExpert implements ExpertInterface
{
    private const CONFIDENCE_THRESHOLD = 30;

    private array $codeKeywords = [
        'code', 'kode', 'coding', 'program', 'programming', 'debug', 'error', 'bug',
        'function', 'fungsi', 'class', 'method', 'variable', 'variabel', 'syntax',
        'compile', 'runtime', 'exception', 'api', 'endpoint', 'database', 'query',
        'framework', 'library', 'package', 'module', 'import', 'install', 'setup',
        'contoh', 'example', 'cara', 'how to', 'buat', 'create', 'implement',
        'php', 'laravel', 'javascript', 'python', 'dart', 'flutter', 'react',
        'typescript', 'sql', 'html', 'css', 'node', 'express', 'django', 'vue',
        'angular', 'java', 'kotlin', 'swift', 'rust', 'go', 'golang', 'ruby',
        'c++', 'csharp', 'dotnet', 'spring', 'algorithm', 'algoritma',
        'pattern', 'design pattern', 'refactor', 'optimize', 'loop', 'array',
        'string', 'object', 'json', 'xml', 'regex', 'git', 'docker',
        'migration', 'model', 'controller', 'route', 'middleware', 'eloquent',
        'widget', 'stateful', 'stateless', 'provider', 'bloc', 'riverpod',
    ];

    public function evaluate(string $message, array $context): array
    {
        $msg = mb_strtolower(trim($message));
        $lang = $context['lang'] ?? 'id';
        $confidence = $this->scoreCodeIntent($msg);

        if ($confidence < self::CONFIDENCE_THRESHOLD) {
            return ['findings' => [], 'actions' => [], 'suggestions' => [], 'confidence' => 0];
        }

        $findings = [];
        $suggestions = [];

        $codeAnswer = $this->processCodeQuery($msg, $lang);
        if ($codeAnswer) {
            $findings[] = $codeAnswer;
        }

        $detectedLang = $this->detectProgrammingLanguage($msg);
        if ($detectedLang) {
            $suggestions[] = $lang === 'en'
                ? "More {$detectedLang} examples"
                : "Contoh {$detectedLang} lainnya";
        }

        $suggestions[] = $lang === 'en' ? 'Explain this code' : 'Jelaskan kode ini';
        $suggestions[] = $lang === 'en' ? 'Show design patterns' : 'Lihat design patterns';

        return [
            'findings' => $findings,
            'actions' => [],
            'suggestions' => array_slice($suggestions, 0, 3),
            'confidence' => $confidence,
        ];
    }

    private function scoreCodeIntent(string $msg): int
    {
        $score = 0;
        foreach ($this->codeKeywords as $kw) {
            if (str_contains($msg, $kw)) {
                $score += (mb_strlen($kw) >= 5) ? 15 : 8;
            }
        }

        if (preg_match('/\b(bagaimana|how|cara|gimana)\b.*\b(code|kode|buat|create|implement)\b/', $msg)) $score += 25;
        if (preg_match('/\b(contoh|example|sample)\b.*\b(code|kode|fungsi|function)\b/', $msg)) $score += 25;
        if (preg_match('/\b(error|bug|debug|fix|perbaiki)\b/', $msg)) $score += 20;
        if (preg_match('/\b(apa itu|what is|jelaskan|explain)\b.*\b(php|laravel|javascript|python|dart|flutter|react|sql|api|oop|mvc|solid)\b/', $msg)) $score += 30;

        return min(100, $score);
    }

    private function detectProgrammingLanguage(string $msg): ?string
    {
        $langMap = [
            'php' => 'PHP', 'laravel' => 'Laravel/PHP', 'eloquent' => 'Laravel/PHP',
            'javascript' => 'JavaScript', 'js' => 'JavaScript', 'nodejs' => 'Node.js', 'node' => 'Node.js',
            'typescript' => 'TypeScript', 'ts' => 'TypeScript',
            'python' => 'Python', 'django' => 'Python/Django', 'flask' => 'Python/Flask',
            'dart' => 'Dart', 'flutter' => 'Flutter/Dart',
            'java' => 'Java', 'kotlin' => 'Kotlin', 'swift' => 'Swift',
            'react' => 'React/JS', 'vue' => 'Vue.js', 'angular' => 'Angular',
            'sql' => 'SQL', 'mysql' => 'MySQL', 'postgresql' => 'PostgreSQL',
            'go' => 'Go', 'golang' => 'Go', 'rust' => 'Rust', 'ruby' => 'Ruby',
            'c++' => 'C++', 'csharp' => 'C#', 'c#' => 'C#',
            'html' => 'HTML', 'css' => 'CSS',
        ];

        foreach ($langMap as $kw => $name) {
            if (str_contains($msg, $kw)) return $name;
        }
        return null;
    }

    private function processCodeQuery(string $msg, string $lang): ?string
    {
        $snippets = $this->getSnippetLibrary();

        $bestMatch = null;
        $bestScore = 0;

        foreach ($snippets as $snippet) {
            $score = 0;
            foreach ($snippet['keywords'] as $kw) {
                if (str_contains($msg, mb_strtolower($kw))) {
                    $score += (mb_strlen($kw) >= 5) ? 20 : 10;
                }
            }
            foreach ($snippet['triggers'] ?? [] as $trigger) {
                if (str_contains($msg, mb_strtolower($trigger))) {
                    $score += 30;
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatch = $snippet;
            }
        }

        if (!$bestMatch || $bestScore < 20) return null;

        $answerKey = $lang === 'en' ? 'explanation_en' : 'explanation_id';
        $explanation = $bestMatch[$answerKey] ?? $bestMatch['explanation_id'];
        $code = $bestMatch['code'] ?? '';

        $result = $explanation;
        if (!empty($code)) {
            $codeLang = $bestMatch['language'] ?? 'text';
            $result .= "\n\n```{$codeLang}\n{$code}\n```";
        }

        return $result;
    }

    private function getSnippetLibrary(): array
    {
        return [
            // ── PHP / Laravel
            [
                'keywords' => ['laravel', 'crud', 'controller', 'resource'],
                'triggers' => ['buat crud', 'create crud', 'laravel crud', 'crud laravel'],
                'language' => 'php',
                'explanation_id' => '**CRUD Controller di Laravel:**',
                'explanation_en' => '**CRUD Controller in Laravel:**',
                'code' => 'class TaskController extends Controller
{
    public function index()
    {
        return Task::latest()->paginate(15);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            \'title\' => \'required|string|max:255\',
            \'description\' => \'nullable|string\',
            \'priority\' => \'in:high,medium,low\',
        ]);
        return Task::create($validated);
    }

    public function show(Task $task)
    {
        return $task->load(\'subtasks\', \'labels\');
    }

    public function update(Request $request, Task $task)
    {
        $task->update($request->validated());
        return $task->fresh();
    }

    public function destroy(Task $task)
    {
        $task->delete();
        return response()->noContent();
    }
}',
            ],
            [
                'keywords' => ['laravel', 'migration', 'database', 'tabel', 'table'],
                'triggers' => ['buat migration', 'create migration', 'buat tabel', 'create table'],
                'language' => 'php',
                'explanation_id' => '**Migration Database di Laravel:**',
                'explanation_en' => '**Database Migration in Laravel:**',
                'code' => 'Schema::create(\'tasks\', function (Blueprint $table) {
    $table->id();
    $table->string(\'title\');
    $table->text(\'description\')->nullable();
    $table->enum(\'priority\', [\'high\', \'medium\', \'low\'])->default(\'medium\');
    $table->boolean(\'is_completed\')->default(false);
    $table->timestamp(\'deadline\')->nullable();
    $table->foreignId(\'user_id\')->constrained()->cascadeOnDelete();
    $table->timestamps();
    $table->softDeletes();
    $table->index([\'user_id\', \'is_completed\']);
});',
            ],
            [
                'keywords' => ['validation', 'validasi', 'form request', 'validate'],
                'triggers' => ['validasi laravel', 'laravel validation', 'form request'],
                'language' => 'php',
                'explanation_id' => '**Validasi di Laravel dengan Form Request:**',
                'explanation_en' => '**Laravel Validation with Form Request:**',
                'code' => 'class StoreTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            \'title\' => [\'required\', \'string\', \'max:255\'],
            \'description\' => [\'nullable\', \'string\', \'max:5000\'],
            \'priority\' => [\'required\', Rule::in([\'high\', \'medium\', \'low\'])],
            \'deadline\' => [\'nullable\', \'date\', \'after:now\'],
            \'labels\' => [\'nullable\', \'array\'],
            \'labels.*\' => [\'exists:labels,id\'],
        ];
    }

    public function messages(): array
    {
        return [
            \'title.required\' => \'Judul tugas wajib diisi.\',
            \'deadline.after\' => \'Deadline harus di masa depan.\',
        ];
    }
}',
            ],
            // ── JavaScript
            [
                'keywords' => ['fetch', 'api', 'javascript', 'http', 'request'],
                'triggers' => ['fetch api', 'javascript fetch', 'panggil api', 'call api js'],
                'language' => 'javascript',
                'explanation_id' => '**Fetch API di JavaScript (async/await):**',
                'explanation_en' => '**JavaScript Fetch API (async/await):**',
                'code' => 'const apiRequest = async (url, method = \'GET\', body = null) => {
  try {
    const options = {
      method,
      headers: {
        \'Content-Type\': \'application/json\',
        \'Authorization\': `Bearer ${getToken()}`,
      },
    };

    if (body && method !== \'GET\') {
      options.body = JSON.stringify(body);
    }

    const response = await fetch(url, options);

    if (!response.ok) {
      throw new Error(`HTTP ${response.status}: ${response.statusText}`);
    }

    return await response.json();
  } catch (error) {
    console.error(\'API Error:\', error.message);
    throw error;
  }
};

// Usage
const tasks = await apiRequest(\'/api/tasks\');
const newTask = await apiRequest(\'/api/tasks\', \'POST\', {
  title: \'New Task\',
  priority: \'high\',
});',
            ],
            [
                'keywords' => ['array', 'method', 'map', 'filter', 'reduce', 'javascript'],
                'triggers' => ['array methods', 'method array', 'map filter reduce'],
                'language' => 'javascript',
                'explanation_id' => '**Array Methods JavaScript (map, filter, reduce, dll):**',
                'explanation_en' => '**JavaScript Array Methods (map, filter, reduce, etc):**',
                'code' => 'const tasks = [
  { id: 1, title: \'Code\', done: false, priority: \'high\' },
  { id: 2, title: \'Test\', done: true, priority: \'medium\' },
  { id: 3, title: \'Deploy\', done: false, priority: \'high\' },
];

// filter: get incomplete tasks
const pending = tasks.filter(t => !t.done);

// map: extract titles
const titles = tasks.map(t => t.title);

// find: first high priority
const urgent = tasks.find(t => t.priority === \'high\');

// some/every: check conditions
const hasCompleted = tasks.some(t => t.done);       // true
const allDone = tasks.every(t => t.done);            // false

// reduce: count by priority
const counts = tasks.reduce((acc, t) => {
  acc[t.priority] = (acc[t.priority] || 0) + 1;
  return acc;
}, {});  // { high: 2, medium: 1 }

// sort: by priority (high first)
const order = { high: 0, medium: 1, low: 2 };
const sorted = [...tasks].sort((a, b) =>
  order[a.priority] - order[b.priority]
);',
            ],
            // ── Python
            [
                'keywords' => ['python', 'class', 'oop', 'object'],
                'triggers' => ['class python', 'oop python', 'buat class python'],
                'language' => 'python',
                'explanation_id' => '**Class & OOP di Python:**',
                'explanation_en' => '**Class & OOP in Python:**',
                'code' => 'from dataclasses import dataclass
from datetime import datetime
from typing import Optional

@dataclass
class Task:
    title: str
    priority: str = "medium"
    deadline: Optional[datetime] = None
    is_completed: bool = False

    def complete(self):
        self.is_completed = True

    def is_overdue(self) -> bool:
        if not self.deadline:
            return False
        return not self.is_completed and datetime.now() > self.deadline

    def __str__(self):
        status = "Done" if self.is_completed else "Pending"
        return f"[{status}] {self.title} ({self.priority})"

class TaskManager:
    def __init__(self):
        self._tasks: list[Task] = []

    def add(self, task: Task):
        self._tasks.append(task)

    def get_overdue(self) -> list[Task]:
        return [t for t in self._tasks if t.is_overdue()]

    def stats(self) -> dict:
        total = len(self._tasks)
        done = sum(1 for t in self._tasks if t.is_completed)
        return {"total": total, "done": done, "pending": total - done}',
            ],
            // ── Dart / Flutter
            [
                'keywords' => ['flutter', 'widget', 'stateful', 'stateless', 'build'],
                'triggers' => ['flutter widget', 'buat widget', 'stateful widget', 'create widget flutter'],
                'language' => 'dart',
                'explanation_id' => '**Widget Flutter (Stateless + Stateful):**',
                'explanation_en' => '**Flutter Widget (Stateless + Stateful):**',
                'code' => 'class TaskCard extends StatelessWidget {
  final String title;
  final String priority;
  final bool isCompleted;
  final VoidCallback onToggle;

  const TaskCard({
    super.key,
    required this.title,
    required this.priority,
    required this.isCompleted,
    required this.onToggle,
  });

  @override
  Widget build(BuildContext context) {
    return Card(
      child: ListTile(
        leading: Checkbox(
          value: isCompleted,
          onChanged: (_) => onToggle(),
        ),
        title: Text(
          title,
          style: TextStyle(
            decoration: isCompleted
                ? TextDecoration.lineThrough
                : TextDecoration.none,
          ),
        ),
        trailing: _priorityBadge(),
      ),
    );
  }

  Widget _priorityBadge() {
    final color = switch (priority) {
      \'high\' => Colors.red,
      \'medium\' => Colors.orange,
      _ => Colors.green,
    };
    return Chip(
      label: Text(priority.toUpperCase()),
      backgroundColor: color.withOpacity(0.2),
    );
  }
}',
            ],
            [
                'keywords' => ['flutter', 'http', 'dio', 'api', 'fetch', 'request'],
                'triggers' => ['flutter http', 'dio flutter', 'flutter api call', 'panggil api flutter'],
                'language' => 'dart',
                'explanation_id' => '**HTTP Request di Flutter dengan Dio:**',
                'explanation_en' => '**HTTP Request in Flutter with Dio:**',
                'code' => 'class ApiService {
  final Dio _dio = Dio(BaseOptions(
    baseUrl: \'https://api.example.com\',
    connectTimeout: const Duration(seconds: 10),
    headers: {\'Content-Type\': \'application/json\'},
  ));

  Future<List<Task>> fetchTasks() async {
    try {
      final response = await _dio.get(\'/tasks\');
      final List data = response.data;
      return data.map((json) => Task.fromJson(json)).toList();
    } on DioException catch (e) {
      throw ApiException(
        e.response?.data[\'message\'] ?? \'Network error\',
        e.response?.statusCode,
      );
    }
  }

  Future<Task> createTask(Map<String, dynamic> payload) async {
    final response = await _dio.post(\'/tasks\', data: payload);
    return Task.fromJson(response.data);
  }
}',
            ],
            // ── SQL
            [
                'keywords' => ['sql', 'join', 'query', 'select', 'database'],
                'triggers' => ['sql join', 'cara join', 'query sql', 'complex query'],
                'language' => 'sql',
                'explanation_id' => '**Query SQL Kompleks (JOIN, GROUP BY, Subquery):**',
                'explanation_en' => '**Complex SQL Query (JOIN, GROUP BY, Subquery):**',
                'code' => '-- Analisis produktivitas per user
SELECT
    u.name,
    COUNT(t.id) AS total_tasks,
    SUM(CASE WHEN t.is_completed THEN 1 ELSE 0 END) AS completed,
    ROUND(
        SUM(CASE WHEN t.is_completed THEN 1 ELSE 0 END) * 100.0
        / NULLIF(COUNT(t.id), 0), 1
    ) AS completion_rate,
    COUNT(CASE
        WHEN NOT t.is_completed AND t.deadline < NOW()
        THEN 1
    END) AS overdue_count
FROM users u
LEFT JOIN tasks t ON u.id = t.user_id
WHERE u.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY u.id, u.name
HAVING COUNT(t.id) > 0
ORDER BY completion_rate DESC
LIMIT 20;',
            ],
            // ── Git
            [
                'keywords' => ['git', 'branch', 'merge', 'commit', 'rebase'],
                'triggers' => ['git workflow', 'git commands', 'perintah git', 'cara git'],
                'language' => 'bash',
                'explanation_id' => '**Git Workflow Commands:**',
                'explanation_en' => '**Git Workflow Commands:**',
                'code' => '# Feature branch workflow
git checkout -b feature/new-feature main
git add .
git commit -m "feat: add new feature"
git push -u origin feature/new-feature

# Rebase before merge (clean history)
git fetch origin
git rebase origin/main
git push --force-with-lease

# Stash changes temporarily
git stash save "WIP: working on feature"
git stash pop

# Undo last commit (keep changes)
git reset --soft HEAD~1

# Interactive rebase (squash commits)
git rebase -i HEAD~3

# Cherry-pick specific commit
git cherry-pick abc1234

# View file history
git log --follow -p -- path/to/file.php',
            ],
            // ── Design Patterns
            [
                'keywords' => ['repository', 'pattern', 'design', 'arsitektur'],
                'triggers' => ['repository pattern', 'design pattern laravel', 'pola repository'],
                'language' => 'php',
                'explanation_id' => '**Repository Pattern di Laravel:**',
                'explanation_en' => '**Repository Pattern in Laravel:**',
                'code' => 'interface TaskRepositoryInterface
{
    public function all(): Collection;
    public function find(int $id): ?Task;
    public function create(array $data): Task;
    public function update(int $id, array $data): Task;
    public function delete(int $id): bool;
}

class TaskRepository implements TaskRepositoryInterface
{
    public function all(): Collection
    {
        return Task::with(\'labels\')
            ->orderByRaw("FIELD(priority, \'high\', \'medium\', \'low\')")
            ->latest()
            ->get();
    }

    public function find(int $id): ?Task
    {
        return Task::with(\'labels\', \'subtasks\')->find($id);
    }

    public function create(array $data): Task
    {
        return DB::transaction(function () use ($data) {
            $task = Task::create($data);
            if (!empty($data[\'labels\'])) {
                $task->labels()->sync($data[\'labels\']);
            }
            return $task->fresh(\'labels\');
        });
    }

    public function update(int $id, array $data): Task
    {
        $task = $this->find($id);
        $task->update($data);
        return $task->fresh();
    }

    public function delete(int $id): bool
    {
        return Task::destroy($id) > 0;
    }
}

// Register in AppServiceProvider
$this->app->bind(TaskRepositoryInterface::class, TaskRepository::class);',
            ],
            // ── Error Handling
            [
                'keywords' => ['error', 'exception', 'try', 'catch', 'handling'],
                'triggers' => ['error handling', 'try catch', 'handle error', 'exception handling'],
                'language' => 'php',
                'explanation_id' => '**Error Handling yang Benar:**',
                'explanation_en' => '**Proper Error Handling:**',
                'code' => '// PHP/Laravel
try {
    $result = $this->riskyOperation();
} catch (ValidationException $e) {
    return response()->json([
        \'message\' => \'Validation failed\',
        \'errors\' => $e->errors(),
    ], 422);
} catch (ModelNotFoundException $e) {
    return response()->json([
        \'message\' => \'Resource not found\',
    ], 404);
} catch (\Exception $e) {
    Log::error(\'Unexpected error\', [
        \'message\' => $e->getMessage(),
        \'trace\' => $e->getTraceAsString(),
    ]);
    return response()->json([
        \'message\' => \'Internal server error\',
    ], 500);
}',
            ],
            // ── Authentication
            [
                'keywords' => ['auth', 'login', 'register', 'token', 'sanctum', 'jwt', 'authentication'],
                'triggers' => ['auth laravel', 'login api', 'sanctum auth', 'authentication'],
                'language' => 'php',
                'explanation_id' => '**Authentication API dengan Laravel Sanctum:**',
                'explanation_en' => '**API Authentication with Laravel Sanctum:**',
                'code' => 'class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validated = $request->validate([
            \'name\' => \'required|string|max:255\',
            \'email\' => \'required|email|unique:users\',
            \'password\' => \'required|min:8|confirmed\',
        ]);

        $user = User::create([
            \'name\' => $validated[\'name\'],
            \'email\' => $validated[\'email\'],
            \'password\' => Hash::make($validated[\'password\']),
        ]);

        $token = $user->createToken(\'auth_token\')->plainTextToken;
        return response()->json([\'user\' => $user, \'token\' => $token], 201);
    }

    public function login(Request $request)
    {
        if (!Auth::attempt($request->only(\'email\', \'password\'))) {
            throw ValidationException::withMessages([
                \'email\' => [\'Invalid credentials.\'],
            ]);
        }

        $token = $request->user()->createToken(\'auth_token\')->plainTextToken;
        return response()->json([\'user\' => Auth::user(), \'token\' => $token]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json([\'message\' => \'Logged out\']);
    }
}',
            ],
            // ── React Hooks
            [
                'keywords' => ['react', 'hooks', 'usestate', 'useeffect', 'component'],
                'triggers' => ['react hooks', 'usestate', 'useeffect', 'react component'],
                'language' => 'tsx',
                'explanation_id' => '**React Hooks (useState, useEffect, custom hook):**',
                'explanation_en' => '**React Hooks (useState, useEffect, custom hook):**',
                'code' => 'function useTasks() {
  const [tasks, setTasks] = useState<Task[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const fetchTasks = async () => {
      try {
        const response = await fetch(\'/api/tasks\');
        const data = await response.json();
        setTasks(data);
      } catch (err) {
        setError(\'Failed to load tasks\');
      } finally {
        setLoading(false);
      }
    };
    fetchTasks();
  }, []);

  const addTask = async (title: string) => {
    const response = await fetch(\'/api/tasks\', {
      method: \'POST\',
      headers: { \'Content-Type\': \'application/json\' },
      body: JSON.stringify({ title }),
    });
    const newTask = await response.json();
    setTasks(prev => [newTask, ...prev]);
  };

  return { tasks, loading, error, addTask };
}

function TaskList() {
  const { tasks, loading, error, addTask } = useTasks();

  if (loading) return <Spinner />;
  if (error) return <ErrorMessage message={error} />;

  return (
    <ul>
      {tasks.map(task => (
        <TaskItem key={task.id} task={task} />
      ))}
    </ul>
  );
}',
            ],
            // ── Docker
            [
                'keywords' => ['docker', 'dockerfile', 'container', 'compose', 'docker-compose'],
                'triggers' => ['docker setup', 'dockerfile', 'docker compose', 'containerize'],
                'language' => 'dockerfile',
                'explanation_id' => '**Dockerfile + Docker Compose untuk Laravel:**',
                'explanation_en' => '**Dockerfile + Docker Compose for Laravel:**',
                'code' => '# Dockerfile
FROM php:8.2-fpm-alpine

RUN apk add --no-cache postgresql-dev \\
    && docker-php-ext-install pdo pdo_pgsql opcache

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY . .
RUN composer install --no-dev --optimize-autoloader
RUN php artisan config:cache && php artisan route:cache
EXPOSE 9000
CMD ["php-fpm"]

# docker-compose.yml
# services:
#   app:
#     build: .
#     volumes: [".:/var/www/html"]
#     depends_on: [db, redis]
#   db:
#     image: postgres:15
#     environment:
#       POSTGRES_DB: pdbl
#       POSTGRES_USER: pdbl
#       POSTGRES_PASSWORD: secret
#   redis:
#     image: redis:alpine
#   nginx:
#     image: nginx:alpine
#     ports: ["80:80"]',
            ],
            // ── Regex
            [
                'keywords' => ['regex', 'regular expression', 'preg_match', 'pattern matching'],
                'triggers' => ['regex', 'regular expression', 'preg_match', 'pattern regex'],
                'language' => 'php',
                'explanation_id' => '**Regular Expression (Regex) Cheatsheet:**',
                'explanation_en' => '**Regular Expression (Regex) Cheatsheet:**',
                'code' => '// Common patterns
$email = \'/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/\';
$phone = \'/^(\+62|62|0)8[1-9][0-9]{6,11}$/\';  // Indonesian phone
$url   = \'/^https?:\\/\\/[\\w\\-]+(\\.[\\w\\-]+)+[/#?]?.*$/\';
$date  = \'/^\\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12]\\d|3[01])$/\';
$slug  = \'/^[a-z0-9]+(?:-[a-z0-9]+)*$/\';

// Extraction
preg_match(\'/price[:\\s]*\\$?([\\d,]+\\.?\\d*)/i\', $text, $matches);
$price = $matches[1] ?? null;

// Replace
$clean = preg_replace(\'/[^\\w\\s]/u\', \'\', $input);  // remove special chars
$slug  = preg_replace(\'/\\s+/\', \'-\', strtolower($text)); // slugify

// Split
$words = preg_split(\'/[\\s,;]+/\', $sentence);

// Modifiers: i=case-insensitive, m=multiline, s=dotall, u=unicode',
            ],
        ];
    }
}
