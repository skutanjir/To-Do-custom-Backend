<?php

namespace App\Services;

use App\Models\Todo;
use App\Services\AiMemoryService;
use App\Services\Ai\LinguisticService;
use App\Services\Ai\IntentManager;
use App\Services\Ai\CognitiveExpertManager;
use App\Services\Ai\KnowledgeBaseService;
use App\Services\Ai\SemanticThesaurusModule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ╔══════════════════════════════════════════════════════════════════════╗
 * ║            L O C A L   A I   E N G I N E   v13.0                   ║
 * ║      G P U   A C C E L E R A T E D   (N V I D I A   R T X)         ║
 * ║                                                                      ║
 * ║   v13.0 New Features:                                                ║
 * ║   - RTX GPU Acceleration (CUDA 13.0)                                 ║
 * ║   - Semantic Task Search (Vector Embeddings)                         ║
 * ║   - Domain-Restricted AI Web Search (Todo Only)                      ║
 * ╚══════════════════════════════════════════════════════════════════════╝
 */
class LocalAiEngine
{
    public const VERSION = '13.0.0-GPU';

    // Bridge to local GPU service (Python Sidecar)
    private string $gpuEndpoint = 'http://127.0.0.1:8080';

    private $todos;
    private ?string $userName;
    private Carbon $now;
    private string $lang;      // 'id' | 'en' | 'jv' | 'su' | 'bt'
    private array  $context;   // short-term conversation context
    private ?AiMemoryService $memory;  // Supabase-backed persistent memory
    private array $personality = [];    // user personality profile
    private string $computeDevice = 'cpu';

    private CognitiveExpertManager $expertManager;
    private KnowledgeBaseService $knowledgeBase;
    private bool $voiceMode = false;
    private array $communicationStyle = [];  
    private array $thoughtChain = [];        

    // ── Intent confidence threshold (0-100). Below this → fallback to external AI
    private const CONFIDENCE_THRESHOLD = 35;

    // ── Levenshtein tolerance for typo correction (max edit distance)
    private const TYPO_TOLERANCE = 3;

    public function __construct($todos = null, ?string $userName = null, array $context = [], ?AiMemoryService $memory = null, bool $voiceMode = false)
    {
        $this->todos     = $todos ?? collect([]);
        $this->memory    = $memory;
        $this->now       = Carbon::now();
        $this->lang      = 'id';
        $this->context   = $context; 
        $this->voiceMode = $voiceMode;
        
        $this->expertManager = app(CognitiveExpertManager::class);
        $this->expertManager->register(new \App\Services\Ai\Experts\DeadlineExpert());
        $this->expertManager->register(new \App\Services\Ai\Experts\PriorityExpert());
        $this->expertManager->register(new \App\Services\Ai\Experts\HabitExpert());
        $this->expertManager->register(new \App\Services\Ai\Experts\ProductivityForecastExpert());
        $this->expertManager->register(new \App\Services\Ai\Experts\MentalLoadExpert());
        $this->expertManager->register(new \App\Services\Ai\Experts\TimeAwareExpert());
        $this->expertManager->register(new \App\Services\Ai\Experts\LanguageSwitchExpert());
        $this->expertManager->register(new \App\Services\Ai\Experts\HabitEvolutionExpert());
        $this->expertManager->register(new \App\Services\Ai\Experts\VirtualExpertRegistry());
        $this->expertManager->register(new \App\Services\Ai\Experts\CodeAssistantExpert());
        $this->expertManager->register(new \App\Services\Ai\Experts\ConversationalExpert());
        $this->expertManager->register(new \App\Services\Ai\Experts\MathScienceExpert());
        $this->expertManager->register(new \App\Services\Ai\Experts\CreativeWritingExpert());
        $this->expertManager->register(new \App\Services\Ai\Experts\LifeCoachExpert());
        $this->expertManager->register(new \App\Services\Ai\Experts\TranslationExpert());
        $this->expertManager->register(new \App\Services\Ai\Experts\StrategicPlannerExpert());
        $this->expertManager->register(new \App\Services\Ai\Experts\HealthVitalityExpert());
        $this->expertManager->register(new \App\Services\Ai\Experts\SystemIntegrityExpert());
        $this->expertManager->register(new \App\Services\Ai\Experts\ResearchExpert());

        $this->knowledgeBase = new KnowledgeBaseService();
        
        $this->checkGpuAvailability();

        // Load personality from memory
        if ($memory) {
            $this->personality = $memory->getPersonality();
            $this->userName = $this->personality['nickname'] ?? $userName ?? 'Tuan';
            $savedLang = $this->personality['preferred_lang'] ?? null;
            if ($savedLang && in_array($savedLang, ['id', 'en', 'jv', 'su', 'bt'])) {
                $this->lang = $savedLang;
            }
        } else {
            $this->userName = $userName ?? 'Tuan';
        }
    }

    private function checkGpuAvailability(): void
    {
        try {
            $response = Http::timeout(1)->get("{$this->gpuEndpoint}/status");
            if ($response->successful()) {
                $data = $response->json();
                if (($data['device'] ?? '') === 'cuda') {
                    $this->computeDevice = 'gpu';
                }
            }
        } catch (\Exception $e) {
            $this->computeDevice = 'cpu';
        }
    }

    public function getExpertManager(): CognitiveExpertManager
    {
        return $this->expertManager;
    }

    // ═══════════════════════════════════════════════════════════════
    // MAIN DISPATCHER — Confidence-scored intent ranking
    // ═══════════════════════════════════════════════════════════════

    public function handle(string $message): ?array
    {
        /** @var LinguisticService $linguistic */
        $linguistic = app(LinguisticService::class);
        /** @var IntentManager $manager */
        $manager = app(IntentManager::class);

        $msg        = $linguistic->normalize($message);
        $this->lang = $linguistic->detectLanguage($msg, $message);

        // ── v12.1: Input validation — reject emoji-only or meaningless input ─
        $stripped = preg_replace('/[\x{1F300}-\x{1F9FF}\x{2600}-\x{27BF}\x{FE00}-\x{FE0F}\x{200D}\x{20E3}\x{E0020}-\x{E007F}\x{1F1E0}-\x{1F1FF}]/u', '', $message);
        $stripped = trim(preg_replace('/\s+/', '', $stripped));
        if (mb_strlen($stripped) < 2) {
            return $this->respond($this->lang === 'en'
                ? "I didn't quite catch that. Could you type a clearer command? 😊"
                : "Maaf, saya tidak memahami input tersebut. Coba ketik perintah yang lebih jelas? 😊",
                null, ['help', 'lihat tugas', 'buat tugas']
            );
        }

        // ── Resolve context-dependent references ────────────────────
        $msg = $this->resolveContextualRefs($msg, $message);

        // ── v12.0: Track Communication Style ────────────────────────
        $this->trackCommunicationStyle($message);

        // ── v12.0: Multi-Intent Detection ───────────────────────────
        $subCommands = $this->detectMultiIntent($msg, $message);
        if (count($subCommands) > 1) {
            return $this->processMultiIntent($subCommands, $message);
        }

        // ── Conversational Flow (Task Creation Wizard) ──────────────
        $flow = $this->handleConversationalFlow($msg, $message);
        if ($flow !== null) {
            $decoded = json_decode($flow['content'] ?? '{}', true);
            $decoded['quickReplies'] = array_unique(array_merge(
                $decoded['quickReplies'] ?? [],
                ['batal']
            ));
            $flow['content'] = json_encode($decoded);
            return $flow;
        }

        // ── Load Memory Context (Facts & Previous Patterns) ──────────
        $facts = $this->memory ? $this->memory->getAllFacts() : [];
        $memContext = [
            'facts' => collect($facts)->pluck('value.data', 'key')->toArray(),
            'personality' => $this->personality,
        ];

        // ── Build User Context for Reasoning (HARDENED) ─────────────
        $context = [
            'user'  => $this->userName,
            'lang'  => $this->lang,
            'tasks' => collect($this->todos)->map(function($t) {
                // Enterprise Hardening: ONLY allow safe fields in the reasoning context
                $safe = [
                    'id', 'judul', 'deskripsi', 'deadline', 
                    'priority', 'is_completed', 'status_id'
                ];
                $data = is_object($t) && method_exists($t, 'toArray') ? $t->toArray() : (array)$t;
                return array_intersect_key($data, array_flip($safe));
            })->toArray(),
            'time'    => $this->now->toIso8601String(),
            'device'  => $this->computeDevice,
            'history' => $this->context, // last N turns
            'memory'  => $memContext,
        ];

        // ── Custom Reasoning Loop (v9.0) ────────────────────────────
        $reasoning = $this->expertManager->reason($msg, $context);

        // ── v12.0: Build Chain-of-Thought for complex queries ───────
        $chainOfThought = $this->buildChainOfThought($msg, $context, $reasoning);

        // ── Build scored intent map (v12.0 Semantic Weighted) ───────
        $intents = $this->scoreIntents($msg, $message);
        arsort($intents);

        // Dispatch top intent above threshold
        foreach ($intents as $intent => $confidence) {
            if ($confidence < self::CONFIDENCE_THRESHOLD) break;
            $result = $this->dispatchIntent($intent, $msg, $message, $confidence);
            if ($result !== null) {
                // ── Enrichment Layer (v12.0 Enhanced) ───────────────
                $decoded = json_decode($result['content'] ?? '{}', true);

                // Adapt response tone to user's style
                if (!empty($decoded['message'])) {
                    $decoded['message'] = $this->adaptResponseTone($decoded['message']);
                }

                if (!empty($reasoning['findings']) && !$this->voiceMode) {
                    $findingText = "\n\n💡 Insights: " . implode(" ", array_slice($reasoning['findings'], 0, 2));
                    $decoded['message'] .= $findingText;
                }
                $decoded['quickReplies'] = array_slice(array_unique(array_merge(
                    $decoded['quickReplies'] ?? [],
                    $reasoning['suggestions'] ?? [],
                    ['focus_mode', 'daily_planner']
                )), 0, 3);

                // v12.0: Inject chain-of-thought for analytical intents
                $analyticalIntents = ['stats', 'productivity', 'weekly_review', 'workload', 'task_analysis', 'eisenhower', 'daily_planner', 'smart_prioritize', 'smart_suggest'];
                if (in_array($intent, $analyticalIntents) && !$this->voiceMode) {
                    $decoded['chain_of_thought'] = $chainOfThought;
                }

                $result['content'] = json_encode($decoded);
                return $result;
            }
        }

        // ── Delegate to Enterprise Intent Manager ──────────────────
        $enterpriseResult = $manager->handle($msg, $context);
        if ($enterpriseResult && ($enterpriseResult['intent'] ?? '') !== 'unknown') {
            $decoded = json_decode($enterpriseResult['content'] ?? '{}', true);
            $decoded['quickReplies'] = array_slice(array_unique(array_merge(
                $decoded['quickReplies'] ?? [],
                $reasoning['suggestions'] ?? []
            )), 0, 3);
            $enterpriseResult['content'] = json_encode($decoded);
            return $enterpriseResult;
        }

        // ── 3. Expert Findings Fallback (v10.0 Enhanced) ─────────────
        if (!empty($reasoning['findings']) && ($reasoning['confidence'] ?? 0) >= 25) {
            return $this->respond(
                implode("\n\n", $reasoning['findings']),
                null,
                $reasoning['suggestions'] ?? []
            );
        }

        // ── v12.1: Self-Correction Suggestion Layer ──────────────────
        reset($intents);
        $bestIntent = key($intents);
        $bestScore  = current($intents);
        
        if ($bestScore >= 15 && $bestIntent !== 'unknown' && $bestIntent !== 'general_chat') {
            $suggestion = match($bestIntent) {
                'create' => $this->t("Apakah Anda ingin membuat tugas baru?", "Did you mean you want to create a new task?"),
                'delete' => $this->t("Apakah Anda ingin menghapus tugas?", "Did you mean you want to delete a task?"),
                'list'   => $this->t("Apakah Anda ingin melihat daftar tugas?", "Did you mean you want to see your task list?"),
                'search' => $this->t("Apakah Anda ingin mencari tugas?", "Did you mean you want to search for a task?"),
                'stats'  => $this->t("Apakah Anda ingin melihat statistik?", "Did you mean you want to see your statistics?"),
                default  => null
            };
            
            if ($suggestion) {
                return $this->respond(
                    $this->t("Saya kurang yakin, namun: $suggestion", "I'm not entirely sure, but: $suggestion"),
                    null,
                    [$this->t("Ya", "Yes"), $this->t("Tidak", "No")]
                );
            }
        }

        // ── 4. Conversational Fallback ─────────────────────────────
        return $this->handleGeneralConversationalChat($message, $context);
    }

    /**
     * Build response from expert findings (Ground-Up Custom Logic).
     */
    private function buildExpertResponse(array $reasoning): array
    {
        $message = $this->t(
            "Hasil analisa saya: " . implode(" ", $reasoning['findings']),
            "My analysis findings: " . implode(" ", $reasoning['findings'])
        );

        if (!empty($reasoning['suggestions'])) {
            $message .= "\n\n" . $this->t("Saran: ", "Suggestion: ") . $reasoning['suggestions'][0];
        }

        return $this->respond(
            $message,
            null,
            array_slice($reasoning['suggestions'], 0, 3) ?: $this->getDefaultQuickReplies()
        );
    }

    /**
     * Get default quick replies for the user.
     */
    private function getDefaultQuickReplies(): array
    {
        return [
            $this->t('Apa jadwal saya hari ini?', 'What is my schedule today?'),
            $this->t('Buat tugas baru', 'Create a new task'),
            $this->t('Tampilkan statistik', 'Show statistics'),
        ];
    }

    // ═══════════════════════════════════════════════════════════════
    // INTENT SCORING ENGINE
    // ═══════════════════════════════════════════════════════════════

    private function scoreIntents(string $msg, string $original): array
    {
        $scores = [];

        // ── Memory intents (highest priority — user managing AI memory) ──
        $scores['remember_preference'] = $this->scoreRememberPreference($msg);
        $scores['recall_memory']       = $this->scoreRecallMemory($msg);
        $scores['forget_memory']       = $this->scoreForgetMemory($msg);
        $scores['conversation_summary']= $this->scoreConversationSummary($msg);
        $scores['memory_stats']        = $this->scoreMemoryStats($msg);

        // ── Greetings ─────────────────────────────────────────────
        $scores['greeting']          = $this->scoreGreeting($msg);

        // ── CRUD ──────────────────────────────────────────────────
        $scores['multi_create']      = $this->scoreMultiCreate($msg);
        $scores['create']            = $this->scoreCreate($msg);
        $scores['update_deadline']   = $this->scoreUpdateDeadline($msg);
        $scores['update_priority']   = $this->scoreUpdatePriority($msg);
        $scores['rename']            = $this->scoreRename($msg);
        $scores['toggle']            = $this->scoreToggle($msg);
        $scores['delete']            = $this->scoreDelete($msg);
        $scores['bulk_op']           = $this->scoreBulkOp($msg);
        $scores['batch_update']      = $this->scoreBatchUpdate($msg);

        // ── Viewing ───────────────────────────────────────────────
        $scores['filtered_list']     = $this->scoreFilteredList($msg);
        $scores['list']              = $this->scoreList($msg);
        $scores['search']            = $this->scoreSearch($msg);
        $scores['schedule']          = $this->scoreSchedule($msg);

        // ── Analytics ─────────────────────────────────────────────
        $scores['stats']             = $this->scoreStats($msg);
        $scores['productivity']      = $this->scoreProductivity($msg);
        $scores['weekly_review']     = $this->scoreWeeklyReview($msg);
        $scores['workload']          = $this->scoreWorkload($msg);
        $scores['task_analysis']     = $this->scoreTaskAnalysis($msg);
        $scores['eisenhower']        = $this->scoreEisenhower($msg);
        $scores['streak']            = $this->scoreStreak($msg);

        // ── Planning ──────────────────────────────────────────────
        $scores['focus_mode']        = $this->scoreFocusMode($msg);
        $scores['daily_planner']     = $this->scoreDailyPlanner($msg);
        $scores['deep_work']         = $this->scoreDeepWork($msg);
        $scores['pomodoro']          = $this->scorePomodoro($msg);

        // ── Smart Features ────────────────────────────────────────
        $scores['smart_suggest']     = $this->scoreSmartSuggest($msg);
        $scores['smart_prioritize']  = $this->scoreSmartPrioritize($msg);
        $scores['habit']             = $this->scoreHabit($msg);
        $scores['template']          = $this->scoreTemplate($msg);
        $scores['reminder']          = $this->scoreReminder($msg);
        $scores['reschedule_overdue']= $this->scoreRescheduleOverdue($msg);

        // ── Goals ─────────────────────────────────────────────────
        $scores['set_goal']          = $this->scoreSetGoal($msg);
        $scores['check_goal']        = $this->scoreCheckGoal($msg);

        // ── Misc ──────────────────────────────────────────────────
        $scores['identity']          = $this->scoreIdentity($msg);
        $scores['help']              = $this->scoreHelp($msg);
        $scores['motivation']        = $this->scoreMotivation($msg);
        $scores['emotion']           = $this->scoreEmotion($msg);
        $scores['general_chat']      = $this->scoreGeneralChat($msg);

        // ── Memory intents (additional) ─────────────────────────────
        $scores['teach_ai']            = $this->scoreTeachAi($msg);
        $scores['switch_language']      = $this->scoreSwitchLanguage($msg);

        // ── Learned patterns from memory (boost matching intents) ─
        $scores = $this->applyLearnedPatterns($msg, $scores);

        // ── v12.0: Semantic Weighted Confidence Boosting ─────────────
        // Apply position-weighted and context-history boosting on top of legacy scores
        $scores = $this->applySemanticWeighting($msg, $scores);

        return $scores;
    }

    /**
     * v12.0: Apply semantic weighting to all scored intents.
     * Boosts scores based on keyword position, word boundaries, and conversation context.
     */
    private function applySemanticWeighting(string $msg, array $scores): array
    {
        // Intent → primary keywords map for semantic boosting
        $intentKeywords = [
            'create'          => ['buat', 'bikin', 'tambah', 'create', 'add', 'make', 'new'],
            'delete'          => ['hapus', 'delete', 'remove', 'buang', 'hilangkan'],
            'toggle'          => ['selesai', 'done', 'complete', 'tandai', 'mark', 'toggle', 'finish'],
            'list'            => ['lihat', 'list', 'show', 'tampilkan', 'daftar', 'semua'],
            'search'          => ['cari', 'search', 'find', 'temukan', 'mana'],
            'stats'           => ['statistik', 'stats', 'laporan', 'report', 'rekap'],
            'schedule'        => ['jadwal', 'schedule', 'agenda', 'hari ini', 'today'],
            'daily_planner'   => ['rencana', 'plan', 'planner', 'planning'],
            'productivity'    => ['produktivitas', 'productivity', 'kinerja', 'performance'],
            'focus_mode'      => ['fokus', 'focus', 'konsentrasi', 'concentrate'],
            'motivation'      => ['motivasi', 'motivation', 'semangat', 'inspire'],
            'help'            => ['bantuan', 'help', 'bisa apa', 'fitur', 'features'],
            'identity'        => ['siapa kamu', 'who are you', 'nama kamu', 'your name'],
            'greeting'        => ['halo', 'hai', 'hello', 'hi', 'hey', 'selamat'],
            'eisenhower'      => ['eisenhower', 'matrix', 'urgent', 'important', 'matriks'],
            'workload'        => ['beban', 'workload', 'overload', 'terlalu banyak'],
            'habit'           => ['kebiasaan', 'habit', 'rutin', 'routine'],
            'emotion'         => ['sedih', 'senang', 'stres', 'capek', 'marah', 'takut', 'cemas'],
        ];

        foreach ($intentKeywords as $intent => $keywords) {
            if (!isset($scores[$intent]) || $scores[$intent] === 0) continue;

            // v12.1: Expand keywords with Semantic Thesaurus
            $expandedKeywords = SemanticThesaurusModule::expand($keywords, $this->lang);

            $boost = 0;
            foreach ($expandedKeywords as $kw) {
                $pos = stripos($msg, $kw);
                if ($pos === false) continue;

                // Position weighting: first 20% of message = +5, first 40% = +3
                $relativePos = $pos / max(1, strlen($msg));
                if ($relativePos < 0.2) {
                    $boost += 5;
                } elseif ($relativePos < 0.4) {
                    $boost += 3;
                } else {
                    $boost += 1;
                }

                // Exact word boundary bonus
                if (preg_match('/\b' . preg_quote($kw, '/') . '\b/iu', $msg)) {
                    $boost += 2;
                }

                // Context history boost: if same keyword appeared in last 3 turns
                foreach (array_slice(array_reverse($this->context), 0, 3) as $ctx) {
                    if (stripos($ctx['text'] ?? '', $kw) !== false) {
                        $boost += 3;
                        break;
                    }
                }
            }

            $scores[$intent] = min(100, $scores[$intent] + $boost);
        }

        return $scores;
    }

    private function dispatchIntent(string $intent, string $msg, string $original, int $confidence): ?array
    {
        $result = match($intent) {
            'general_chat'      => $this->respondGeneralChat($msg),
            'greeting'          => $this->respondGreeting($msg),
            'multi_create'      => $this->parseMultiCreate($msg, $original),
            'create'            => $this->parseCreateWithDuplicateCheck($msg, $original),
            'update_deadline'   => $this->parseUpdateDeadline($msg),
            'update_priority'   => $this->parseUpdatePriority($msg),
            'rename'            => $this->parseRename($msg),
            'toggle'            => $this->parseToggle($msg, $original),
            'delete'            => $this->parseDelete($msg, $original),
            'bulk_op'           => $this->parseBulkOperation($msg),
            'batch_update'      => $this->parseBatchUpdate($msg, $original),
            'filtered_list'     => $this->respondFilteredList($msg),
            'list'              => $this->respondList(),
            'search'            => $this->respondSearch($msg),
            'schedule'          => $this->respondSchedule($msg),
            'stats'             => $this->respondStats(),
            'productivity'      => $this->respondProductivityInsight(),
            'weekly_review'     => $this->respondWeeklyReview(),
            'workload'          => $this->respondWorkloadAnalysis(),
            'task_analysis'     => $this->respondTaskAnalysis($msg),
            'eisenhower'        => $this->respondEisenhowerMatrix(),
            'streak'            => $this->respondStreakAchievement(),
            'focus_mode'        => $this->respondFocusMode(),
            'daily_planner'     => $this->respondDailyPlanner(),
            'deep_work'         => $this->respondDeepWorkBlocks(),
            'pomodoro'          => $this->respondPomodoro($msg),
            'smart_suggest'     => $this->respondSmartSuggestions(),
            'smart_prioritize'  => $this->respondSmartPrioritize(),
            'habit'             => $this->respondHabitTracker(),
            'template'          => $this->parseTaskTemplate($msg, $original),
            'reminder'          => $this->parseReminder($msg, $original),
            'reschedule_overdue'=> $this->parseRescheduleOverdue($msg),
            'set_goal'          => $this->respondSetGoal($msg, $original),
            'check_goal'        => $this->respondCheckGoal(),
            'identity'          => $this->respondIdentity(),
            'help'              => $this->respondHelp(),
            'motivation'        => $this->respondMotivation(),
            'emotion'           => $this->respondEmotion($msg),
            // ── Memory intents ──────────────────────────────────
            'remember_preference' => $this->respondRememberPreference($msg, $original),
            'recall_memory'       => $this->respondRecallMemory($msg),
            'forget_memory'       => $this->respondForgetMemory($msg),
            'teach_ai'            => $this->respondTeachAi($msg),
            'switch_language'     => $this->respondSwitchLanguage($msg, $original),
            'conversation_summary'=> $this->respondConversationSummary(),
            'memory_stats'        => $this->respondMemoryStats(),
            default             => null,
        };

        if ($result) {
            $result['intent'] = $intent;
            $result['confidence'] = $confidence;
        }

        return $result;
    }

    private function respondGeneralChat(string $msg): array
    {
        if ($this->matchesAny($msg, ['apa kabar','how are you','kumaha damang','piye kabar','bagaimana kabar'])) {
            return $this->respond($this->t(
                "Saya baik, {$this->userName}. Terima kasih sudah bertanya! Senang bisa melayani Anda hari ini. Ada yang bisa saya bantu?",
                "I'm doing well, {$this->userName}. Thank you for asking! Happy to assist you today. How can I help?"
            ));
        }

        if ($this->matchesAny($msg, ['makasih','thanks','thank you','suwun','nuhun'])) {
            return $this->respond($this->t(
                "Sama-sama, {$this->userName}! Sudah menjadi tugas saya. Semangat terus ya! 💪",
                "You're very welcome, {$this->userName}! Just doing my duty. Keep up the great work! 💪"
            ));
        }

        if ($this->matchesAny($msg, ['siapa pembuatmu','who created you','developer','pembuat'])) {
            return $this->respond($this->t(
                "Saya dikembangkan sebagai asisten pribadi cerdas Anda, Tuan. Fokus saya adalah membantu Anda tetap produktif!",
                "I was developed to be your intelligent personal assistant, Tuan. My focus is to help you stay productive!"
            ));
        }

        // Generic positive acknowledgment
        return $this->respond($this->t(
            "Mengerti, {$this->userName}. Ada lagi tugas yang ingin dikelola atau jadwal yang mau disusun?",
            "Understood, {$this->userName}. Any other tasks to manage or schedules to plan?",
            "Nggih, {$this->userName}. Wonten malih ingkang saged kula bantu?",
            "Muhun, {$this->userName}. Aya deui anu tiasa diabantu?",
            "Oke, {$this->userName}. Ada lagi yang bisa gue bantu?"
        ));
    }

    // ═══════════════════════════════════════════════════════════════
    // SENTIMENT ANALYSIS — lightweight keyword-based
    // ═══════════════════════════════════════════════════════════════

    /**
     * Analyze sentiment of message.
     * Returns ['polarity' => 'positive'|'negative'|'neutral', 'intensity' => 1-5, 'emotions' => []]
     */
    private function analyzeSentiment(string $msg): array
    {
        $positive = ['senang','happy','bagus','great','mantap','keren','sip','nice','love','suka',
                     'terima kasih','thanks','appreciate','semangat','excited','luar biasa','amazing',
                     'perfect','sempurna','berhasil','sukses','sukur','alhamdulillah','yeay','hore'];
        $negative = ['stres','stress','capek','tired','malas','males','bosan','bored','frustasi',
                     'frustrated','cemas','anxious','takut','afraid','khawatir','worried','panik',
                     'panic','putus asa','hopeless','burn out','burnout','lelah','exhausted',
                     'kewalahan','overwhelm','sedih','sad','gagal','failed','susah','sulit',
                     'bingung','confused','marah','angry','kesal','annoyed','kecewa','disappointed'];

        $posCount = 0;
        $negCount = 0;
        $detected = [];

        foreach ($positive as $w) {
            if (stripos($msg, $w) !== false) { $posCount++; $detected[] = $w; }
        }
        foreach ($negative as $w) {
            if (stripos($msg, $w) !== false) { $negCount++; $detected[] = $w; }
        }

        $total = $posCount + $negCount;
        if ($total === 0) return ['polarity' => 'neutral', 'intensity' => 1, 'emotions' => []];

        $polarity = $posCount > $negCount ? 'positive' : ($negCount > $posCount ? 'negative' : 'neutral');
        $intensity = min(5, max(1, $total));

        return ['polarity' => $polarity, 'intensity' => $intensity, 'emotions' => $detected];
    }

    /**
     * Pick a deterministic-but-varied response from an array of variants.
     * Uses a hash of the message to avoid always returning the same one.
     */
    private function pickResponse(array $variants, string $seed = ''): string
    {
        if (empty($variants)) return '';
        $hash = crc32($seed ?: (string) $this->now->timestamp);
        return $variants[abs($hash) % count($variants)];
    }

    // ═══════════════════════════════════════════════════════════════
    // SCORING FUNCTIONS — return 0-100
    // ═══════════════════════════════════════════════════════════════

    private function scoreGreeting(string $msg): int
    {
        $kw = ['hi','hello','hey','halo','hai','jarvis','assalamualaikum','selamat pagi',
               'selamat siang','selamat sore','selamat malam','pagi','siang','sore','malam',
               'good morning','good afternoon','good evening','yo',
               'hei','woi','bro','oi','helo',
               // Javanese
               'sugeng enjing','sugeng siang','sugeng sonten','sugeng dalu','ndherek','piye kabar',
               // Sundanese
               'wilujeng enjing','wilujeng siang','sampurasun','kumaha damang',
               // Betawi
               'aye','gue','elo',
        ];
        if ($this->hasActionKeyword($msg)) return 0;
        $score = 0;
        foreach ($kw as $k) {
            if ($msg === $k || Str::startsWith($msg, $k.' ') || Str::startsWith($msg, $k.',')) {
                $score = 90; break;
            }
        }
        return $score;
    }

    private function scoreMultiCreate(string $msg): int
    {
        if (!$this->hasCreateKeyword($msg)) return 0;
        // Compound commands (containing "dan"/"and"/comma + create verb) should beat single create
        if (preg_match('/\b(dan|and|,)\b/', $msg) && preg_match('/\b(buat|bikin|tambah|create|add)\b/', $msg)) return 95;
        return 0;
    }

    private function scoreCreate(string $msg): int
    {
        return $this->hasCreateKeyword($msg) ? 80 : 0;
    }

    private function scoreUpdateDeadline(string $msg): int
    {
        if (!$this->hasUpdateKeyword($msg)) return 0;
        return preg_match('/\b(deadline|tenggat|due|batas waktu)\b/i', $msg) ? 88 : 0;
    }

    private function scoreUpdatePriority(string $msg): int
    {
        if (!$this->hasUpdateKeyword($msg)) return 0;
        return preg_match('/\b(prioritas|priority)\b/i', $msg) ? 88 : 0;
    }

    private function scoreRename(string $msg): int
    {
        return preg_match('/\b(rename|ubah nama|ganti nama|ganti judul|ubah judul)\b/i', $msg) ? 88 : 0;
    }

    private function scoreToggle(string $msg): int
    {
        return $this->hasToggleKeyword($msg) ? 82 : 0;
    }

    private function scoreDelete(string $msg): int
    {
        if (!$this->hasDeleteKeyword($msg)) return 0;
        if ($this->hasBulkKeyword($msg)) return 0; // handled by bulk
        return 82;
    }

    private function scoreBulkOp(string $msg): int
    {
        return $this->hasBulkKeyword($msg) ? 90 : 0;
    }

    private function scoreBatchUpdate(string $msg): int
    {
        $patterns = ['update semua','update all','ganti semua deadline','ganti semua prioritas',
                     'batch update','set all priority','set semua prioritas'];
        return $this->matchesAny($msg, $patterns) ? 87 : 0;
    }

    private function scoreFilteredList(string $msg): int
    {
        $patterns = ['overdue','terlambat','high priority','prioritas tinggi','low priority',
                     'selesai','completed','belum','pending','hari ini','today','besok','tomorrow',
                     'minggu ini','this week'];
        foreach ($patterns as $p) {
            if (Str::contains($msg, $p)) return 78;
        }
        return 0;
    }

    private function scoreList(string $msg): int
    {
        $patterns = ['lihat tugas','list tugas','daftar tugas','tugasku','my tasks','show tasks',
                     'tampilkan tugas','task list','all tasks','semua tugas'];
        return $this->matchesAny($msg, $patterns) ? 75 : 0;
    }

    private function scoreSearch(string $msg): int
    {
        return preg_match('/\b(cari|search|find|temukan|mana)\b/i', $msg) ? 80 : 0;
    }

    private function scoreSchedule(string $msg): int
    {
        // Exclude if it's actually a daily planner request
        $plannerPatterns = ['rencana harian','rencanakan hari','plan hari ini','daily plan','daily planner','rencana hari ini','susun jadwal','plan my day'];
        if ($this->matchesAny($msg, $plannerPatterns)) return 0;
        return preg_match('/\b(jadwal|schedule|agenda)\b/i', $msg) ? 72 : 0;
    }

    private function scoreStats(string $msg): int
    {
        $patterns = ['statistik','stats','briefing','ringkasan','rangkuman','rekap',
                     'laporan','report','berapa tugas','how many tasks','summary'];
        return $this->matchesAny($msg, $patterns) ? 76 : 0;
    }

    private function scoreProductivity(string $msg): int
    {
        $patterns = ['produktivitas','productivity','persen selesai','completion rate',
                     'progress saya','my progress','kinerja','performance','pencapaian'];
        return $this->matchesAny($msg, $patterns) ? 75 : 0;
    }

    private function scoreWeeklyReview(string $msg): int
    {
        $patterns = ['weekly review','review mingguan','minggu ini','this week',
                     'weekly summary','bagaimana minggu','how was my week','weekly recap'];
        return $this->matchesAny($msg, $patterns) ? 78 : 0;
    }

    private function scoreWorkload(string $msg): int
    {
        $patterns = ['workload','beban kerja','capacity','kapasitas','how busy',
                     'seberapa sibuk','overloaded','kewalahan','too much'];
        return $this->matchesAny($msg, $patterns) ? 77 : 0;
    }

    private function scoreTaskAnalysis(string $msg): int
    {
        $patterns = ['analyze','analisis','analyse','breakdown','detail task','task info'];
        return $this->matchesAny($msg, $patterns) && !$this->hasCreateKeyword($msg) ? 76 : 0;
    }

    private function scoreEisenhower(string $msg): int
    {
        $patterns = ['eisenhower','matrix','urgent important','urgensi','kuadran',
                     'quadrant','penting mendesak','matriks'];
        return $this->matchesAny($msg, $patterns) ? 80 : 0;
    }

    private function scoreStreak(string $msg): int
    {
        $patterns = ['streak','achievement','pencapaian','prestasi','badges','lencana',
                     'reward','hadiah','konsisten','konsistensi'];
        return $this->matchesAny($msg, $patterns) ? 74 : 0;
    }

    private function scoreFocusMode(string $msg): int
    {
        // Exclude if it's specifically a deep work / time blocking request
        if (preg_match('/\b(deep work|time block|timeblock|blok waktu|fokus penuh|time blocking|scheduled focus)\b/i', $msg)) return 0;
        $patterns = ['focus mode','mode fokus','fokus','most important','paling penting',
                     'satu tugas','which one first','mulai dari mana','where to start',
                     'mana dulu','yang mana duluan'];
        return $this->matchesAny($msg, $patterns) && !$this->hasCreateKeyword($msg) ? 78 : 0;
    }

    private function scoreDailyPlanner(string $msg): int
    {
        $patterns = ['plan my day','daily plan','rencana harian','rencanakan hari',
                     'plan hari ini','daily planner','susun jadwal','buat rencana','rencana hari ini'];
        return $this->matchesAny($msg, $patterns) ? 80 : 0;
    }

    private function scoreDeepWork(string $msg): int
    {
        $patterns = ['deep work','time block','timeblock','blok waktu','fokus penuh',
                     'time blocking','jadwal fokus','scheduled focus'];
        return $this->matchesAny($msg, $patterns) ? 79 : 0;
    }

    private function scoreHelp(string $msg): int
    {
        $patterns = ['bantuan','help','tolong','bantu','apa yang bisa kamu lakukan','fitur','kemampuan','tanya','ingin tanya'];
        return $this->matchesAny($msg, $patterns) ? 88 : 0;
    }

    private function scorePomodoro(string $msg): int
    {
        $patterns = ['pomodoro','pomo','25 menit','work timer','timer kerja','focus timer',
                     'mulai pomodoro','start pomodoro','istirahat 5'];
        return $this->matchesAny($msg, $patterns) ? 82 : 0;
    }

    private function scoreSmartSuggest(string $msg): int
    {
        $patterns = ['suggest','saran','rekomendasi','advice','apa yang perlu','tips',
                     'sarankan','apa lagi','what else'];
        return $this->matchesAny($msg, $patterns) && !$this->hasCreateKeyword($msg) ? 71 : 0;
    }

    private function scoreSmartPrioritize(string $msg): int
    {
        $patterns = ['auto prioritize','auto prioritas','smart priority','prioritas otomatis',
                     'fix priorities','perbaiki prioritas','reassign priority','optimize priority'];
        return $this->matchesAny($msg, $patterns) ? 80 : 0;
    }

    private function scoreHabit(string $msg): int
    {
        $patterns = ['habit','kebiasaan','recurring','berulang','routine','rutinitas',
                     'track habit','lacak kebiasaan'];
        return $this->matchesAny($msg, $patterns) ? 75 : 0;
    }

    private function scoreTemplate(string $msg): int
    {
        return preg_match('/\b(template|templat|preset|quick start|quick add)\b/i', $msg) ? 82 : 0;
    }

    private function scoreReminder(string $msg): int
    {
        return $this->hasReminderKeyword($msg) ? 83 : 0;
    }

    private function scoreRescheduleOverdue(string $msg): int
    {
        return $this->hasRescheduleOverdueKeyword($msg) ? 88 : 0;
    }

    private function scoreSetGoal(string $msg): int
    {
        $patterns = ['set goal','buat goal','tambah goal','target saya','my goal',
                     'goal minggu ini','weekly goal','set target','buat target'];
        return $this->matchesAny($msg, $patterns) ? 79 : 0;
    }

    private function scoreCheckGoal(string $msg): int
    {
        $patterns = ['lihat goal','check goal','goal saya','my goals','progress goal',
                     'target tercapai','goal tercapai','how is my goal'];
        return $this->matchesAny($msg, $patterns) ? 77 : 0;
    }

    private function scoreIdentity(string $msg): int
    {
        $patterns = ['siapa kamu','who are you','kamu siapa','nama kamu','your name',
                     'apa yang bisa kamu','what can you do','bisa apa'];
        return $this->matchesAny($msg, $patterns) ? 85 : 0;
    }

    private function scoreMotivation(string $msg): int
    {
        $patterns = ['motivasi','motivation','semangat','tips produktif','males','malas',
                     'capek','tired','bosan','bored','overwhelmed','kewalahan'];
        return $this->matchesAny($msg, $patterns) ? 70 : 0;
    }

    private function scoreEmotion(string $msg): int
    {
        $patterns = ['stres','stress','cemas','anxious','panik','panic','frustrasi',
                     'frustrated','hopeless','putus asa','burn out','burnout',
                     'lelah','exhausted','takut','afraid','khawatir','worried'];
        return $this->matchesAny($msg, $patterns) ? 72 : 0;
    }

    private function scoreGeneralChat(string $msg): int
    {
        $greetings = ['apa kabar','how are you','kumaha damang','piye kabar','bagaimana kabar','halo','hallo','hi','hai','hey','salam'];
        if ($this->matchesAny($msg, $greetings)) {
             // Ensure it's not matching 'bagaimana' as 'apa'
             if (preg_match('/\b(apa kabar|how are you|kumaha damang|piye kabar|bagaimana kabar|halo|hallo|hi|hai|hey|salam)\b/i', $msg)) {
                 return 95;
             }
        }

        $identity = ['siapa pembuatmu','siapa yang buat','who created you','developer','pembuat','siapa yang bikin'];
        if ($this->matchesAny($msg, $identity)) return 95;

        $patterns = ['makasih','thanks','thank you','sip','ok','okee',
                     'siap','baiklah','paham','mengerti','keren','mantap','bagus','nice',
                     'kamu apa', 'apa itu jarvis','help me','bisa bantu','apa yang bisa kamu lakukan'];
        
        // Use regex for precise matching
        if (preg_match('/\b(doing well|apa kabar|how are you)\b/i', $msg)) return 95;

        // Conversational triggers should have lower score to allow fallback to Ollama/Expert
        return $this->matchesAny($msg, $patterns) ? 30 : 5;
    }

    // ═══════════════════════════════════════════════════════════════
    // FALLBACK FOR UNKNOWN INTENTS
    // ═══════════════════════════════════════════════════════════════

    /**
     * Fallback for free-form conversation when no specific task intent is matched.
     * Fulfills the "ChatGPT/Gemini style" conversational requirement.
     */
    public function handleGeneralConversationalChat(string $message, array $context): array
    {
        // 1. Auto-learn facts from the message (e.g., "Aku suka main gitar")
        $this->autoExtractFacts($message);

        $msg = $this->normalize($message);
        $sentiment = $this->analyzeSentiment($msg);
        $facts = $context['memory']['facts'] ?? [];
        
        // ── 1. Personalized Contextual Responses ─────────────────────
        if (str_contains($msg, 'siapa saya') || str_contains($msg, 'tentang saya')) {
            $name = $this->memory ? $this->memory->getNickname() : null;
            $interests = $this->memory ? $this->memory->recallFact('interests') : null;
            $work = $this->memory ? $this->memory->recallFact('workplace') : null;
            
            $text = $name ? "Tuan adalah {$name}. " : "Tuan adalah tuan saya yang bijaksana. ";
            if ($interests) $text .= "Tuan tertarik pada " . implode(', ', (array)$interests) . ". ";
            if ($work) $text .= "Tuan bekerja/sekolah di {$work}. ";
            $text .= "Ada hal lain yang ingin Tuan diskusikan?";
            
            return $this->respond($this->t($text, $text), null, ['stats', 'focus_mode']);
        }

        if (str_contains($msg, 'siapa kamu') || str_contains($msg, 'jarvis')) {
            return $this->respond($this->t(
                "Saya adalah Jarvis, asisten AI Tuan. Saya di sini untuk menjaga agar hidup Tuan tetap teratur dan produktif.",
                "I am Jarvis, your AI assistant. I am here to ensure your life remains organized and productive."
            ), null, ['help', 'motivation']);
        }

        // ── 2. Productivity Consultation ─────────────────────────────
        if (str_contains($msg, 'tips') || str_contains($msg, 'saran') || str_contains($msg, 'nasihat')) {
            $tips = [
                "Cobalah teknik Pomodoro untuk konsentrasi tinggi.",
                "Selesaikan tugas tersulit di pagi hari (Eat the Frog).",
                "Jangan lupa beristirahat setiap 90 menit kerja.",
                "Tuliskan 3 target utama Tuan untuk hari ini."
            ];
            $tip = $tips[array_rand($tips)];
            return $this->respond($this->t(
                "Tentu, Tuan. Satu saran untuk hari ini: {$tip}",
                "Certainly, Sir. One piece of advice for today: {$tip}"
            ), null, ['daily_planner', 'pomodoro']);
        }

        // ── 3. Learning (Fact Extraction) ───────────────────────────
        if (str_contains($msg, 'saya suka') || str_contains($msg, 'ingat bahwa')) {
            $extracted = preg_replace('/\b(saya suka|ingat bahwa|ingat|saya|bahwa)\b/i', '', $message);
            $extracted = trim($extracted, ' ,.!');
            if (!empty($extracted)) {
                if ($this->memory) $this->memory->rememberFact(Str::slug($extracted), $extracted);
                return $this->respond($this->t(
                    "Saya akan mengingat hal itu dengan baik, Tuan: \"{$extracted}\".",
                    "I will remember that carefully, Sir: \"{$extracted}\"."
                ), null, ['list_tasks', 'stats']);
            }
        }

        // ── 4. General Social / Small Talk ───────────────────────────
        if ($sentiment['polarity'] === 'positive' && (str_contains($msg, 'bagus') || str_contains($msg, 'terima kasih'))) {
            $resp = $this->t(
                "Sama-sama, Tuan. Senang bisa melayani Anda. Apa ada hal lain yang bisa saya bantu?",
                "You're very welcome, Sir. It's a pleasure to serve you. Anything else I can assist with?"
            );
            return $this->respond($resp, null, ['smart_suggest', 'motivation']);
        }

        // Logic-based construction for curiosity
        if ($sentiment['polarity'] === 'neutral' && mb_strlen($message) > 10) {
             $questions = [
                 "Menarik sekali. Bagaimana Tuan melihat hal ini berdampak pada jadwal hari ini?",
                 "Saya mencatat poin itu. Apakah ada tindakan yang perlu saya bantu jadwalkan?",
                 "Paham, Tuan. Mau bahas hal ini lebih lanjut atau kembali fokus ke daftar tugas?",
             ];
             return $this->respond($questions[array_rand($questions)], null, ['list', 'focus_mode']);
        }

        // Final fallback: try KnowledgeBase, then unknown logic
        $kbResult = $this->knowledgeBase->query($message, $this->lang);
        if ($kbResult && ($kbResult['confidence'] ?? 0) >= 40) {
            $answer = $kbResult['answer'] ?? $kbResult['explanation'] ?? '';
            if (!empty($answer)) {
                return $this->respond($answer, null, ['smart_suggest', 'help']);
            }
        }

        return $this->handleUnknown($message);
    }

    private function autoExtractFacts(string $msg): void
    {
        if (!$this->memory) return;

        // Pattern 1: Nicknames / Names
        if (preg_match('/\b(?:panggil aku|nama aku|my name is|call me|arane)\s+([a-z0-9\s]{2,20})\b/i', $msg, $m)) {
            $name = trim($m[1]);
            $this->memory->setNickname($name);
        }

        // Pattern 2: Hobbies / Interests
        if (preg_match('/\b(?:aku suka|i like|seneng|hobi(ku)?)\s+([a-z0-9\s]{2,30})\b/i', $msg, $m)) {
            $interest = trim($m[2]);
            $current = $this->memory->recallFact('interests') ?: [];
            if (!in_array($interest, (array)$current)) {
                $new = array_merge((array)$current, [$interest]);
                $this->memory->rememberFact('interests', array_unique($new));
            }
        }

        // Pattern 3: Work/Study context
        if (preg_match('/\b(?:aku kerja di|i work at|sekolah di|kuliah di)\s+([a-z0-9\s]{2,30})\b/i', $msg, $m)) {
            $this->memory->rememberFact('workplace', trim($m[1]));
        }
    }

    public function handleUnknown(string $message): array
    {
        $msg = $this->normalize($message);
        $this->lang = $this->detectLanguage($msg, $message);
        $sentiment = $this->analyzeSentiment($msg);

        // Attempt partial intent matching before fully giving up
        $partialMatches = $this->findPartialIntentMatches($msg);
        if (!empty($partialMatches)) {
            $suggestions = implode(', ', array_slice($partialMatches, 0, 3));
            return $this->respond($this->t(
                "Hmm, saya kurang yakin maksud Anda, {$this->userName}. Mungkin yang Anda cari: {$suggestions}? Atau coba \"help\" untuk melihat semua perintah.",
                "Hmm, I'm not quite sure what you mean, {$this->userName}. Perhaps you meant: {$suggestions}? Or try \"help\" to see all commands."
            ), null, ['help', 'smart_suggest', 'stats']);
        }

        // Logic-based construct
        $phrasesId = [
            "Saya masih belajar memahami percakapan kompleks, {$this->userName}.",
            "Poin yang menarik. Sebagai asisten Tuan, saya ingin tahu lebih lanjut.",
            "Saya merekam ini sebagai masukan untuk pengembangan logika saya.",
        ];
        $questionsId = [
            "Apa ada tugas lain yang bisa saya bantu catat?",
            "Bagaimana rencana Tuan untuk sisa hari ini?",
            "Apa ada sesuatu yang sedang mengganggu produktivitas Tuan?",
        ];

        $pickedPhrase = $phrasesId[array_rand($phrasesId)];
        $pickedQuestion = $questionsId[array_rand($questionsId)];

        return $this->respond(
            "{$pickedPhrase} {$pickedQuestion}",
            null,
            ['help', 'motivation', 'daily_planner']
        );
    }

    /**
     * Find partial intent matches for unknown messages.
     * Returns human-readable suggestions for the closest matching commands.
     */
    private function findPartialIntentMatches(string $msg): array
    {
        $intentKeywords = [
            'create'      => ['buat', 'tambah', 'create', 'add'],
            'delete'      => ['hapus', 'delete', 'remove'],
            'toggle'      => ['selesai', 'done', 'complete', 'tandai'],
            'list'        => ['lihat', 'list', 'show', 'tampilkan', 'tugas'],
            'search'      => ['cari', 'search', 'find'],
            'stats'       => ['statistik', 'stats', 'laporan'],
            'focus_mode'  => ['fokus', 'focus'],
            'pomodoro'    => ['pomodoro', 'timer'],
            'daily_planner'=> ['rencana', 'plan', 'jadwal'],
            'motivation'  => ['motivasi', 'semangat', 'motivation'],
            'help'        => ['help', 'bantuan', 'perintah'],
        ];

        $readableNames = [
            'create'       => $this->t('"buat tugas [nama]"', '"create task [name]"'),
            'delete'       => $this->t('"hapus tugas [nama]"', '"delete task [name]"'),
            'toggle'       => $this->t('"tandai selesai [nama]"', '"mark done [name]"'),
            'list'         => $this->t('"lihat tugas"', '"show tasks"'),
            'search'       => $this->t('"cari [kata kunci]"', '"search [keyword]"'),
            'stats'        => $this->t('"statistik"', '"stats"'),
            'focus_mode'   => $this->t('"focus mode"', '"focus mode"'),
            'pomodoro'     => $this->t('"mulai pomodoro"', '"start pomodoro"'),
            'daily_planner'=> $this->t('"rencana harian"', '"daily planner"'),
            'motivation'   => $this->t('"motivasi"', '"motivation"'),
            'help'         => $this->t('"help"', '"help"'),
        ];

        $matches = [];
        $msgWords = explode(' ', $msg);

        foreach ($intentKeywords as $intent => $keywords) {
            foreach ($keywords as $kw) {
                foreach ($msgWords as $mw) {
                    if (mb_strlen($mw) >= 3 && mb_strlen($kw) >= 3 && levenshtein($mw, $kw) <= 2) {
                        $matches[$intent] = $readableNames[$intent];
                        break 2;
                    }
                }
            }
        }

        return array_values($matches);
    }

    // ═══════════════════════════════════════════════════════════════
    // CONTEXT RESOLUTION — "yang itu", "yang tadi", ordinal refs
    // ═══════════════════════════════════════════════════════════════

    private function resolveContextualRefs(string $msg, string $original): string
    {
        // "yang pertama" / "yang kedua" / "the first" / "the second"
        $ordinals = [
            'pertama|first|satu|1st' => 0,
            'kedua|second|dua|2nd'   => 1,
            'ketiga|third|tiga|3rd'  => 2,
            'keempat|fourth|empat|4th' => 3,
        ];

        foreach ($ordinals as $pattern => $index) {
            if (preg_match('/\b(' . $pattern . ')\b/i', $msg)) {
                $pending = $this->todos->where('is_completed', false)->values();
                if ($pending->count() > $index) {
                    $task = $pending[$index];
                    // Replace ordinal reference with task title
                    $msg = preg_replace('/\b(' . $pattern . ')\b/i', $task->judul, $msg);
                }
                break;
            }
        }

        // "yang tadi" / "that one" / "itu" → last mentioned in context
        if (preg_match('/\b(yang tadi|that one|itu|tadi|tersebut)\b/i', $msg)) {
            foreach (array_reverse($this->context) as $ctx) {
                if (($ctx['role'] ?? '') === 'ai' && preg_match('/"([^"]+)"/', $ctx['text'] ?? '', $m)) {
                    $msg = str_replace($m[0], $m[1], $msg);
                    break;
                }
            }
        }

        // ── v12.0: Advanced Pronoun Resolution (Deep Anaphora) ──────
        // "dia" / "he" / "she" / "it" → resolve to last referenced task
        if (preg_match('/\b(dia|he|she|it|nya|his|her|its|tugasnya|tasknya)\b/i', $msg)) {
            $lastTask = $this->resolveLastReferencedTask();
            if ($lastTask) {
                // Replace pronoun references with the actual task title
                $msg = preg_replace('/\b(dia|he|she|it)\b/i', '"' . $lastTask->judul . '"', $msg, 1);
                $msg = preg_replace('/\b(nya|his|her|its|tugasnya|tasknya)\b/i', 'tugas "' . $lastTask->judul . '"', $msg, 1);
            }
        }

        // "mereka" / "they" / "them" → last mentioned group of tasks
        if (preg_match('/\b(mereka|they|them|semuanya|all of them)\b/i', $msg)) {
            $lastGroup = $this->resolveLastReferencedGroup();
            if (!empty($lastGroup)) {
                $titles = array_map(fn($t) => '"' . (is_object($t) ? $t->judul : ($t['judul'] ?? '')) . '"', array_slice($lastGroup, 0, 3));
                $replacement = implode(', ', $titles);
                $msg = preg_replace('/\b(mereka|they|them|semuanya|all of them)\b/i', $replacement, $msg, 1);
            }
        }

        // "yang sebelumnya" / "the previous one" → task mentioned 2 turns ago
        if (preg_match('/\b(yang sebelumnya|the previous one|sebelumnya|previous)\b/i', $msg)) {
            $prevTask = $this->resolveNthReferencedTask(1); // 1 = one step back
            if ($prevTask) {
                $msg = preg_replace('/\b(yang sebelumnya|the previous one|sebelumnya|previous)\b/i', '"' . $prevTask->judul . '"', $msg, 1);
            }
        }

        return $msg;
    }

    /**
     * v12.0: Find the last task referenced in conversation context.
     */
    private function resolveLastReferencedTask(): ?object
    {
        foreach (array_reverse($this->context) as $ctx) {
            $text = $ctx['text'] ?? '';
            // Look for quoted task titles in AI responses
            if (($ctx['role'] ?? '') === 'ai' && preg_match('/"([^"]+)"/', $text, $m)) {
                $title = $m[1];
                $match = $this->todos->first(fn($t) => 
                    stripos(is_object($t) ? $t->judul : ($t['judul'] ?? ''), $title) !== false
                );
                if ($match) return is_object($match) ? $match : (object) $match;
            }
            // Look for task titles mentioned by user
            if (($ctx['role'] ?? '') === 'user') {
                foreach ($this->todos as $task) {
                    $judul = is_object($task) ? $task->judul : ($task['judul'] ?? '');
                    if ($judul && stripos($text, $judul) !== false) {
                        return is_object($task) ? $task : (object) $task;
                    }
                }
            }
        }
        return null;
    }

    /**
     * v12.0: Find the Nth-back referenced task in conversation context.
     */
    private function resolveNthReferencedTask(int $stepsBack): ?object
    {
        $found = 0;
        foreach (array_reverse($this->context) as $ctx) {
            if (($ctx['role'] ?? '') === 'ai' && preg_match('/"([^"]+)"/', $ctx['text'] ?? '', $m)) {
                if ($found === $stepsBack) {
                    $match = $this->todos->first(fn($t) => 
                        stripos(is_object($t) ? $t->judul : ($t['judul'] ?? ''), $m[1]) !== false
                    );
                    if ($match) return is_object($match) ? $match : (object) $match;
                }
                $found++;
            }
        }
        return null;
    }

    /**
     * v12.0: Resolve a group of tasks referenced in recent context (e.g., list results).
     */
    private function resolveLastReferencedGroup(): array
    {
        foreach (array_reverse($this->context) as $ctx) {
            if (($ctx['role'] ?? '') === 'ai') {
                $text = $ctx['text'] ?? '';
                // Extract all quoted titles from a list response
                preg_match_all('/"([^"]+)"/', $text, $matches);
                if (!empty($matches[1]) && count($matches[1]) > 1) {
                    $tasks = [];
                    foreach ($matches[1] as $title) {
                        $match = $this->todos->first(fn($t) => 
                            stripos(is_object($t) ? $t->judul : ($t['judul'] ?? ''), $title) !== false
                        );
                        if ($match) $tasks[] = $match;
                    }
                    if (count($tasks) > 1) return $tasks;
                }
            }
        }
        return [];
    }

    // ═══════════════════════════════════════════════════════════════
    // v12.0: MULTI-INTENT DETECTION & PROCESSING
    // ═══════════════════════════════════════════════════════════════

    /**
     * Detect if a message contains multiple independent commands.
     * Splits on conjunctions like "dan", "lalu", "terus", "kemudian", "and", "then", "also".
     * Returns array of sub-commands. If only 1 intent found, returns single-element array.
     */
    private function detectMultiIntent(string $msg, string $original): array
    {
        // Don't split if we're in a conversational flow (wizard)
        if ($this->memory) {
            $creating = $this->memory->recall('context', 'creating_task_state');
            $editing  = $this->memory->recall('context', 'editing_task_state');
            $deleting = $this->memory->recall('context', 'deleting_task_state');
            if ($creating || $editing || $deleting) return [$msg];
        } else {
            // If memory is unavailable, skip multi-intent to prevent splitting wizard input
            return [$msg];
        }

        // Split on compound conjunctions — but only when they connect action verbs
        $actionVerbs = 'buat|bikin|tambah|hapus|delete|remove|ubah|ganti|update|selesai|tandai|toggle|mark|create|add|make|lihat|show|list|cari|search|find';
        
        // Pattern: "action1 ... (dan|lalu|terus|kemudian|then|and|also) action2 ..."
        $splitPattern = '/\s+(?:dan\s+(?:juga\s+)?|lalu\s+|terus\s+|kemudian\s+|then\s+|and\s+(?:also\s+)?|also\s+)(?=' . $actionVerbs . ')/i';
        
        $parts = preg_split($splitPattern, $msg);
        
        // Only return multi if we got 2+ meaningful parts
        if (count($parts) > 1) {
            $validParts = [];
            foreach ($parts as $part) {
                $part = trim($part);
                if (strlen($part) > 3) { // Minimum meaningful command length
                    $validParts[] = $part;
                }
            }
            if (count($validParts) > 1) {
                return array_slice($validParts, 0, 5); // Cap at 5 sub-commands
            }
        }
        
        return [$msg]; // Single intent
    }

    /**
     * Process multiple intents sequentially, combining results.
     */
    private function processMultiIntent(array $subCommands, string $originalMessage): array
    {
        $this->thoughtChain = [];
        $this->thoughtChain[] = $this->t(
            "🧠 Terdeteksi " . count($subCommands) . " perintah dalam satu pesan",
            "🧠 Detected " . count($subCommands) . " commands in one message"
        );

        $results = [];
        $actions = [];
        $allMessages = [];

        foreach ($subCommands as $index => $cmd) {
            $stepNum = $index + 1;
            $this->thoughtChain[] = $this->t(
                "Langkah {$stepNum}: Memproses \"{$cmd}\"",
                "Step {$stepNum}: Processing \"{$cmd}\""
            );

            /** @var LinguisticService $linguistic */
            $linguistic = app(LinguisticService::class);
            $normalized = $linguistic->normalize($cmd);

            // Score this sub-command individually
            $intents = $this->scoreIntents($normalized, $cmd);
            arsort($intents);

            $processed = false;
            foreach ($intents as $intent => $confidence) {
                if ($confidence < self::CONFIDENCE_THRESHOLD) break;
                $result = $this->dispatchIntent($intent, $normalized, $cmd, $confidence);
                if ($result !== null) {
                    $decoded = json_decode($result['content'] ?? '{}', true);
                    $allMessages[] = ($decoded['message'] ?? '');
                    if (!empty($decoded['action'])) {
                        $actions[] = $decoded['action'];
                    }
                    $results[] = [
                        'intent' => $intent,
                        'confidence' => $confidence,
                        'command' => $cmd,
                        'success' => true,
                    ];
                    $processed = true;
                    break;
                }
            }

            if (!$processed) {
                $allMessages[] = $this->t(
                    "⚠️ Tidak bisa memproses: \"{$cmd}\"",
                    "⚠️ Could not process: \"{$cmd}\""
                );
                $results[] = [
                    'intent' => 'unknown',
                    'confidence' => 0,
                    'command' => $cmd,
                    'success' => false,
                ];
            }
        }

        // Combine all messages
        $combinedMessage = $this->t("📋 Hasil multi-perintah:\n\n", "📋 Multi-command results:\n\n");
        foreach ($allMessages as $i => $m) {
            $combinedMessage .= ($i + 1) . ". " . $m . "\n\n";
        }

        // Use the first action (primary), pass all in multi_intent
        $primaryAction = $actions[0] ?? null;

        return $this->respond($combinedMessage, $primaryAction, null, [
            'multi_intent' => $results,
            'chain_of_thought' => $this->thoughtChain,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    // v12.0: CHAIN-OF-THOUGHT SIMULATION
    // ═══════════════════════════════════════════════════════════════

    /**
     * Build chain-of-thought reasoning for complex queries.
     * Adds step-by-step reasoning text visible to the user.
     */
    private function buildChainOfThought(string $msg, array $context, array $reasoning): array
    {
        $steps = [];

        // Step 1: Language detection
        $steps[] = $this->t(
            "1️⃣ Bahasa terdeteksi: " . strtoupper($this->lang),
            "1️⃣ Language detected: " . strtoupper($this->lang)
        );

        // Step 2: Intent classification
        $intents = $this->scoreIntents($msg, $msg);
        arsort($intents);
        $topIntents = array_slice($intents, 0, 3, true);
        $intentSummary = [];
        foreach ($topIntents as $intent => $score) {
            if ($score > 0) $intentSummary[] = "{$intent}({$score}%)";
        }
        $steps[] = $this->t(
            "2️⃣ Klasifikasi intent: " . (implode(', ', $intentSummary) ?: 'umum'),
            "2️⃣ Intent classification: " . (implode(', ', $intentSummary) ?: 'general')
        );

        // Step 3: Context awareness
        $taskCount = $this->todos->count();
        $pendingCount = $this->todos->where('is_completed', false)->count();
        $steps[] = $this->t(
            "3️⃣ Konteks: {$taskCount} tugas ({$pendingCount} aktif)",
            "3️⃣ Context: {$taskCount} tasks ({$pendingCount} active)"
        );

        // Step 4: Expert consultation
        if (!empty($reasoning['reasoning_path'])) {
            $experts = implode(', ', array_slice($reasoning['reasoning_path'], 0, 3));
            $steps[] = $this->t(
                "4️⃣ Pakar dikonsultasi: {$experts}",
                "4️⃣ Experts consulted: {$experts}"
            );
        }

        // Step 5: Confidence assessment
        $topScore = reset($topIntents) ?: 0;
        $confLabel = $topScore >= 80 ? $this->t('Sangat yakin', 'Very confident') :
                    ($topScore >= 50 ? $this->t('Cukup yakin', 'Fairly confident') :
                    ($topScore >= 35 ? $this->t('Mempertimbangkan', 'Considering') :
                    $this->t('Eksplorasi', 'Exploring')));
        $steps[] = $this->t(
            "5️⃣ Tingkat keyakinan: {$confLabel} ({$topScore}%)",
            "5️⃣ Confidence level: {$confLabel} ({$topScore}%)"
        );

        return $steps;
    }

    // ═══════════════════════════════════════════════════════════════
    // v12.0: PERSONALITY ADAPTATION — User Style Mirroring
    // ═══════════════════════════════════════════════════════════════

    /**
     * Track user's communication style from their messages.
     * Detects: formal/informal, verbose/terse, emoji usage, language preference.
     */
    private function trackCommunicationStyle(string $message): void
    {
        $style = &$this->communicationStyle;

        // ── Formality detection ─────────────────────────────────────
        $formalMarkers = ['tolong', 'mohon', 'silakan', 'please', 'could you', 'would you', 'kindly', 'apakah', 'bisakah', 'Anda'];
        $informalMarkers = ['dong', 'deh', 'nih', 'sih', 'lah', 'kek', 'cuy', 'bro', 'woi', 'gue', 'lu', 'gw', 'lo', 'wkwk', 'haha', 'lol', 'btw', 'guys'];

        $formalHits = 0;
        $informalHits = 0;
        foreach ($formalMarkers as $w) {
            if (stripos($message, $w) !== false) $formalHits++;
        }
        foreach ($informalMarkers as $w) {
            if (stripos($message, $w) !== false) $informalHits++;
        }

        if ($formalHits > $informalHits) {
            $style['formality'] = ($style['formality'] ?? 'neutral') === 'neutral' ? 'formal' : $style['formality'];
            $style['formal_score'] = min(100, ($style['formal_score'] ?? 50) + 5);
        } elseif ($informalHits > $formalHits) {
            $style['formality'] = 'informal';
            $style['formal_score'] = max(0, ($style['formal_score'] ?? 50) - 5);
        }

        // ── Verbosity detection ─────────────────────────────────────
        $wordCount = str_word_count($message);
        $style['avg_words'] = (($style['avg_words'] ?? $wordCount) + $wordCount) / 2;
        $style['verbosity'] = $style['avg_words'] > 15 ? 'verbose' : ($style['avg_words'] < 5 ? 'terse' : 'moderate');

        // ── Emoji usage ─────────────────────────────────────────────
        $emojiCount = preg_match_all('/[\x{1F300}-\x{1F64F}\x{1F680}-\x{1F6FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}]/u', $message);
        $style['uses_emoji'] = ($style['uses_emoji'] ?? false) || $emojiCount > 0;

        // ── Save to persistent memory ───────────────────────────────
        if ($this->memory && count($this->context) % 5 === 0) {
            // Save every 5th message to avoid excessive writes
            $this->memory->remember('personality', 'communication_style', $style, now()->addDays(30)->toDateTimeString());
        }
    }

    /**
     * Get an adaptive response prefix based on tracked communication style.
     */
    private function getAdaptivePrefix(): string
    {
        $formality = $this->communicationStyle['formality'] ?? 'neutral';
        $verbosity = $this->communicationStyle['verbosity'] ?? 'moderate';

        if ($formality === 'informal') {
            return $this->t('', ''); // Keep it casual, no formal prefix
        }
        return ''; // Default: no prefix
    }

    /**
     * Adapt response tone based on user's style.
     */
    private function adaptResponseTone(string $message): string
    {
        $formality = $this->communicationStyle['formality'] ?? 'neutral';

        if ($formality === 'informal') {
            // Make responses slightly more casual
            $message = str_replace(
                ['Baik, Tuan.', 'Tentu, Tuan.', 'Siap, Tuan.', 'Sir,'],
                ['Oke!', 'Siap!', 'Oke!', ''],
                $message
            );
        }

        return $message;
    }

    // ═══════════════════════════════════════════════════════════════
    // v12.0: SEMANTIC WEIGHTED CONFIDENCE SCORING
    // ═══════════════════════════════════════════════════════════════

    /**
     * Apply semantic weighting to keyword matches.
     * Position-weighted: keywords at start of message = higher weight.
     * Context-boosted: if recent conversation was about same topic, boost score.
     */
    private function semanticWeightedScore(string $msg, array $keywords, int $baseScore = 10): int
    {
        $score = 0;
        $msgWords = explode(' ', $msg);
        $totalWords = count($msgWords);
        if ($totalWords === 0) return 0;

        foreach ($keywords as $keyword) {
            $pos = stripos($msg, $keyword);
            if ($pos === false) continue;

            // Base match score
            $matchScore = $baseScore;

            // ── Position weighting ──────────────────────────────────
            // Keywords at the start of message get 1.5x boost
            // Keywords in the first third get 1.2x
            $relativePos = $pos / max(1, strlen($msg));
            if ($relativePos < 0.15) {
                $matchScore = (int) ($matchScore * 1.5); // First 15% of message
            } elseif ($relativePos < 0.33) {
                $matchScore = (int) ($matchScore * 1.2); // First third
            }

            // ── Exact word boundary match bonus ─────────────────────
            if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/i', $msg)) {
                $matchScore += 3; // Exact word boundary = more confident
            }

            // ── Context history boost ───────────────────────────────
            // If the same topic was discussed recently, boost confidence
            foreach (array_slice(array_reverse($this->context), 0, 3) as $ctx) {
                if (stripos($ctx['text'] ?? '', $keyword) !== false) {
                    $matchScore += 5; // Recent context mention boost
                    break;
                }
            }

            $score += $matchScore;
        }

        return min(100, $score);
    }

    // ═══════════════════════════════════════════════════════════════
    // LANGUAGE DETECTION — ID / EN / Javanese / Sundanese / Betawi
    // ═══════════════════════════════════════════════════════════════

    private function detectLanguage(string $msg, string $original = ''): string
    {
        // High-confidence dialect-specific markers (NOT shared with Indonesian)
        $jvMarkers = ['sugeng','piye','ndherek','nuwun','matur','suwun','opo','sopo',
                      'awakmu','kowe','nggawe','mbusak','delok','iki','iku','saiki',
                      'nggih','mboten','wonten','maturnuwun','sampun','kulo','panjenengan',
                      'durung','wis','rampung'];

        // Sundanese markers (unique to Sundanese)
        $suMarkers = ['wilujeng','kumaha','damang','sampurasun','mangga',
                      'hapunten','haturnuhun','naon','iraha','anjeun','abdi',
                      'maneh','tos','bikeun','punten'];

        // Betawi markers (unique to Betawi)
        $btMarkers = ['aye','kagak','engga','nyang','emang','udh','belom','ude'];

        $words = explode(' ', $msg);

        // Phase 1: Check high-confidence dialect markers first (require 2+ matches)
        $jvCount = 0; $suCount = 0; $btCount = 0;
        foreach ($words as $w) {
            if (in_array($w, $jvMarkers)) $jvCount++;
            if (in_array($w, $suMarkers)) $suCount++;
            if (in_array($w, $btMarkers)) $btCount++;
        }

        $maxDialect = max($jvCount, $suCount, $btCount);
        if ($maxDialect >= 2) {
            if ($jvCount === $maxDialect) return 'jv';
            if ($suCount === $maxDialect) return 'su';
            if ($btCount === $maxDialect) return 'bt';
        }

        // Phase 2: Fallback to English vs Indonesian word counting
        $enWords = ['create','make','add','delete','remove','update','change','show','list',
                    'my','tasks','task','schedule','today','tomorrow','help','hello','hi','hey',
                    'good','morning','afternoon','evening','night','please','thanks','done',
                    'complete','finish','search','find','what','how','when','which','the','with',
                    'priority','deadline','overdue','pending','stats','reschedule','remind',
                    'motivation','who','are','you','your','can','will','would','should','could',
                    'all','completed','mark','toggle','set','focus','plan','review','week','day',
                    'workload','analysis','analyze','eisenhower','matrix','pomodoro','deep','work',
                    'goal','goals','streak','achievement','suggest','habit','template','batch',
        ];

        $idWords = ['buat','tambah','bikin','hapus','ubah','ganti','lihat','tampilkan','tugas',
                    'jadwal','hari','ini','besok','tolong','selamat','pagi','siang','sore','malam',
                    'halo','hai','terima','kasih','selesai','tandai','cari','apa','bagaimana',
                    'kapan','mana','yang','dengan','untuk','dari','ke','dan','atau','prioritas',
                    'tenggat','terlambat','semua','statistik','laporan','produktivitas','kinerja',
                    'pencapaian','pengingat','ingatkan','motivasi','siapa','kamu','anda','bisa',
                    'saya','mau','sudah','belum','juga','lagi','dong','deh','nih','yuk','nanti',
                    'segera','update','cek','rekap','fokus','rencana','tujuan','kebiasaan','goal',
        ];

        $enCount = 0; $idCount = 0;
        foreach ($words as $w) {
            if (in_array($w, $enWords)) $enCount++;
            if (in_array($w, $idWords)) $idCount++;
        }

        // Also check single dialect marker (lower confidence) combined with ID tie
        if ($maxDialect >= 1 && $enCount <= $idCount) {
            if ($jvCount === $maxDialect) return 'jv';
            if ($suCount === $maxDialect) return 'su';
            if ($btCount === $maxDialect) return 'bt';
        }

        return ($enCount > $idCount) ? 'en' : 'id';

        return $scores['en'] > $scores['id'] ? 'en' : 'id';
    }

    // ═══════════════════════════════════════════════════════════════
    // KEYWORD HELPERS
    // ═══════════════════════════════════════════════════════════════

    private function hasActionKeyword(string $msg): bool
    {
        return $this->hasCreateKeyword($msg) || $this->hasDeleteKeyword($msg)
            || $this->hasToggleKeyword($msg) || $this->hasUpdateKeyword($msg)
            || $this->hasReminderKeyword($msg) || $this->hasBulkKeyword($msg)
            || $this->hasRescheduleOverdueKeyword($msg);
    }

    private function hasCreateKeyword(string $msg): bool
    {
        return $this->matchesThesaurus($msg, ['buat', 'bikin', 'tambah', 'create', 'add', 'new task', 'catat']);
    }

    private function hasDeleteKeyword(string $msg): bool
    {
        return $this->matchesThesaurus($msg, ['hapus', 'delete', 'remove', 'buang', 'hilangkan']);
    }

    private function hasToggleKeyword(string $msg): bool
    {
        return $this->matchesThesaurus($msg, ['selesai', 'done', 'complete', 'tandai', 'mark', 'toggle']);
    }

    private function hasUpdateKeyword(string $msg): bool
    {
        return $this->matchesThesaurus($msg, ['ubah', 'ganti', 'update', 'change', 'edit']);
    }

    private static array $regexCache = [];

    private function matchesThesaurus(string $msg, array $keywords): bool
    {
        $cacheKey = $this->lang . '_' . implode('|', $keywords);
        if (isset(self::$regexCache[$cacheKey])) {
            $pattern = self::$regexCache[$cacheKey];
        } else {
            $expanded = SemanticThesaurusModule::expand($keywords, $this->lang);
            $escaped = array_map(fn($k) => preg_quote($k, '/'), $expanded);
            $pattern = '/\b(' . implode('|', $escaped) . ')\b/i';
            self::$regexCache[$cacheKey] = $pattern;
        }
        return (bool) preg_match($pattern, $msg);
    }

    private function hasReminderKeyword(string $msg): bool
    {
        return (bool) preg_match('/\b(ingatkan|remind|reminder|alarm|pengingat|notifikasi|notification|pepeling|enget)\b/', $msg);
    }

    private function hasBulkKeyword(string $msg): bool
    {
        return (bool) preg_match('/\b(semua|all|seluruh|semuanya|kabeh)\b/', $msg)
            && ($this->hasDeleteKeyword($msg) || $this->hasToggleKeyword($msg));
    }

    private function hasRescheduleOverdueKeyword(string $msg): bool
    {
        return (bool) preg_match('/\b(reschedule|jadwalkan ulang|jadwal ulang|pindahkan)\b.*\b(overdue|terlambat|telat|lewat)\b/i', $msg)
            || (bool) preg_match('/\b(overdue|terlambat|telat|lewat)\b.*\b(reschedule|jadwalkan ulang|jadwal ulang|pindahkan)\b/i', $msg);
    }

    private function matchesAny(string $msg, array $patterns): bool
    {
        foreach ($patterns as $p) {
            if (stripos($msg, $p) !== false) {
                return true;
            }
        }
        // Fuzzy fallback: try Levenshtein for short patterns (typo tolerance)
        // Only for single-word patterns >= 5 chars, with tight distance of 1
        $msgWords = explode(' ', $msg);
        foreach ($patterns as $p) {
            $pWords = explode(' ', $p);
            if (count($pWords) === 1 && mb_strlen($p) >= 5) {
                foreach ($msgWords as $mw) {
                    if (mb_strlen($mw) >= 5 && levenshtein($mw, $p) <= 1) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    // ═══════════════════════════════════════════════════════════════
    // NEW: EISENHOWER MATRIX
    // ═══════════════════════════════════════════════════════════════

    private function respondEisenhowerMatrix(): array
    {
        $pending = $this->todos->where('is_completed', false);
        if ($pending->isEmpty()) {
            return $this->respond($this->t(
                "Tidak ada tugas pending untuk dianalisis dengan Eisenhower Matrix.",
                "No pending tasks to analyze with the Eisenhower Matrix."
            ));
        }

        $q1 = []; // Urgent + Important (DO NOW)
        $q2 = []; // Not Urgent + Important (SCHEDULE)
        $q3 = []; // Urgent + Not Important (DELEGATE)
        $q4 = []; // Not Urgent + Not Important (ELIMINATE)

        foreach ($pending as $t) {
            $isUrgent    = false;
            $isImportant = false;

            // Urgency: overdue, due today/tomorrow, or high priority
            if ($t->deadline) {
                $dl = Carbon::parse($t->deadline);
                if ($dl->isPast() || $dl->isToday() || $dl->isTomorrow()) $isUrgent = true;
                $hoursLeft = $this->now->diffInHours($dl, false);
                if ($hoursLeft <= 72) $isUrgent = true;
            }
            if (($t->priority ?? 'medium') === 'high') $isUrgent = true;

            // Importance: high priority = important, medium may be, low = not
            $priority = $t->priority ?? 'medium';
            if ($priority === 'high') $isImportant = true;
            elseif ($priority === 'medium') $isImportant = true; // assume medium = important

            if ($isUrgent && $isImportant)      $q1[] = $t;
            elseif (!$isUrgent && $isImportant)  $q2[] = $t;
            elseif ($isUrgent && !$isImportant)  $q3[] = $t;
            else                                  $q4[] = $t;
        }

        $fmt = fn($tasks) => implode("\n", array_map(fn($t) => "  • \"{$t->judul}\"", array_slice($tasks, 0, 5)));
        $more = fn($tasks, $max=5) => count($tasks) > $max ? "\n  ...+" . (count($tasks)-$max) . " lagi" : "";

        if ($this->lang === 'en') {
            $text  = "🧭 **EISENHOWER MATRIX**, {$this->userName}\n";
            $text .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
            $text .= "🔴 **Q1 — DO NOW** (Urgent + Important) [" . count($q1) . "]\n";
            $text .= (empty($q1) ? "  ✅ None — great!\n" : $fmt($q1) . $more($q1) . "\n") . "\n";
            $text .= "📅 **Q2 — SCHEDULE** (Important, Not Urgent) [" . count($q2) . "]\n";
            $text .= (empty($q2) ? "  — None\n" : $fmt($q2) . $more($q2) . "\n") . "\n";
            $text .= "🤝 **Q3 — DELEGATE** (Urgent, Not Important) [" . count($q3) . "]\n";
            $text .= (empty($q3) ? "  — None\n" : $fmt($q3) . $more($q3) . "\n") . "\n";
            $text .= "🗑️ **Q4 — ELIMINATE** (Not Urgent + Not Important) [" . count($q4) . "]\n";
            $text .= (empty($q4) ? "  — None\n" : $fmt($q4) . $more($q4) . "\n") . "\n";
            $text .= "💡 Focus on Q1 first, then invest time in Q2 to prevent future crises.";
        } else {
            $text  = "🧭 **EISENHOWER MATRIX**, {$this->userName}\n";
            $text .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
            $text .= "🔴 **Q1 — KERJAKAN SEKARANG** (Mendesak + Penting) [" . count($q1) . "]\n";
            $text .= (empty($q1) ? "  ✅ Kosong — bagus!\n" : $fmt($q1) . $more($q1) . "\n") . "\n";
            $text .= "📅 **Q2 — JADWALKAN** (Penting, Tidak Mendesak) [" . count($q2) . "]\n";
            $text .= (empty($q2) ? "  — Kosong\n" : $fmt($q2) . $more($q2) . "\n") . "\n";
            $text .= "🤝 **Q3 — DELEGASIKAN** (Mendesak, Tidak Penting) [" . count($q3) . "]\n";
            $text .= (empty($q3) ? "  — Kosong\n" : $fmt($q3) . $more($q3) . "\n") . "\n";
            $text .= "🗑️ **Q4 — ELIMINASI** (Tidak Mendesak + Tidak Penting) [" . count($q4) . "]\n";
            $text .= (empty($q4) ? "  — Kosong\n" : $fmt($q4) . $more($q4) . "\n") . "\n";
            $text .= "💡 Fokus ke Q1 dulu, lalu investasikan waktu di Q2 untuk mencegah krisis masa depan.";
        }

        return $this->respond($text, [
            'type' => 'search_tasks',
            'data' => ['view' => 'eisenhower']
        ], ['eisenhower', 'focus_mode', 'smart_prioritize']);
    }

    // ═══════════════════════════════════════════════════════════════
    // NEW: STREAK & ACHIEVEMENT
    // ═══════════════════════════════════════════════════════════════

    private function respondStreakAchievement(): array
    {
        $completed   = $this->todos->where('is_completed', true);
        $total       = $this->todos->count();
        $completedCt = $completed->count();
        $rate        = $total > 0 ? round(($completedCt / $total) * 100) : 0;

        // Count tasks completed today
        $todayDone = $completed->filter(fn($t) =>
            $t->updated_at && Carbon::parse($t->updated_at)->isToday()
        )->count();

        // Count consecutive days with at least 1 completion
        $streak = 0;
        $checkDay = $this->now->copy()->startOfDay();
        for ($i = 0; $i < 30; $i++) {
            $dayStart = $checkDay->copy()->subDays($i)->startOfDay();
            $dayEnd   = $dayStart->copy()->endOfDay();
            $hasCompletion = $completed->filter(fn($t) =>
                $t->updated_at && Carbon::parse($t->updated_at)->between($dayStart, $dayEnd)
            )->isNotEmpty();
            if ($hasCompletion) $streak++;
            else if ($i > 0) break;
        }

        // Determine badges
        $badges = [];
        if ($completedCt >= 1)   $badges[] = '🥉 First Task Done';
        if ($completedCt >= 10)  $badges[] = '🥈 10 Tasks Champion';
        if ($completedCt >= 25)  $badges[] = '🥇 25 Tasks Legend';
        if ($completedCt >= 50)  $badges[] = '💎 50 Tasks Diamond';
        if ($completedCt >= 100) $badges[] = '👑 100 Tasks Royalty';
        if ($streak >= 3)        $badges[] = "🔥 {$streak}-Day Streak";
        if ($streak >= 7)        $badges[] = "⚡ Week Warrior";
        if ($streak >= 30)       $badges[] = "🌟 30-Day Legend";
        if ($rate >= 80)         $badges[] = '🎯 80%+ Completion Rate';
        if ($todayDone >= 5)     $badges[] = '💪 5+ Tasks Today';

        $nextMilestone = collect([1,10,25,50,100,250,500])
            ->first(fn($m) => $m > $completedCt);

        if ($this->lang === 'en') {
            $text  = "🏆 **ACHIEVEMENTS & STREAK**, {$this->userName}\n";
            $text .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
            $text .= "🔥 Current Streak: {$streak} day(s)\n";
            $text .= "✅ Total Completed: {$completedCt} tasks\n";
            $text .= "📅 Completed Today: {$todayDone} tasks\n";
            $text .= "📊 All-time Rate: {$rate}%\n\n";
            if (!empty($badges)) {
                $text .= "🏅 **Badges Earned:**\n";
                foreach ($badges as $b) $text .= "  {$b}\n";
            } else {
                $text .= "🔓 No badges yet — complete your first task to earn one!\n";
            }
            if ($nextMilestone) {
                $remaining = $nextMilestone - $completedCt;
                $text .= "\n🎯 Next milestone: {$nextMilestone} tasks ({$remaining} to go!)";
            }
        } else {
            $text  = "🏆 **PENCAPAIAN & STREAK**, {$this->userName}\n";
            $text .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
            $text .= "🔥 Streak Saat Ini: {$streak} hari\n";
            $text .= "✅ Total Selesai: {$completedCt} tugas\n";
            $text .= "📅 Selesai Hari Ini: {$todayDone} tugas\n";
            $text .= "📊 Tingkat Selesai: {$rate}%\n\n";
            if (!empty($badges)) {
                $text .= "🏅 **Lencana Diraih:**\n";
                foreach ($badges as $b) $text .= "  {$b}\n";
            } else {
                $text .= "🔓 Belum ada lencana — selesaikan tugas pertama untuk mendapat satu!\n";
            }
            if ($nextMilestone) {
                $remaining = $nextMilestone - $completedCt;
                $text .= "\n🎯 Milestone berikutnya: {$nextMilestone} tugas ({$remaining} lagi!)";
            }
        }

        return $this->respond($text, null, ['weekly_review', 'productivity', 'stats']);
    }

    // ═══════════════════════════════════════════════════════════════
    // NEW: DEEP WORK BLOCKS
    // ═══════════════════════════════════════════════════════════════

    private function respondDeepWorkBlocks(): array
    {
        $pending = $this->todos->where('is_completed', false)
            ->where('priority', 'high')
            ->filter(fn($t) => !$t->is_completed);

        if ($pending->isEmpty()) {
            $pending = $this->todos->where('is_completed', false);
        }

        // Each deep work block = 90 minutes; max 4 blocks/day
        $blocks = [];
        $blockDurations = [90, 90, 60, 60]; // minutes
        $startTimes = ['09:00', '11:00', '14:00', '16:00'];
        $taskList   = $pending->take(8)->values();

        $taskIndex = 0;
        foreach ($blockDurations as $i => $duration) {
            if ($taskIndex >= $taskList->count()) break;
            $blockTasks = [];
            $tasksPerBlock = ($duration === 90) ? 2 : 1;
            for ($j = 0; $j < $tasksPerBlock && $taskIndex < $taskList->count(); $j++) {
                $blockTasks[] = $taskList[$taskIndex]->judul;
                $taskIndex++;
            }
            if (!empty($blockTasks)) {
                $blocks[] = [
                    'start'    => $startTimes[$i],
                    'duration' => $duration,
                    'tasks'    => $blockTasks,
                ];
            }
        }

        if ($this->lang === 'en') {
            $text  = "🧠 **DEEP WORK SCHEDULE**, {$this->userName}\n";
            $text .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            $text .= "Based on Cal Newport's Deep Work methodology\n\n";
            foreach ($blocks as $b) {
                $end = Carbon::createFromFormat('H:i', $b['start'])->addMinutes($b['duration'])->format('H:i');
                $text .= "🟦 **{$b['start']} – {$end}** ({$b['duration']} min)\n";
                foreach ($b['tasks'] as $task) $text .= "   📌 \"{$task}\"\n";
                $text .= "\n";
            }
            $text .= "💡 Rules:\n";
            $text .= "• Phone on silent, notifications off\n";
            $text .= "• One task at a time — no multitasking\n";
            $text .= "• 15-min break between blocks\n";
            $text .= "• 60-min lunch after Q2 block";
        } else {
            $text  = "🧠 **JADWAL DEEP WORK**, {$this->userName}\n";
            $text .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            $text .= "Berdasarkan metodologi Deep Work Cal Newport\n\n";
            foreach ($blocks as $b) {
                $end = Carbon::createFromFormat('H:i', $b['start'])->addMinutes($b['duration'])->format('H:i');
                $text .= "🟦 **{$b['start']} – {$end}** ({$b['duration']} menit)\n";
                foreach ($b['tasks'] as $task) $text .= "   📌 \"{$task}\"\n";
                $text .= "\n";
            }
            $text .= "💡 Aturan:\n";
            $text .= "• HP silent, matikan notifikasi\n";
            $text .= "• Satu tugas sekaligus — no multitasking\n";
            $text .= "• Istirahat 15 menit antar blok\n";
            $text .= "• Makan siang 60 menit setelah blok ke-2";
        }

        return $this->respond($text, null, ['pomodoro', 'focus_mode', 'daily_planner']);
    }

    // ═══════════════════════════════════════════════════════════════
    // NEW: POMODORO COMMAND
    // ═══════════════════════════════════════════════════════════════

    private function respondPomodoro(string $msg): array
    {
        // Detect custom duration
        $workMins  = 25;
        $breakMins = 5;

        if (preg_match('/(\d+)\s*(?:menit|min|minutes?)\s*(?:kerja|work|fokus|focus)/i', $msg, $m)) {
            $workMins = (int) $m[1];
        }
        if (preg_match('/(\d+)\s*(?:menit|min|minutes?)\s*(?:istirahat|break|rest)/i', $msg, $m)) {
            $breakMins = (int) $m[1];
        }

        $pendingCount = $this->todos->where('is_completed', false)->count();
        $topTask = $this->todos->where('is_completed', false)
            ->sortByDesc(fn($t) => match($t->priority ?? 'medium') {'high'=>3,'medium'=>2,'low'=>1,default=>2})
            ->first();

        $pomodoroData = [
            'work_minutes'  => $workMins,
            'break_minutes' => $breakMins,
            'task'          => $topTask ? $topTask->judul : null,
            'task_id'       => $topTask ? $topTask->id : null,
        ];

        if ($this->lang === 'en') {
            $text  = "⏱️ **POMODORO TIMER**, {$this->userName}\n";
            $text .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
            $text .= "🟢 Work: **{$workMins} minutes**\n";
            $text .= "🔴 Break: **{$breakMins} minutes**\n\n";
            if ($topTask) {
                $text .= "🎯 Suggested task: \"{$topTask->judul}\"\n\n";
            }
            $text .= "A Pomodoro session will be logged as a task.\n";
            $text .= "You have {$pendingCount} pending tasks. Good luck!";
        } else {
            $text  = "⏱️ **POMODORO TIMER**, {$this->userName}\n";
            $text .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
            $text .= "🟢 Fokus: **{$workMins} menit**\n";
            $text .= "🔴 Istirahat: **{$breakMins} menit**\n\n";
            if ($topTask) {
                $text .= "🎯 Tugas yang disarankan: \"{$topTask->judul}\"\n\n";
            }
            $text .= "Sesi Pomodoro akan dicatat sebagai tugas.\n";
            $text .= "Anda punya {$pendingCount} tugas pending. Semangat!";
        }

        return $this->respond(
            $text,
            ['type' => 'start_pomodoro', 'data' => $pomodoroData],
            ['deep_work', 'focus_mode']
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // NEW: EMOTION DETECTION & EMPATHETIC RESPONSE
    // ═══════════════════════════════════════════════════════════════

    private function respondEmotion(string $msg): array
    {
        $isStress   = $this->matchesAny($msg, ['stres','stress','cemas','panik','panic','anxious',
                                                'khawatir','worried','overwhelmed','kewalahan']);
        $isBurnout  = $this->matchesAny($msg, ['burnout','burn out','lelah banget','exhausted',
                                                'capek banget','nyerah','give up','putus asa']);
        $isFear     = $this->matchesAny($msg, ['takut','afraid','scary','menakutkan','ngeri','was-was']);
        $isSad      = $this->matchesAny($msg, ['sedih','sad','down','murung','galau','kecewa','disappointed']);
        $isAngry    = $this->matchesAny($msg, ['marah','angry','kesal','annoyed','jengkel','frustrasi','frustrated']);
        $isConfused = $this->matchesAny($msg, ['bingung','confused','lost','overwhelm','tidak tahu','dont know']);

        $pending = $this->todos->where('is_completed', false)->count();
        $high    = $this->todos->where('is_completed', false)->where('priority', 'high')->count();

        if ($isBurnout) {
            $text = $this->t(
                "\xF0\x9F\x92\x99 Saya dengar Anda, {$this->userName}. Burnout itu nyata dan wajar dirasakan.\n\n" .
                "Saran saya:\n" .
                "1. \xF0\x9F\x9B\x91 Berhenti dulu mencoba produktif sekarang\n" .
                "2. \xF0\x9F\x98\xB4 Istirahat bukan kemalasan, itu pemulihan\n" .
                "3. \xF0\x9F\x93\x8B Ada {$pending} tugas pending, kita bisa reschedule\n" .
                "4. \xF0\x9F\x8E\xAF Pilih SATU tugas saja untuk hari ini\n\n" .
                "Mau saya jadwalkan ulang tugas tidak mendesak ke minggu depan?",
                "\xF0\x9F\x92\x99 I hear you, {$this->userName}. Burnout is real and it's okay to acknowledge it.\n\n" .
                "Here's what I suggest:\n" .
                "1. \xF0\x9F\x9B\x91 Stop trying to be productive right now\n" .
                "2. \xF0\x9F\x98\xB4 Rest is not laziness, it's recovery\n" .
                "3. \xF0\x9F\x93\x8B You have {$pending} pending tasks, we can reschedule\n" .
                "4. \xF0\x9F\x8E\xAF Pick just ONE task for today\n\n" .
                "Would you like me to reschedule non-urgent tasks to next week?"
            );
            return $this->respond($text, null, ['reschedule_overdue', 'focus_mode']);
        }

        if ($isStress) {
            $taskInfo = $pending > 0
                ? $this->t(" Anda punya {$pending} tugas pending" . ($high > 0 ? " ({$high} prioritas tinggi)." : "."),
                           " You have {$pending} pending tasks" . ($high > 0 ? " ({$high} high priority)." : "."))
                : '';
            $text = $this->t(
                "\xF0\x9F\x92\x99 Wajar merasa stres, {$this->userName}. Mari saya bantu.{$taskInfo}\n\n" .
                "Coba ini:\n" .
                "\xF0\x9F\x8C\xAC Tarik napas dalam 4 detik, tahan 4 detik, buang 4 detik\n" .
                "\xF0\x9F\x8E\xAF Ucapkan \"focus mode\" untuk lihat prioritas #1\n" .
                "\xE2\x8F\xB1 Ucapkan \"pomodoro\" untuk sesi kerja 25 menit\n" .
                "\xF0\x9F\x94\x84 Ucapkan \"jadwal ulang tugas terlambat\" untuk bersihkan backlog\n\n" .
                "Satu langkah dalam satu waktu. Anda pasti bisa.",
                "\xF0\x9F\x92\x99 It's okay to feel stressed, {$this->userName}. Let me help.{$taskInfo}\n\n" .
                "Try this:\n" .
                "\xF0\x9F\x8C\xAC Breathe in 4s, hold 4s, out 4s\n" .
                "\xF0\x9F\x8E\xAF Say \"focus mode\" to see your #1 priority\n" .
                "\xE2\x8F\xB1 Say \"pomodoro\" for a 25-min focused session\n" .
                "\xF0\x9F\x94\x84 Say \"reschedule overdue\" to clear backlogs\n\n" .
                "One step at a time. You've got this."
            );
            return $this->respond($text, null, ['focus_mode', 'pomodoro', 'reschedule_overdue']);
        }

        if ($isSad) {
            $text = $this->t(
                "\xF0\x9F\x92\x99 Tidak apa-apa merasa sedih, {$this->userName}. Perasaan itu valid.\n\n" .
                "Yang bisa kita lakukan:\n" .
                "\xF0\x9F\x8E\xAF Fokus ke satu hal kecil yang bisa diselesaikan hari ini\n" .
                "\xF0\x9F\x93\x96 Lihat pencapaian Anda: \"streak\" atau \"stats\"\n" .
                "\xF0\x9F\x92\xAA Kadang menyelesaikan satu tugas kecil bisa mengangkat mood\n\n" .
                "Saya di sini kapanpun Anda butuh.",
                "\xF0\x9F\x92\x99 It's okay to feel sad, {$this->userName}. Your feelings are valid.\n\n" .
                "What we can do:\n" .
                "\xF0\x9F\x8E\xAF Focus on one small thing you can finish today\n" .
                "\xF0\x9F\x93\x96 Check your progress: \"streak\" or \"stats\"\n" .
                "\xF0\x9F\x92\xAA Sometimes completing one small task can lift your mood\n\n" .
                "I'm here whenever you need me."
            );
            return $this->respond($text, null, ['focus_mode', 'streak', 'stats']);
        }

        if ($isAngry) {
            $text = $this->t(
                "\xF0\x9F\x92\x99 Saya mengerti frustrasi Anda, {$this->userName}. Mari kita salurkan energi itu secara produktif.\n\n" .
                "\xF0\x9F\x8E\xAF Mode fokus: selesaikan tugas yang mengganggu pikiran\n" .
                "\xF0\x9F\x97\x91 Bersihkan backlog: hapus tugas yang tidak relevan lagi\n" .
                "\xE2\x8F\xB1 Pomodoro: 25 menit intens, lalu istirahat\n\n" .
                "Kadang mengerjakan sesuatu bisa meredakan rasa frustrasi.",
                "\xF0\x9F\x92\x99 I understand your frustration, {$this->userName}. Let's channel that energy productively.\n\n" .
                "\xF0\x9F\x8E\xAF Focus mode: tackle what's bugging you\n" .
                "\xF0\x9F\x97\x91 Clear backlog: delete irrelevant tasks\n" .
                "\xE2\x8F\xB1 Pomodoro: 25 min intense, then rest\n\n" .
                "Sometimes doing something can ease the frustration."
            );
            return $this->respond($text, null, ['focus_mode', 'pomodoro', 'bulk_op']);
        }

        if ($isConfused) {
            $text = $this->t(
                "\xF0\x9F\x92\x99 Bingung itu wajar, {$this->userName}. Mari kita urai bersama.\n\n" .
                "Coba langkah ini:\n" .
                "\xF0\x9F\x93\x8B \"lihat tugas\" untuk melihat gambaran besar\n" .
                "\xF0\x9F\xA7\xAD \"eisenhower\" untuk prioritaskan berdasarkan urgensi\n" .
                "\xF0\x9F\x93\x85 \"rencana harian\" untuk struktur hari Anda\n" .
                "\xF0\x9F\x92\xA1 \"saran cerdas\" untuk rekomendasi spesifik\n\n" .
                "Yang penting mulai dari satu langkah kecil.",
                "\xF0\x9F\x92\x99 Feeling confused is normal, {$this->userName}. Let's break it down together.\n\n" .
                "Try these steps:\n" .
                "\xF0\x9F\x93\x8B \"show tasks\" for the big picture\n" .
                "\xF0\x9F\xA7\xAD \"eisenhower\" to prioritize by urgency\n" .
                "\xF0\x9F\x93\x85 \"daily planner\" to structure your day\n" .
                "\xF0\x9F\x92\xA1 \"smart suggest\" for specific recommendations\n\n" .
                "Just start with one small step."
            );
            return $this->respond($text, null, ['list', 'eisenhower', 'daily_planner', 'smart_suggest']);
        }

        // Generic emotional support (fear, general)
        $text = $this->t(
            "\xF0\x9F\x92\x99 Sepertinya Anda sedang mengalami sesuatu, {$this->userName}.\n\n" .
            "Tarik napas dulu. Saya di sini untuk bantu kelola tugas dan kurangi beban pikiran.\n" .
            "Mau lihat tugas terpenting atau rencanakan hari ini?",
            "\xF0\x9F\x92\x99 It sounds like you're going through something, {$this->userName}.\n\n" .
            "Take a deep breath. I'm here to help you manage tasks and reduce mental load.\n" .
            "Would you like to see your most important tasks or plan your day?"
        );
        return $this->respond($text, null, ['focus_mode', 'daily_planner', 'smart_suggest']);
    }

    // ═══════════════════════════════════════════════════════════════
    // NEW: GOAL SETTING & TRACKING
    // ═══════════════════════════════════════════════════════════════

    private function respondSetGoal(string $msg, string $original): array
    {
        // Extract goal from message
        $goalText = preg_replace('/\b(set goal|buat goal|tambah goal|target saya|my goal|goal minggu ini|weekly goal|set target|buat target)\b\s*/i', '', $msg);
        $goalText = $this->cleanTitle($goalText);

        if (empty($goalText) || mb_strlen($goalText) < 3) {
            return $this->respond($this->t(
                "💭 Goal apa yang ingin Anda capai, {$this->userName}? Contoh: \"buat goal selesaikan 10 tugas minggu ini\"",
                "💭 What goal do you want to set, {$this->userName}? Example: \"set goal complete 10 tasks this week\""
            ));
        }

        $goalTitle = mb_strtoupper(mb_substr($goalText, 0, 1)) . mb_substr($goalText, 1);

        // Create a special goal task with deadline end of week
        $deadline = $this->now->copy()->endOfWeek()->setTime(23, 59);
        $data = [
            'judul'    => "🎯 Goal: {$goalTitle}",
            'priority' => 'high',
            'deadline' => $deadline->format('Y-m-d H:i:s'),
            'deskripsi' => "Weekly goal set on " . $this->now->format('d M Y'),
        ];

        return $this->respond(
            $this->t(
                "🎯 Goal \"{$goalTitle}\" telah ditetapkan hingga {$deadline->format('l, d M Y')}. Semangat!",
                "🎯 Goal \"{$goalTitle}\" has been set with deadline {$deadline->format('l, d M Y')}. Go for it!"
            ),
            ['type' => 'create_task', 'data' => $data],
            ['check_goal', 'focus_mode', 'productivity']
        );
    }

    private function respondCheckGoal(): array
    {
        $goals = $this->todos->filter(fn($t) => Str::startsWith($t->judul, '🎯 Goal:'));

        if ($goals->isEmpty()) {
            return $this->respond($this->t(
                "Belum ada goal yang ditetapkan. Ucapkan \"buat goal [tujuan Anda]\" untuk mulai.",
                "No goals set yet. Say \"set goal [your objective]\" to start."
            ));
        }

        $lines = [];
        foreach ($goals as $g) {
            $status   = $g->is_completed ? '✅' : '⬜';
            $deadline = $g->deadline ? Carbon::parse($g->deadline)->format('d M') : '—';
            $overdue  = (!$g->is_completed && $g->deadline && Carbon::parse($g->deadline)->isPast()) ? ' ⚠️' : '';
            $lines[]  = "{$status} " . str_replace('🎯 Goal: ', '', $g->judul) . " (deadline: {$deadline}){$overdue}";
        }

        $completed = $goals->where('is_completed', true)->count();
        $total     = $goals->count();

        $header = $this->t("🎯 **Goal Tracker**, {$this->userName} ({$completed}/{$total} tercapai):\n\n",
                            "🎯 **Goal Tracker**, {$this->userName} ({$completed}/{$total} achieved):\n\n");
        return $this->respond($header . implode("\n", $lines), null, ['set_goal', 'productivity']);
    }

    // ═══════════════════════════════════════════════════════════════
    // NEW: BATCH UPDATE (multiple tasks at once)
    // ═══════════════════════════════════════════════════════════════

    private function parseBatchUpdate(string $msg, string $original): ?array
    {
        // "ganti semua deadline ke besok"
        if (preg_match('/\b(ganti|update|set|ubah)\s*(semua|all)\s*(deadline|tenggat)\b/i', $msg)) {
            $newDeadline = $this->parseDeadline($msg);
            if (!$newDeadline) {
                return $this->respond($this->t(
                    "Mau diubah ke tanggal berapa, {$this->userName}?",
                    "What date do you want to change to, {$this->userName}?"
                ));
            }
            $pending = $this->todos->where('is_completed', false);
            if ($pending->isEmpty()) {
                return $this->respond($this->t("Tidak ada tugas pending.", "No pending tasks."));
            }
            $ids = $pending->pluck('id')->toArray();
            return $this->respond(
                $this->t(
                    "Baik {$this->userName}, deadline semua {$pending->count()} tugas pending diubah ke {$this->formatDate($newDeadline)}.",
                    "Done {$this->userName}, deadline for all {$pending->count()} pending tasks changed to {$this->formatDate($newDeadline)}."
                ),
                ['type' => 'batch_update_deadline', 'data' => ['ids' => $ids, 'deadline' => $newDeadline->format('Y-m-d H:i:s')]]
            );
        }

        // "ganti semua prioritas ke medium"
        if (preg_match('/\b(ganti|update|set|ubah)\s*(semua|all)\s*(prioritas|priority)\b/i', $msg)) {
            $newPriority = $this->parsePriorityValue($msg);
            if (!$newPriority) {
                return $this->respond($this->t(
                    "Prioritas apa yang diinginkan? (high / medium / low)",
                    "What priority? (high / medium / low)"
                ));
            }
            $pending = $this->todos->where('is_completed', false);
            $ids     = $pending->pluck('id')->toArray();
            return $this->respond(
                $this->t(
                    "Baik {$this->userName}, semua {$pending->count()} tugas pending diubah ke prioritas {$newPriority}.",
                    "Done {$this->userName}, all {$pending->count()} pending tasks set to {$newPriority} priority."
                ),
                ['type' => 'batch_update_priority', 'data' => ['ids' => $ids, 'priority' => $newPriority]]
            );
        }

        return null;
    }

    // ═══════════════════════════════════════════════════════════════
    // EXISTING FEATURES — Enhanced versions
    // ═══════════════════════════════════════════════════════════════

    private function respondFocusMode(): array
    {
        $pending = $this->todos->where('is_completed', false);
        if ($pending->isEmpty()) {
            return $this->respond($this->t(
                "✅ Tidak ada tugas pending. Anda sudah menyelesaikan semuanya, {$this->userName}!",
                "✅ No pending tasks. You've completed everything, {$this->userName}!"
            ));
        }

        $scored = $pending->map(function ($t) {
            $score = 0;
            if ($t->deadline && Carbon::parse($t->deadline)->isPast())    $score += 100;
            if ($t->deadline && Carbon::parse($t->deadline)->isToday())   $score += 80;
            if ($t->deadline && Carbon::parse($t->deadline)->isTomorrow()) $score += 60;
            $score += match($t->priority ?? 'medium') { 'high' => 40, 'medium' => 20, 'low' => 5, default => 10 };
            if ($t->deadline) {
                $hoursLeft = Carbon::now()->diffInHours(Carbon::parse($t->deadline), false);
                if ($hoursLeft > 0 && $hoursLeft < 48) $score += (48 - $hoursLeft);
            }
            return ['task' => $t, 'score' => $score];
        })->sortByDesc('score');

        $top      = $scored->first()['task'];
        $topScore = $scored->first()['score'];
        $deadline = $top->deadline ? Carbon::parse($top->deadline)->format('d M Y H:i') : null;
        $priority = strtoupper($top->priority ?? 'medium');

        // Estimate time to complete based on priority
        $estTime = match($top->priority ?? 'medium') { 'high' => '90 min', 'medium' => '45 min', 'low' => '20 min', default => '45 min' };

        if ($this->lang === 'en') {
            $text  = "🎯 **FOCUS MODE**, {$this->userName}\n\n";
            $text .= "Your #1 priority right now:\n";
            $text .= "━━━━━━━━━━━━━━━━━━━━━━━━\n";
            $text .= "📌 \"{$top->judul}\"\n";
            $text .= "Priority: {$priority} | Est. Time: ~{$estTime}";
            $text .= $deadline ? " | Deadline: {$deadline}\n" : "\n";
            if ($topScore >= 100)    $text .= "⚠️ This task is OVERDUE — complete it immediately!\n";
            elseif ($topScore >= 80) $text .= "⏰ Due TODAY — focus on this first.\n";
            elseif ($topScore >= 60) $text .= "📅 Due tomorrow — get a head start.\n";
            $text .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
            if ($scored->count() > 1) $text .= "Up next: \"{$scored->values()[1]['task']->judul}\"";
        } else {
            $text  = "🎯 **MODE FOKUS**, {$this->userName}\n\n";
            $text .= "Prioritas #1 Anda sekarang:\n";
            $text .= "━━━━━━━━━━━━━━━━━━━━━━━━\n";
            $text .= "📌 \"{$top->judul}\"\n";
            $text .= "Prioritas: {$priority} | Estimasi: ~{$estTime}";
            $text .= $deadline ? " | Deadline: {$deadline}\n" : "\n";
            if ($topScore >= 100)    $text .= "⚠️ Tugas ini sudah TERLAMBAT — selesaikan segera!\n";
            elseif ($topScore >= 80) $text .= "⏰ Jatuh tempo HARI INI — fokus ke ini dulu.\n";
            elseif ($topScore >= 60) $text .= "📅 Jatuh tempo besok — mulai dari sekarang.\n";
            $text .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
            if ($scored->count() > 1) $text .= "Berikutnya: \"{$scored->values()[1]['task']->judul}\"";
        }

        return $this->respond($text, null, ['pomodoro', 'deep_work', 'daily_planner']);
    }

    private function respondSmartSuggestions(): array
    {
        $pending     = $this->todos->where('is_completed', false);
        $completed   = $this->todos->where('is_completed', true);
        $suggestions = [];

        $hasHighPriority = $pending->where('priority', 'high')->isNotEmpty();
        $hasDeadlines    = $pending->filter(fn($t) => $t->deadline)->isNotEmpty();
        $overdue         = $pending->filter(fn($t) => $t->deadline && Carbon::parse($t->deadline)->isPast())->count();

        if ($this->lang === 'en') {
            if ($overdue > 0)
                $suggestions[] = "⚠️ {$overdue} overdue task(s). Say \"reschedule overdue tasks\" to fix them.";
            if (!$hasDeadlines && $pending->count() > 0)
                $suggestions[] = "📅 None of your tasks have deadlines. Add deadlines to stay on track.";
            if ($pending->count() > 10)
                $suggestions[] = "📊 {$pending->count()} pending tasks. Try the daily planner to break them down.";
            if ($pending->where('priority', 'low')->count() > 5)
                $suggestions[] = "🔻 Many low-priority tasks. Consider \"delete all low priority tasks\".";
            if ($completed->count() > 0 && $pending->count() == 0)
                $suggestions[] = "🎉 All tasks done! Set a new goal with \"set goal [objective]\".";
            if ($pending->count() == 0 && $completed->count() == 0)
                $suggestions[] = "📝 Task list is empty. Try \"template project\" to quick-start!";
            if (!$hasHighPriority && $pending->count() > 3)
                $suggestions[] = "⬆️ No high-priority tasks. Try \"auto prioritize\" for smart suggestions.";
            // Eisenhower tip
            if ($pending->count() >= 4)
                $suggestions[] = "🧭 Try \"eisenhower matrix\" to visualize urgency vs importance.";
            // Streak tip
            if ($completed->count() > 0)
                $suggestions[] = "🏆 Say \"show my achievements\" to see your streak and badges!";
            $hour = $this->now->hour;
            if ($hour >= 8 && $hour <= 10)
                $suggestions[] = "🌅 Morning! Perfect for deep work. Try \"deep work schedule\".";
            elseif ($hour >= 14 && $hour <= 15)
                $suggestions[] = "☕ Post-lunch slump? Try \"start pomodoro\" for a quick focus session.";
            elseif ($hour >= 17)
                $suggestions[] = "🌙 End of day — try \"weekly review\" to see your progress.";
            $text = "💡 Smart Suggestions, {$this->userName}:\n\n";
        } else {
            if ($overdue > 0)
                $suggestions[] = "⚠️ Ada {$overdue} tugas terlambat. Ucapkan \"jadwalkan ulang tugas terlambat\".";
            if (!$hasDeadlines && $pending->count() > 0)
                $suggestions[] = "📅 Tidak ada tugas yang punya deadline. Tambahkan agar tetap on track.";
            if ($pending->count() > 10)
                $suggestions[] = "📊 {$pending->count()} tugas pending. Coba \"rencana harian\" untuk membaginya.";
            if ($pending->where('priority', 'low')->count() > 5)
                $suggestions[] = "🔻 Banyak tugas prioritas rendah. Pertimbangkan menghapusnya.";
            if ($completed->count() > 0 && $pending->count() == 0)
                $suggestions[] = "🎉 Semua selesai! Buat goal baru dengan \"buat goal [tujuan]\".";
            if ($pending->count() == 0 && $completed->count() == 0)
                $suggestions[] = "📝 Daftar tugas kosong. Coba \"template project\" untuk mulai cepat!";
            if (!$hasHighPriority && $pending->count() > 3)
                $suggestions[] = "⬆️ Belum ada tugas prioritas tinggi. Coba \"auto prioritas\".";
            if ($pending->count() >= 4)
                $suggestions[] = "🧭 Coba \"eisenhower matrix\" untuk visualisasi urgensi vs kepentingan.";
            if ($completed->count() > 0)
                $suggestions[] = "🏆 Ucapkan \"lihat pencapaian\" untuk melihat streak dan lencana Anda!";
            $hour = $this->now->hour;
            if ($hour >= 8 && $hour <= 10)
                $suggestions[] = "🌅 Pagi! Cocok untuk deep work. Coba \"jadwal deep work\".";
            elseif ($hour >= 14 && $hour <= 15)
                $suggestions[] = "☕ Ngantuk setelah makan siang? Coba \"mulai pomodoro\".";
            elseif ($hour >= 17)
                $suggestions[] = "🌙 Menjelang malam — coba \"review mingguan\" untuk melihat progres.";
            $text = "💡 Saran Cerdas, {$this->userName}:\n\n";
        }

        if (empty($suggestions))
            $suggestions[] = $this->t("✅ Semuanya terlihat bagus! Terus pertahankan momentum Anda.", "✅ Everything looks great! Keep up the momentum.");

        foreach ($suggestions as $i => $s)
            $text .= ($i + 1) . ". {$s}\n";

        return $this->respond($text, null, ['focus_mode', 'eisenhower', 'deep_work']);
    }

    private function respondDailyPlanner(): array
    {
        $pending = $this->todos->where('is_completed', false);
        if ($pending->isEmpty()) {
            return $this->respond($this->t(
                "📋 Tidak ada tugas pending untuk direncanakan, {$this->userName}. Tambahkan tugas dulu!",
                "📋 No pending tasks to plan, {$this->userName}. Add some tasks first!"
            ));
        }

        $sorted = $pending->sortBy(function ($t) {
            $score = 0;
            if ($t->deadline && Carbon::parse($t->deadline)->isPast())    $score -= 1000;
            if ($t->deadline && Carbon::parse($t->deadline)->isToday())   $score -= 500;
            if ($t->deadline && Carbon::parse($t->deadline)->isTomorrow()) $score -= 200;
            $score -= match($t->priority ?? 'medium') { 'high' => 100, 'medium' => 50, 'low' => 10, default => 25 };
            return $score;
        });

        $morningSlot = [];
        $afternoonSlot = [];
        $eveningSlot = [];
        $i = 0;
        foreach ($sorted->take(9) as $t) {
            $entry = "• \"{$t->judul}\" [{$t->priority}]";
            if ($i < 3)      $morningSlot[]   = $entry;
            elseif ($i < 6)  $afternoonSlot[] = $entry;
            else              $eveningSlot[]   = $entry;
            $i++;
        }

        $backlogCount = max(0, $sorted->count() - 9);
        $dayName = \Illuminate\Support\Carbon::parse($this->now)->locale($this->lang === 'id' ? 'id' : 'en')->dayName . ', ' . \Illuminate\Support\Carbon::parse($this->now)->format('j F Y');

        if ($this->lang === 'en') {
            $text  = "📋 **DAILY PLAN** — {$dayName}\n";
            $text .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
            if (!empty($morningSlot))   $text .= "🌅 **Morning (08:00-12:00)**\n"   . implode("\n", $morningSlot)   . "\n\n";
            if (!empty($afternoonSlot)) $text .= "☀️ **Afternoon (13:00-17:00)**\n" . implode("\n", $afternoonSlot) . "\n\n";
            if (!empty($eveningSlot))   $text .= "🌙 **Evening (18:00-21:00)**\n"   . implode("\n", $eveningSlot)   . "\n\n";
            if ($backlogCount > 0)      $text .= "📦 +{$backlogCount} more in backlog\n";
            $text .= "\n💡 Say \"deep work schedule\" for time-blocked sessions!";
        } else {
            $text  = "📋 **RENCANA HARIAN** — {$dayName}\n";
            $text .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
            if (!empty($morningSlot))   $text .= "🌅 **Pagi (08:00-12:00)**\n"   . implode("\n", $morningSlot)   . "\n\n";
            if (!empty($afternoonSlot)) $text .= "☀️ **Siang (13:00-17:00)**\n" . implode("\n", $afternoonSlot) . "\n\n";
            if (!empty($eveningSlot))   $text .= "🌙 **Malam (18:00-21:00)**\n"  . implode("\n", $eveningSlot)   . "\n\n";
            if ($backlogCount > 0)      $text .= "📦 +{$backlogCount} lagi di backlog\n";
            $text .= "\n💡 Ucapkan \"jadwal deep work\" untuk sesi kerja terblok!";
        }

        return $this->respond($text, null, ['deep_work', 'pomodoro', 'focus_mode']);
    }

    private function respondWeeklyReview(): array
    {
        $startOfWeek = $this->now->copy()->startOfWeek();
        $endOfWeek   = $this->now->copy()->endOfWeek();

        $completedThisWeek = $this->todos->where('is_completed', true)
            ->filter(fn($t) => $t->updated_at && Carbon::parse($t->updated_at)->between($startOfWeek, $endOfWeek))
            ->count();

        $createdThisWeek = $this->todos
            ->filter(fn($t) => $t->created_at && Carbon::parse($t->created_at)->between($startOfWeek, $endOfWeek))
            ->count();

        $overdue      = $this->todos->where('is_completed', false)
            ->filter(fn($t) => $t->deadline && Carbon::parse($t->deadline)->isPast())->count();
        $pendingCount = $this->todos->where('is_completed', false)->count();
        $totalCompleted = $this->todos->where('is_completed', true)->count();
        $total        = $this->todos->count();
        $completionRate = $total > 0 ? round(($totalCompleted / $total) * 100) : 0;

        if ($completionRate >= 80) $rating = '🌟🌟🌟🌟🌟';
        elseif ($completionRate >= 60) $rating = '🌟🌟🌟🌟';
        elseif ($completionRate >= 40) $rating = '🌟🌟🌟';
        elseif ($completionRate >= 20) $rating = '🌟🌟';
        else $rating = '🌟';

        // Velocity: tasks completed this week / tasks created this week
        $velocity = $createdThisWeek > 0 ? round(($completedThisWeek / $createdThisWeek) * 100) : 0;

        if ($this->lang === 'en') {
            $text  = "📊 **WEEKLY REVIEW** — Week of {$startOfWeek->format('d M')}\n";
            $text .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
            $text .= "Rating: {$rating} ({$completionRate}%)\n";
            $text .= "⚡ Velocity: {$velocity}% (completed/created ratio)\n\n";
            $text .= "📈 **This Week**\n";
            $text .= "• Created: {$createdThisWeek} | Completed: {$completedThisWeek}\n";
            $text .= "• Still Pending: {$pendingCount} | Overdue: {$overdue}\n\n";
            if ($completedThisWeek > 5)     $text .= "🔥 Outstanding productivity! You crushed it.\n";
            elseif ($completedThisWeek > 0) $text .= "👍 Good progress. Keep the momentum!\n";
            else                             $text .= "💪 Slow week? Let's make next week better!\n";
            if ($overdue > 0) $text .= "⚠️ Address {$overdue} overdue task(s) before the weekend.\n";
            $text .= "\n💡 Say \"show my achievements\" to see your badges!";
        } else {
            $text  = "📊 **REVIEW MINGGUAN** — Minggu {$startOfWeek->format('d M')}\n";
            $text .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
            $text .= "Rating: {$rating} ({$completionRate}%)\n";
            $text .= "⚡ Kecepatan: {$velocity}% (rasio selesai/dibuat)\n\n";
            $text .= "📈 **Minggu Ini**\n";
            $text .= "• Dibuat: {$createdThisWeek} | Selesai: {$completedThisWeek}\n";
            $text .= "• Masih Pending: {$pendingCount} | Terlambat: {$overdue}\n\n";
            if ($completedThisWeek > 5)     $text .= "🔥 Produktivitas luar biasa! Anda hebat minggu ini.\n";
            elseif ($completedThisWeek > 0) $text .= "👍 Progres bagus. Pertahankan momentum!\n";
            else                             $text .= "💪 Minggu yang lambat? Mari buat minggu depan lebih baik!\n";
            if ($overdue > 0) $text .= "⚠️ Selesaikan {$overdue} tugas terlambat sebelum akhir minggu.\n";
            $text .= "\n💡 Ucapkan \"lihat pencapaian\" untuk melihat lencana Anda!";
        }

        return $this->respond($text, null, ['streak', 'productivity', 'stats']);
    }

    private function respondWorkloadAnalysis(): array
    {
        $pending      = $this->todos->where('is_completed', false);
        $total        = $pending->count();
        $high         = $pending->where('priority', 'high')->count();
        $overdue      = $pending->filter(fn($t) => $t->deadline && Carbon::parse($t->deadline)->isPast())->count();
        $todayCount   = $pending->filter(fn($t) => $t->deadline && Carbon::parse($t->deadline)->isToday())->count();
        $tomorrowCount= $pending->filter(fn($t) => $t->deadline && Carbon::parse($t->deadline)->isTomorrow())->count();
        $thisWeek     = $pending->filter(fn($t) => $t->deadline && Carbon::parse($t->deadline)->isBetween($this->now, $this->now->copy()->endOfWeek()))->count();

        $stress = min(100, ($total * 5) + ($high * 15) + ($overdue * 25) + ($todayCount * 10));

        if ($stress >= 80)      { $level = $this->t('🔴 KRITIS','🔴 CRITICAL'); $emoji = '🚨'; }
        elseif ($stress >= 60)  { $level = $this->t('🟠 TINGGI','🟠 HIGH');    $emoji = '⚡'; }
        elseif ($stress >= 40)  { $level = $this->t('🟡 SEDANG','🟡 MODERATE'); $emoji = '📊'; }
        elseif ($stress >= 20)  { $level = $this->t('🟢 RINGAN','🟢 LIGHT');   $emoji = '✨'; }
        else                    { $level = $this->t('💚 SANTAI','💚 RELAXED'); $emoji = '🏖️'; }

        if ($this->lang === 'en') {
            $text  = "{$emoji} **WORKLOAD ANALYSIS**, {$this->userName}\n";
            $text .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
            $text .= "Stress Level: {$level} ({$stress}/100)\n\n";
            $text .= "📋 Total Pending: {$total}\n";
            $text .= "🔴 High Priority: {$high} | ⚠️ Overdue: {$overdue}\n";
            $text .= "📅 Today: {$todayCount} | 📆 Tomorrow: {$tomorrowCount}\n";
            $text .= "📊 This Week: {$thisWeek}\n\n";
            if ($stress >= 80)     $text .= "💡 You're overloaded. Try \"eisenhower matrix\" to eliminate tasks.";
            elseif ($stress >= 60) $text .= "💡 Heavy workload. Focus on Q1 (urgent + important) first.";
            elseif ($stress >= 40) $text .= "💡 Manageable load. Stay focused — you'll clear this.";
            else                   $text .= "💡 Light workload. Great time to plan ahead or set a new goal!";
        } else {
            $text  = "{$emoji} **ANALISIS BEBAN KERJA**, {$this->userName}\n";
            $text .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
            $text .= "Level Stress: {$level} ({$stress}/100)\n\n";
            $text .= "📋 Total Pending: {$total}\n";
            $text .= "🔴 Prioritas Tinggi: {$high} | ⚠️ Terlambat: {$overdue}\n";
            $text .= "📅 Hari Ini: {$todayCount} | 📆 Besok: {$tomorrowCount}\n";
            $text .= "📊 Minggu Ini: {$thisWeek}\n\n";
            if ($stress >= 80)     $text .= "💡 Anda overload. Coba \"eisenhower matrix\" untuk eliminasi tugas.";
            elseif ($stress >= 60) $text .= "💡 Beban berat. Fokus ke Q1 (mendesak + penting) dulu.";
            elseif ($stress >= 40) $text .= "💡 Beban bisa dikelola. Tetap fokus.";
            else                   $text .= "💡 Beban ringan. Waktu yang tepat untuk merencanakan!";
        }

        return $this->respond($text, null, ['eisenhower', 'focus_mode', 'reschedule_overdue']);
    }

    private function respondTaskAnalysis(string $msg): array
    {
        $taskName = preg_replace('/\b(analyze|analisis|analyse|breakdown|detail|task|tugas|info|explain|jelaskan)\b/i', '', $msg);
        $taskName = trim($taskName);

        if (empty($taskName)) return $this->respondWorkloadAnalysis();

        $task = $this->findTask($taskName);
        if (!$task) return $this->respondTaskNotFound($taskName);

        $deadline = $task->deadline ? Carbon::parse($task->deadline) : null;
        $priority = strtoupper($task->priority ?? 'medium');

        if ($this->lang === 'en') {
            $text  = "🔍 **TASK ANALYSIS**\n";
            $text .= "━━━━━━━━━━━━━━━━━━━━━━━━\n";
            $text .= "📌 Title: \"{$task->judul}\"\n";
            $text .= "📊 Priority: {$priority}\n";
            $text .= "📋 Status: " . ($task->is_completed ? '✅ Completed' : '⬜ Pending') . "\n";
            if ($task->deskripsi) $text .= "📝 Desc: {$task->deskripsi}\n";
            if ($deadline) {
                $text .= "📅 Deadline: {$deadline->format('d M Y H:i')}\n";
                if (!$task->is_completed) {
                    if ($deadline->isPast()) {
                        $days = $this->now->diffInDays($deadline);
                        $text .= "⚠️ OVERDUE by {$days} day(s)!\n";
                    } else {
                        $text .= "⏳ Remaining: " . $this->now->diffForHumans($deadline, ['parts' => 2]) . "\n";
                    }
                }
            } else $text .= "📅 Deadline: Not set\n";
            $text .= "🆔 ID: {$task->id}\n";
        } else {
            $text  = "🔍 **ANALISIS TUGAS**\n";
            $text .= "━━━━━━━━━━━━━━━━━━━━━━━━\n";
            $text .= "📌 Judul: \"{$task->judul}\"\n";
            $text .= "📊 Prioritas: {$priority}\n";
            $text .= "📋 Status: " . ($task->is_completed ? '✅ Selesai' : '⬜ Belum') . "\n";
            if ($task->deskripsi) $text .= "📝 Deskripsi: {$task->deskripsi}\n";
            if ($deadline) {
                $text .= "📅 Deadline: {$deadline->format('d M Y H:i')}\n";
                if (!$task->is_completed) {
                    if ($deadline->isPast()) {
                        $days = $this->now->diffInDays($deadline);
                        $text .= "⚠️ TERLAMBAT {$days} hari!\n";
                    } else {
                        $text .= "⏳ Sisa: " . $this->now->diffForHumans($deadline, ['parts' => 2]) . "\n";
                    }
                }
            } else $text .= "📅 Deadline: Belum diatur\n";
            $text .= "🆔 ID: {$task->id}\n";
        }

        return $this->respond($text);
    }

    private function respondHabitTracker(): array
    {
        $titleCounts = [];
        foreach ($this->todos as $t) {
            $normalized = mb_strtolower(trim($t->judul));
            if (!isset($titleCounts[$normalized]))
                $titleCounts[$normalized] = ['count' => 0, 'title' => $t->judul, 'completed' => 0];
            $titleCounts[$normalized]['count']++;
            if ($t->is_completed) $titleCounts[$normalized]['completed']++;
        }
        $habits = collect($titleCounts)->filter(fn($v) => $v['count'] >= 2)->sortByDesc('count');

        if ($this->lang === 'en') {
            $text  = "🔄 **HABIT TRACKER**, {$this->userName}\n";
            $text .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
            if ($habits->isEmpty()) {
                $text .= "No recurring patterns yet.\n\n💡 Suggested habits:\n";
                $text .= "• \"Daily standup\" | • \"Exercise\" | • \"Read 30 min\"\n";
            } else {
                foreach ($habits->take(8)->toArray() as $h) {
                    $rate   = $h['count'] > 0 ? (int) round(($h['completed'] / $h['count']) * 100) : 0;
                    $streak = $rate >= 80 ? '🔥' : ($rate >= 50 ? '⚡' : '💤');
                    $text  .= "{$streak} \"{$h['title']}\" — {$h['count']}x created, {$h['completed']}x done ({$rate}%)\n";
                }
            }
        } else {
            $text  = "🔄 **PELACAK KEBIASAAN**, {$this->userName}\n";
            $text .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
            if ($habits->isEmpty()) {
                $text .= "Belum ada pola berulang.\n\n💡 Saran kebiasaan:\n";
                $text .= "• \"Daily standup\" | • \"Olahraga\" | • \"Baca 30 menit\"\n";
            } else {
                foreach ($habits->take(8)->toArray() as $h) {
                    $rate   = $h['count'] > 0 ? (int) round(($h['completed'] / $h['count']) * 100) : 0;
                    $streak = $rate >= 80 ? '🔥' : ($rate >= 50 ? '⚡' : '💤');
                    $text  .= "{$streak} \"{$h['title']}\" — {$h['count']}x dibuat, {$h['completed']}x selesai ({$rate}%)\n";
                }
            }
        }

        return $this->respond($text);
    }

    private function respondSmartPrioritize(): array
    {
        $pending = $this->todos->where('is_completed', false);
        if ($pending->isEmpty()) {
            return $this->respond($this->t("Tidak ada tugas pending.", "No pending tasks."));
        }

        $suggestions = [];
        foreach ($pending as $t) {
            $current  = $t->priority ?? 'medium';
            $suggested = $current;
            $reason = '';
            if ($t->deadline) {
                $dl        = \Illuminate\Support\Carbon::parse($t->deadline);
                $hoursLeft = $this->now->diffInHours($dl, false);
                if ($dl->isPast()) {
                    $suggested = 'high';
                    $reason    = $this->t('Sudah terlambat', 'Already overdue');
                } elseif ($hoursLeft <= 24) {
                    $suggested = 'high';
                    $reason    = $this->t('< 24 jam', '< 24h left');
                } elseif ($hoursLeft <= 72) {
                    $suggested = $current === 'low' ? 'medium' : $current;
                    $reason    = $this->t('< 3 hari', '< 3 days');
                }
            }
            if ($suggested !== $current)
                $suggestions[] = ['task' => $t, 'from' => $current, 'to' => $suggested, 'reason' => $reason];
        }

        if (empty($suggestions)) {
            return $this->respond($this->t(
                "✅ Semua prioritas sudah optimal, {$this->userName}.",
                "✅ All priorities are already optimal, {$this->userName}."
            ));
        }

        $header = $this->t("🎯 Saran Prioritas Cerdas:\n\n", "🎯 Smart Priority Suggestions:\n\n");
        $lines  = [];
        foreach ($suggestions as $s) {
            $lines[] = "• \"{$s['task']->judul}\" — " . strtoupper($s['from']) . " → " . strtoupper($s['to']) . " ({$s['reason']})";
        }
        $footer = $this->t("\n\n💡 Ucapkan \"ubah prioritas [nama] ke high\" untuk menerapkan.",
                            "\n\n💡 Say \"change priority of [task] to high\" to apply.");

        return $this->respond($header . implode("\n", $lines) . $footer);
    }

    // ═══════════════════════════════════════════════════════════════
    // MULTI-INTENT CREATE
    // ═══════════════════════════════════════════════════════════════

    private function parseMultiCreate(string $msg, string $original): ?array
    {
        if (!$this->hasCreateKeyword($msg)) return null;

        $stripped = preg_replace('/\b(buat|bikin|tambah|create|add|buat ?kan|tambah ?kan|new task|catat)\b\s*(tugas|task|todo|tasks)?\s*/i', '', $msg);
        $stripped = trim($stripped, ' ,:');

        $parts = preg_split('/\s*(?:,\s*(?:dan|and)\s*|\s+dan\s+|\s+and\s+|,\s*)\s*/i', $stripped);
        $parts = array_filter($parts, fn($p) => mb_strlen(trim($p)) >= 2);

        if (count($parts) < 2) return null;

        $actions = [];
        $titles  = [];
        foreach ($parts as $part) {
            $part     = trim($part);
            $priority = 'medium';
            if (preg_match('/\b(high|tinggi|penting|urgent)\b/i', $part)) {
                $priority = 'high';
                $part     = preg_replace('/\b(high|tinggi|penting|urgent)\s*(priority|prioritas)?\b/i', '', $part);
            } elseif (preg_match('/\b(low|rendah|santai)\b/i', $part)) {
                $priority = 'low';
                $part     = preg_replace('/\b(low|rendah|santai)\s*(priority|prioritas)?\b/i', '', $part);
            }

            $deadline = $this->parseDeadline($part);
            if ($deadline) $part = $this->removeDeadlineText($part);

            $title = $this->cleanTitle($part);
            if (mb_strlen($title) < 2) continue;
            $title    = mb_strtoupper(mb_substr($title, 0, 1)) . mb_substr($title, 1);
            $titles[] = $title;
            $data     = ['judul' => $title, 'priority' => $priority];
            if ($deadline) $data['deadline'] = $deadline->format('Y-m-d H:i:s');
            $actions[] = ['type' => 'create_task', 'data' => $data];
        }

        if (count($actions) < 2) return null;

        $titleList = implode(', ', array_map(fn($t) => "\"{$t}\"", $titles));
        $message   = "Baik {$this->userName}, saya sudah membuat " . count($actions) . " tugas: {$titleList}. Ada lagi?";

        return $this->respond($message, ['type' => 'batch_create', 'data' => ['tasks' => $actions]]);
    }

    // ═══════════════════════════════════════════════════════════════
    // CREATE (with duplicate check)
    // ═══════════════════════════════════════════════════════════════

    private function parseCreateWithDuplicateCheck(string $msg, string $original): ?array
    {
        $create = $this->parseCreate($msg, $original);
        if ($create === null) return null;

        $decoded = json_decode($create['content'] ?? '', true);
        if (!$decoded || empty($decoded['action']['data']['judul'])) return $create;

        $newTitle = mb_strtolower($decoded['action']['data']['judul']);
        foreach ($this->todos as $existing) {
            $existingTitle = mb_strtolower($existing->judul);
            similar_text($newTitle, $existingTitle, $similarity);
            if ($similarity >= 80) {
                $status = $existing->is_completed ? '✅' : '⬜';
                $warning = $this->t(
                    "⚠️ Tugas serupa sudah ada: {$status} \"{$existing->judul}\" (ID: {$existing->id}). Tetap dibuat?",
                    "⚠️ Similar task exists: {$status} \"{$existing->judul}\" (ID: {$existing->id}). Create anyway?"
                );
                $decoded['message'] = $warning . "\n\n" . ($decoded['message'] ?? '');
                $create['content']  = json_encode($decoded);
                return $create;
            }
        }
        return $create;
    }

    private function parseCreate(string $msg, string $original): ?array
    {
        if (!$this->hasCreateKeyword($msg)) return null;

        $stripped = preg_replace('/\b(?:saya|aku|ingin|mau|pengen|gue|elo|lu|kita|sudah|udah|tgs|tugas|task|todo|\s+)*(?:buat|bikin|tambah|create|add|buat ?kan|tambah ?kan|new task|catat|tambahin)\b\s*(?:tgs|tugas|task|todo)?\s*/i', '', $msg);
        $stripped = trim($stripped, ' ,:');

        // Extract what we can from the first message
        $priority = $this->parsePriorityValue($msg);
        $deadline = $this->parseDeadline($msg);
        
        // Title is what remains after stripping keywords and deadline text
        $titleText = $this->removeDeadlineText($stripped);
        // Also remove priority keywords from title
        $titleText = preg_replace('/\b(prioritas|priority)\s+(tinggi|sedang|rendah|high|medium|low)\b/i', '', $titleText);
        $titleText = preg_replace('/\b(tinggi|sedang|rendah|high|medium|low)\b/i', '', $titleText);
        
        $title = $this->cleanTitle($titleText);

        // Try to extract description if "deskripsi" keyword exists
        $desc = null;
        if (preg_match('/\b(?:deskripsi|catatan|notes|dengan isi)\s+(.*?)(?:\s+|$)/i', $msg, $dm)) {
            $desc = trim($dm[1]);
            // Remove desc from title if it was caught there
            $title = str_ireplace($desc, '', $title);
            $title = preg_replace('/\b(?:deskripsi|catatan|notes|dengan isi)\b/i', '', $title);
            $title = trim($title, ' ,:');
        }

        $data = [
            'judul'     => $title,
            'deskripsi' => $desc,
            'date'      => $deadline ? $deadline->format('Y-m-d') : null,
            'time'      => $deadline ? $deadline->format('H:i') : null,
            'priority'  => $priority,
        ];

        return $this->nextCreationStep($data);
    }

    private function handleConversationalFlow(string $msg, string $original): ?array
    {
        if (!$this->memory) return null;

        // ── 0. Global Exit / Cancel Handler ──────────────────────────
        if ($this->isCancel($msg)) {
            $sessions = ['creating_task_state', 'editing_task_state', 'deleting_task_state', 'listing_task_state'];
            $active = false;
            foreach ($sessions as $s) {
                if ($this->memory->recall('context', $s)) {
                    $this->memory->forget('context', $s);
                    $active = true;
                }
            }
            if ($active) {
                return $this->respond($this->t(
                    "Baik Tuan, sesi dibatalkan. Ada lagi yang bisa saya bantu?",
                    "Alright Sir, session cancelled. Is there anything else I can help with?"
                ));
            }
        }

        // ── 1. Task Creation Session ────────────────────────────────
        $createState = $this->memory->recall('context', 'creating_task_state');
        if ($createState) return $this->handleCreationSession($msg, $original, $createState);

        // ── 2. Task Editing Session ─────────────────────────────────
        $editState = $this->memory->recall('context', 'editing_task_state');
        if ($editState) return $this->handleEditingSession($msg, $original, $editState);

        // ── 3. Task Deleting Session ────────────────────────────────
        $deleteState = $this->memory->recall('context', 'deleting_task_state');
        if ($deleteState) return $this->handleDeletingSession($msg, $original, $deleteState);

        // ── 4. Task Listing Session ─────────────────────────────────
        $listState = $this->memory->recall('context', 'listing_task_state');
        if ($listState) return $this->handleListingSession($msg, $original, $listState);

        return null;
    }

    private function handleCreationSession(string $msg, string $original, array $state): array
    {
        $data = $state['data'] ?? [];
        $currentStep = $state['step'] ?? 'ask_title';

        // 1. Greedy match: Scan for ANY missing info first, regardless of the current step
        $potentialDate = $this->parseDeadline($msg);
        if ($potentialDate && empty($data['date'])) {
            $data['date'] = $potentialDate->format('Y-m-d');
            if (preg_match('/\b(?:jam|pagi|siang|sore|malam|at|on)\b/i', $msg)) {
                $data['time'] = $potentialDate->format('H:i');
            }
        }

        $potentialPriority = $this->parsePriorityValue($msg);
        if ($potentialPriority && empty($data['priority'])) {
            $data['priority'] = $potentialPriority;
        }

        // 2. Process the input for the specific step if not already captured by greedy match
        switch ($currentStep) {
            case 'ask_title':
                // Check if they are providing the title (e.g., "Judulnya Meeting")
                $cleaned = $this->cleanTitle($msg);
                if (!empty($cleaned)) {
                    $data['judul'] = $cleaned;
                }
                break;
            case 'ask_description':
                if (!$this->isNegative($msg)) {
                    // Only set description if it doesn't look like they are answering a different step
                    if (!$potentialDate && !$potentialPriority) {
                        $data['deskripsi'] = $original;
                    }
                } else {
                    $data['deskripsi'] = null; // skip
                }
                break;
            case 'ask_date':
                if ($potentialDate) {
                    $data['date'] = $potentialDate->format('Y-m-d');
                }
                break;
            case 'ask_time':
                $time = $this->parseDeadline($msg);
                if ($time) {
                    $data['time'] = $time->format('H:i');
                }
                break;
            case 'ask_priority':
                if ($potentialPriority) {
                    $data['priority'] = $potentialPriority;
                }
                break;
        }

        // 3. Clear state if the user cancels
        if ($this->isCancel($msg)) {
            $this->memory->forget('context', 'creating_task_state');
            return $this->respond($this->t("Baik, pembuatan tugas dibatalkan.", "Alright, task creation cancelled."));
        }

        return $this->nextCreationStep($data);
    }

    private function nextCreationStep(array $data): array
    {
        // 1. Ask Title
        if (empty($data['judul'])) {
            if ($this->memory) {
                $this->memory->remember('context', 'creating_task_state', ['step' => 'ask_title', 'data' => $data], now()->addMinutes(10)->toDateTimeString());
            }
            return $this->respond($this->t("Tugasnya apa namanya, Tuan?", "What is the name of the task, Sir?"), null, null, [
                'session' => ['type' => 'create', 'label' => 'Membuat Tugas Baru', 'current_step' => 1, 'total_steps' => 5]
            ]);
        }

        // 2. Ask Description
        if (!isset($data['deskripsi'])) {
            if ($this->memory) {
                $this->memory->remember('context', 'creating_task_state', ['step' => 'ask_description', 'data' => $data], now()->addMinutes(10)->toDateTimeString());
            }
            return $this->respond(
                $this->t("Deskripsinya bagaimana? (Bisa dijawab 'tidak ada' atau 'skip')", "What is the description? (You can say 'none' or 'skip')"),
                null,
                ['skip', 'batal'],
                ['session' => ['type' => 'create', 'label' => 'Membuat Tugas Baru', 'current_step' => 2, 'total_steps' => 5]]
            );
        }

        // 3. Ask Date
        if (empty($data['date'])) {
            if ($this->memory) {
                $this->memory->remember('context', 'creating_task_state', ['step' => 'ask_date', 'data' => $data], now()->addMinutes(10)->toDateTimeString());
            }
            return $this->respond($this->t("Untuk tanggal berapa?", "For which date?"), null, null, [
                'session' => ['type' => 'create', 'label' => 'Membuat Tugas Baru', 'current_step' => 3, 'total_steps' => 5]
            ]);
        }

        // 4. Ask Time
        if (empty($data['time'])) {
            if ($this->memory) {
                $this->memory->remember('context', 'creating_task_state', ['step' => 'ask_time', 'data' => $data], now()->addMinutes(10)->toDateTimeString());
            }
            return $this->respond($this->t("Jam berapa?", "At what time?"), null, null, [
                'session' => ['type' => 'create', 'label' => 'Membuat Tugas Baru', 'current_step' => 4, 'total_steps' => 5]
            ]);
        }

        // 5. Ask Priority
        if (empty($data['priority'])) {
            if ($this->memory) {
                $this->memory->remember('context', 'creating_task_state', ['step' => 'ask_priority', 'data' => $data], now()->addMinutes(10)->toDateTimeString());
            }
            return $this->respond($this->t("Prioritasnya apa? (Tinggi / Sedang / Rendah)", "What is the priority? (High / Medium / Low)"), null, null, [
                'session' => ['type' => 'create', 'label' => 'Membuat Tugas Baru', 'current_step' => 5, 'total_steps' => 5]
            ]);
        }

        // 6. FINISH - Create Task
        if ($this->memory) {
            $this->memory->forget('context', 'creating_task_state');
        }


        $deadlineStr = $data['date'] . ' ' . ($data['time'] ?: '23:59') . ':00';
        $deadline = Carbon::parse($deadlineStr);

        $finalData = [
            'judul'     => mb_strtoupper(mb_substr($data['judul'], 0, 1)) . mb_substr($data['judul'], 1),
            'deskripsi' => $data['deskripsi'],
            'deadline'  => $deadline->format('Y-m-d H:i:s'),
            'priority'  => $data['priority'],
        ];

        // Save last discussed task for context (will be useful AFTER the creation action)
        // We can't save the ID yet because it's not created, but we can set a flag or just wait for the next interaction
        // Actually, for creation, it's better to let the user reference it in the next turn.
        
        return $this->respond(
            $this->t(
                "Baik {$this->userName}, tugas \"{$finalData['judul']}\" sudah dibuat untuk {$this->formatDate($deadline)} dengan prioritas {$finalData['priority']}.",
                "Alright {$this->userName}, task \"{$finalData['judul']}\" created for {$this->formatDate($deadline)} with {$finalData['priority']} priority."
            ),
            ['type' => 'create_task', 'data' => $finalData],
            ['focus_mode', 'daily_planner']
        );
    }

    private function isNegative(string $msg): bool
    {
        return preg_match('/\b(tidak|enggak|nggak|no|none|skip|lewat|lumpat|gak ada|kosong|tanpa)\b/i', $msg);
    }

    private function isCancel(string $msg): bool
    {
        return preg_match('/\b(batal|cancel|stop|berhenti|gah|ojo|keluar|exit)\b/i', $msg);
    }

    // ═══════════════════════════════════════════════════════════════
    // UPDATE OPERATIONS
    // ═══════════════════════════════════════════════════════════════

    private function parseUpdateDeadline(string $msg): ?array
    {
        $cleaned  = preg_replace('/\b(ubah|ganti|change|update|pindah|reschedule|set)\s*(deadline|tenggat|due|batas waktu)\s*(tugas|task|todo)?\s*/i', '', $msg);
        $cleaned  = preg_replace('/\b(ke|to|jadi|menjadi)\b/i', '|||', $cleaned);
        $parts    = explode('|||', $cleaned, 2);
        $taskQuery   = trim($parts[0] ?? '', ' ,:');
        $deadlineStr = trim($parts[1] ?? '', ' ,:');

        if (empty($taskQuery)) {
            $this->memory->remember('context', 'editing_task_state', ['step' => 'pick_task', 'data' => ['field' => 'deadline']], now()->addMinutes(10)->toDateTimeString());
            return $this->respond("Tugas mana yang mau diubah deadline-nya, {$this->userName}?", null, null, [
                'session' => ['type' => 'edit', 'label' => 'Mengubah Deadline', 'current_step' => 1, 'total_steps' => 3]
            ]);
        }

        $task = $this->findTask($taskQuery);
        if (!$task) return $this->respondTaskNotFound($taskQuery);

        if (empty($deadlineStr)) {
            $this->memory->remember('context', 'editing_task_state', ['step' => 'ask_value', 'data' => ['task_id' => $task->id, 'field' => 'deadline']], now()->addMinutes(10)->toDateTimeString());
            return $this->respond("Mau diubah ke kapan deadline tugas \"{$task->judul}\", {$this->userName}?", null, null, [
                'session' => ['type' => 'edit', 'label' => 'Mengubah Deadline', 'current_step' => 3, 'total_steps' => 3]
            ]);
        }

        $newDeadline = $this->parseDeadline($deadlineStr);
        if (!$newDeadline)
            return $this->respond("Format tanggal tidak dikenali. Coba: \"besok jam 3 sore\", \"hari senin\", \"20 maret\"");

        return $this->respond(
            "Baik {$this->userName}, deadline \"{$task->judul}\" diubah ke {$this->formatDate($newDeadline)}.",
            ['type' => 'update_task', 'data' => ['id' => $task->id, 'deadline' => $newDeadline->format('Y-m-d H:i:s')]]
        );
    }

    private function handleEditingSession(string $msg, string $original, array $state): array
    {
        // Clear conflicting wizard states to prevent stale state
        $this->memory->forget('context', 'creating_task_state');
        $this->memory->forget('context', 'deleting_task_state');
        $this->memory->forget('context', 'listing_task_state');

        $step = $state['step'] ?? 'pick_task';
        $data = $state['data'] ?? [];

        switch ($step) {
            case 'pick_task':
                $task = $this->findTask($msg);
                if (!$task) return $this->respondTaskNotFound($msg);
                
                $data['task_id'] = $task->id;
                $data['task_judul'] = $task->judul;
                $this->memory->remember('context', 'editing_task_state', ['step' => 'pick_field', 'data' => $data], now()->addMinutes(10)->toDateTimeString());
                
                return $this->respond($this->t(
                    "Mau ubah apa dari tugas \"{$task->judul}\"? (Judul / Tanggal / Prioritas)",
                    "What would you like to change for \"{$task->judul}\"? (Title / Date / Priority)"
                ), null, ['Batal'], [
                    'session' => ['type' => 'edit', 'label' => 'Mengubah Tugas', 'current_step' => 2, 'total_steps' => 3]
                ]);

            case 'pick_field':
                $field = $this->resolveEditField($msg);
                if (!$field) return $this->respond($this->t("Pilih salah satu Tuan: Judul, Tanggal, atau Prioritas.", "Please pick one: Title, Date, or Priority."), null, null, [
                    'session' => ['type' => 'edit', 'label' => 'Mengubah Tugas', 'current_step' => 2, 'total_steps' => 3]
                ]);
                
                $data['field'] = $field;
                $this->memory->remember('context', 'editing_task_state', ['step' => 'ask_value', 'data' => $data], now()->addMinutes(10)->toDateTimeString());
                
                $prompt = match($field) {
                    'judul'    => "Judul barunya apa, Tuan?",
                    'deadline' => "Mau diubah ke kapan?",
                    'priority' => "Prioritas barunya apa? (Tinggi / Sedang / Rendah)",
                };
                return $this->respond($this->t($prompt, $prompt), null, ['Batal'], [
                    'session' => ['type' => 'edit', 'label' => 'Mengubah Tugas', 'current_step' => 3, 'total_steps' => 3]
                ]);

            case 'ask_value':
                $this->memory->forget('context', 'editing_task_state');
                $field = $data['field'];
                $taskId = $data['task_id'];
                
                $updateData = ['id' => $taskId];
                $confirmMsg = "";

                if ($field === 'judul') {
                    $updateData['judul'] = mb_strtoupper(mb_substr($msg, 0, 1)) . mb_substr($msg, 1);
                    $confirmMsg = "Judul diubah menjadi \"{$updateData['judul']}\".";
                } elseif ($field === 'deadline') {
                    $date = $this->parseDeadline($msg);
                    if (!$date) {
                         // Stay in session if invalid
                         $this->memory->remember('context', 'editing_task_state', $state, now()->addMinutes(5)->toDateTimeString());
                         return $this->respond($this->t("Format tanggal tidak saya kenali. Coba lagi?", "I didn't recognize that date format. Try again?"), null, null, [
                             'session' => ['type' => 'edit', 'current_step' => 3, 'total_steps' => 3]
                         ]);
                    }
                    $updateData['deadline'] = $date->format('Y-m-d H:i:s');
                    $confirmMsg = "Deadline diubah ke " . $this->formatDate($date) . ".";
                } elseif ($field === 'priority') {
                    $prio = $this->parsePriorityValue($msg);
                    if (!$prio) {
                         $this->memory->remember('context', 'editing_task_state', $state, now()->addMinutes(5)->toDateTimeString());
                         return $this->respond($this->t("Prioritas tidak valid (Tinggi/Sedang/Rendah).", "Invalid priority (High/Medium/Low)."));
                    }
                    $updateData['priority'] = $prio;
                    $confirmMsg = "Prioritas diubah menjadi {$prio}.";
                }

                return $this->respond(
                    $this->t("Baik Tuan. {$confirmMsg}", "Alright Sir. {$confirmMsg}"),
                    ['type' => 'update_task', 'data' => $updateData]
                );
        }

        return $this->handleUnknown($msg);
    }

    private function handleDeletingSession(string $msg, string $original, array $state): array
    {
        // Clear conflicting wizard states to prevent stale state
        $this->memory->forget('context', 'creating_task_state');
        $this->memory->forget('context', 'editing_task_state');
        $this->memory->forget('context', 'listing_task_state');

        $step = $state['step'] ?? 'pick_task';
        $data = $state['data'] ?? [];

        if ($step === 'pick_task') {
            $task = $this->findTask($msg);
            if (!$task) return $this->respondTaskNotFound($msg);
            
            $this->memory->remember('context', 'deleting_task_state', ['step' => 'confirm', 'task_id' => $task->id, 'judul' => $task->judul], now()->addMinutes(5)->toDateTimeString());
            return $this->respond($this->t(
                "Apakah Tuan yakin ingin menghapus tugas \"{$task->judul}\"? (Ya / Tidak)",
                "Are you sure you want to delete \"{$task->judul}\"? (Yes / No)"
            ), null, ['Ya', 'Tidak'], [
                'session' => ['type' => 'delete', 'current_step' => 2, 'total_steps' => 2]
            ]);
        }

        if ($step === 'confirm') {
            $this->memory->forget('context', 'deleting_task_state');
            if ($this->matchesAny($msg, ['ya', 'yes', 'boleh', 'oke', 'lanjut', 'hajar'])) {
                return $this->respond(
                    $this->t("Tugas \"{$state['judul']}\" dipun hapus, Tuan.", "Task \"{$state['judul']}\" deleted, Sir."),
                    ['type' => 'delete_task', 'data' => ['id' => $state['task_id']]]
                );
            }
            return $this->respond($this->t("Baik, penghapusan dibatalkan.", "Alright, deletion cancelled."));
        }

        return $this->handleUnknown($msg);
    }

    private function resolveEditField(string $msg): ?string
    {
        if (preg_match('/\b(judul|nama|isi|text|title|name)\b/i', $msg)) return 'judul';
        if (preg_match('/\b(tanggal|waktu|deadline|jam|date|time|kapan)\b/i', $msg)) return 'deadline';
        if (preg_match('/\b(prioritas|penting|priority|urgen)\b/i', $msg)) return 'priority';
        return null;
    }

    private function parseUpdatePriority(string $msg): ?array
    {
        $cleaned     = preg_replace('/\b(ubah|ganti|change|update|set)\s*(prioritas|priority)\s*(tugas|task|todo)?\s*/i', '', $msg);
        $cleaned     = preg_replace('/\b(ke|to|jadi|menjadi)\b/i', '|||', $cleaned);
        $parts       = explode('|||', $cleaned, 2);
        $taskQuery   = trim($parts[0] ?? '', ' ,:');
        $priorityStr = trim($parts[1] ?? '', ' ,:');

        if (empty($taskQuery)) {
            $this->memory->remember('context', 'editing_task_state', ['step' => 'pick_task', 'data' => ['field' => 'priority']], now()->addMinutes(10)->toDateTimeString());
            return $this->respond("Tugas mana yang mau diubah prioritasnya, {$this->userName}?", null, null, [
                'session' => ['type' => 'edit', 'current_step' => 1, 'total_steps' => 3]
            ]);
        }

        $task = $this->findTask($taskQuery);
        if (!$task) return $this->respondTaskNotFound($taskQuery);

        $newPriority = $this->parsePriorityValue($priorityStr ?: $msg);
        if (!$newPriority) {
            $this->memory->remember('context', 'editing_task_state', ['step' => 'ask_value', 'data' => ['task_id' => $task->id, 'field' => 'priority']], now()->addMinutes(10)->toDateTimeString());
            return $this->respond("Prioritasnya apa? (high / medium / low)", null, null, [
                'session' => ['type' => 'edit', 'current_step' => 3, 'total_steps' => 3]
            ]);
        }

        $labels = ['high' => 'Tinggi', 'medium' => 'Sedang', 'low' => 'Rendah'];
        return $this->respond(
            "Baik {$this->userName}, prioritas \"{$task->judul}\" diubah ke {$labels[$newPriority]}.",
            ['type' => 'update_task', 'data' => ['id' => $task->id, 'priority' => $newPriority]]
        );
    }

    private function parseRename(string $msg): ?array
    {
        $cleaned = preg_replace('/\b(rename|ubah nama|ganti nama|ganti judul|ubah judul)\s*(tugas|task|todo)?\s*/i', '', $msg);
        $cleaned = preg_replace('/\b(jadi|menjadi|to|into)\b/i', '|||', $cleaned);
        $parts   = explode('|||', $cleaned, 2);
        $taskQuery = trim($parts[0] ?? '', ' ,:');
        $newName   = trim($parts[1] ?? '', ' ,:');

        if (empty($taskQuery)) {
            $this->memory->remember('context', 'editing_task_state', ['step' => 'pick_task', 'data' => ['field' => 'judul']], now()->addMinutes(10)->toDateTimeString());
            return $this->respond("Tugas mana yang mau di-rename, {$this->userName}?", null, null, [
                'session' => ['type' => 'edit', 'current_step' => 1, 'total_steps' => 3]
            ]);
        }

        $task = $this->findTask($taskQuery);
        if (!$task) return $this->respondTaskNotFound($taskQuery);

        if (empty($newName) || mb_strlen($newName) < 2) {
            $this->memory->remember('context', 'editing_task_state', ['step' => 'ask_value', 'data' => ['task_id' => $task->id, 'field' => 'judul']], now()->addMinutes(10)->toDateTimeString());
            return $this->respond("Nama barunya apa, {$this->userName}?", null, null, [
                'session' => ['type' => 'edit', 'current_step' => 3, 'total_steps' => 3]
            ]);
        }

        $newName = mb_strtoupper(mb_substr($newName, 0, 1)) . mb_substr($newName, 1);
        return $this->respond(
            "Baik {$this->userName}, \"{$task->judul}\" diubah menjadi \"{$newName}\".",
            ['type' => 'update_task', 'data' => ['id' => $task->id, 'judul' => $newName]]
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // TOGGLE / DELETE
    // ═══════════════════════════════════════════════════════════════

    private function parseToggle(string $msg, string $original): ?array
    {
        if (!$this->hasToggleKeyword($msg)) return null;

        $stripped = preg_replace('/\b(selesai|done|complete|tandai|mark|toggle|centang|checklist|ceklis|rampung|beres)\b\s*(kan|in)?\s*(tugas|task|todo)?\s*/i', '', $msg);
        $stripped = trim($stripped, ' ,:');

        if (empty($stripped))
            return $this->respond("Tugas mana yang mau ditandai selesai, {$this->userName}?");

        $match = $this->findTask($stripped);
        if (!$match) return $this->respondTaskNotFound($stripped);

        $status    = $match->is_completed ? 'belum selesai' : 'selesai';
        $remaining = $this->todos->where('is_completed', false)->count() - ($match->is_completed ? 0 : 1);
        $extra     = $remaining > 0 ? " Sisa {$remaining} tugas pending." : " Semua tugas sudah selesai! 🎉";

        return $this->respond(
            "Baik {$this->userName}, \"{$match->judul}\" ditandai {$status}.{$extra}",
            ['type' => 'toggle_task', 'data' => ['id' => $match->id]],
            $remaining === 0 ? ['streak', 'weekly_review'] : ['focus_mode']
        );
    }

    private function parseDelete(string $msg, string $original): ?array
    {
        if (!$this->hasDeleteKeyword($msg)) return null;

        $stripped = preg_replace('/\b(hapus|delete|remove|buang|hilangkan|mbusak)\b\s*(tugas|task|todo)?\s*/i', '', $msg);
        $stripped = trim($stripped, ' ,:');

        if (empty($stripped)) {
            $this->memory->remember('context', 'deleting_task_state', ['step' => 'pick_task'], now()->addMinutes(5)->toDateTimeString());
            return $this->respond("Tugas mana yang mau dihapus, {$this->userName}?", null, null, [
                'session' => ['type' => 'delete', 'current_step' => 1, 'total_steps' => 2]
            ]);
        }

        $match = $this->findTask($stripped);
        if (!$match) return $this->respondTaskNotFound($stripped);

        $this->memory->remember('context', 'deleting_task_state', ['step' => 'confirm', 'task_id' => $match->id, 'judul' => $match->judul], now()->addMinutes(5)->toDateTimeString());
        return $this->respond(
            $this->t(
                "Apakah Tuan yakin ingin menghapus tugas \"{$match->judul}\"? (Ya / Tidak)",
                "Are you sure you want to delete \"{$match->judul}\"? (Yes / No)"
            ),
            null,
            ['Ya', 'Tidak']
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // BULK OPERATIONS
    // ═══════════════════════════════════════════════════════════════

    private function parseBulkOperation(string $msg): ?array
    {
        if (preg_match('/\b(hapus|delete|remove)\b.*\b(semua|all|seluruh)\b.*\b(selesai|completed|done)\b/i', $msg)) {
            $completed = $this->todos->where('is_completed', true);
            if ($completed->isEmpty())
                return $this->respond("Tidak ada tugas selesai untuk dihapus, {$this->userName}.");
            return $this->respond(
                "Baik {$this->userName}, {$completed->count()} tugas selesai akan dihapus.",
                ['type' => 'bulk_delete', 'data' => ['ids' => $completed->pluck('id')->toArray(), 'filter' => 'completed']]
            );
        }

        if (preg_match('/\b(selesai|complete|done|tandai)\b.*\b(semua|all|seluruh)\b/i', $msg)
            || preg_match('/\b(semua|all)\b.*\b(selesai|complete|done)\b/i', $msg)) {
            $pending = $this->todos->where('is_completed', false);
            if ($pending->isEmpty())
                return $this->respond("Semua tugas sudah selesai, {$this->userName}!");
            return $this->respond(
                "Baik {$this->userName}, {$pending->count()} tugas akan ditandai selesai.",
                ['type' => 'bulk_toggle', 'data' => ['ids' => $pending->pluck('id')->toArray(), 'set_completed' => true]],
                ['streak', 'weekly_review']
            );
        }

        // "hapus tugas prioritas rendah"
        if (preg_match('/\b(hapus|delete)\b.*\b(low|rendah)\s*(priority|prioritas)?\b/i', $msg)) {
            $lowTasks = $this->todos->where('is_completed', false)->where('priority', 'low');
            if ($lowTasks->isEmpty())
                return $this->respond("Tidak ada tugas prioritas rendah, {$this->userName}.");
            return $this->respond(
                "Baik {$this->userName}, {$lowTasks->count()} tugas prioritas rendah akan dihapus.",
                ['type' => 'bulk_delete', 'data' => ['ids' => $lowTasks->pluck('id')->toArray(), 'filter' => 'low_priority']]
            );
        }

        if (preg_match('/\b(hapus|delete|remove)\b.*\b(semua|all|seluruh)\b/i', $msg)) {
            if ($this->todos->isEmpty())
                return $this->respond("Tidak ada tugas untuk dihapus, {$this->userName}.");
            return $this->respond(
                "Baik {$this->userName}, semua {$this->todos->count()} tugas akan dihapus.",
                ['type' => 'bulk_delete', 'data' => ['ids' => $this->todos->pluck('id')->toArray(), 'filter' => 'all']]
            );
        }

        return null;
    }

    // ═══════════════════════════════════════════════════════════════
    // REMINDER
    // ═══════════════════════════════════════════════════════════════

    private function parseReminder(string $msg, string $original): ?array
    {
        if (!$this->hasReminderKeyword($msg)) return null;

        $stripped = preg_replace('/\b(ingatkan|remind|reminder|alarm|pengingat|notifikasi|notification|pepeling|enget)\b\s*(saya|me|aku|ku)?\s*(untuk|to|tentang|about)?\s*/i', '', $msg);
        $stripped = trim($stripped, ' ,:');

        if (empty($stripped) || mb_strlen($stripped) < 2)
            return $this->respond("Ingatkan tentang apa, {$this->userName}? Dan kapan?");

        $deadline = $this->parseDeadline($stripped);
        if ($deadline) $stripped = $this->removeDeadlineText($stripped);
        $title = $this->cleanTitle($stripped);
        if (empty($title) || mb_strlen($title) < 2)
            return $this->respond("Ingatkan tentang apa, {$this->userName}?");

        $title    = mb_strtoupper(mb_substr($title, 0, 1)) . mb_substr($title, 1);
        if (!$deadline) $deadline = $this->now->copy()->addHour();

        $data = [
            'judul'    => "🔔 Reminder: {$title}",
            'deskripsi'=> "Pengingat dari Jarvis: {$title}",
            'deadline' => $deadline->format('Y-m-d H:i:s'),
            'priority' => 'high',
        ];

        return $this->respond(
            "Siap {$this->userName}, Anda akan diingatkan tentang \"{$title}\" pada {$this->formatDate($deadline)}.",
            ['type' => 'create_task', 'data' => $data]
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // TEMPLATE
    // ═══════════════════════════════════════════════════════════════

    private function parseTaskTemplate(string $msg, string $original): ?array
    {
        if (!preg_match('/\b(template|templat|preset|quick start|quick add)\b/i', $msg)) return null;

        $templates = [
            'meeting' => [
                ['judul' => 'Siapkan agenda meeting',       'priority' => 'high'],
                ['judul' => 'Kirim undangan meeting',       'priority' => 'high'],
                ['judul' => 'Buat slide presentasi',        'priority' => 'medium'],
                ['judul' => 'Tulis notulensi meeting',      'priority' => 'medium'],
            ],
            'project' => [
                ['judul' => 'Definisikan scope project',    'priority' => 'high'],
                ['judul' => 'Buat timeline project',        'priority' => 'high'],
                ['judul' => 'Assign peran tim',             'priority' => 'medium'],
                ['judul' => 'Setup repository',             'priority' => 'medium'],
                ['judul' => 'Jadwalkan kickoff meeting',    'priority' => 'low'],
            ],
            'study' => [
                ['judul' => 'Baca materi bab',              'priority' => 'high'],
                ['judul' => 'Buat catatan',                 'priority' => 'medium'],
                ['judul' => 'Kerjakan latihan soal',        'priority' => 'high'],
                ['judul' => 'Review flashcard',             'priority' => 'medium'],
                ['judul' => 'Kuis mandiri',                 'priority' => 'low'],
            ],
            'sprint' => [
                ['judul' => 'Sprint planning',              'priority' => 'high'],
                ['judul' => 'Daily standup',                'priority' => 'medium'],
                ['judul' => 'Code review',                  'priority' => 'high'],
                ['judul' => 'Sprint retrospective',         'priority' => 'medium'],
                ['judul' => 'Sprint demo',                  'priority' => 'medium'],
            ],
            'launch' => [
                ['judul' => 'Final QA testing',             'priority' => 'high'],
                ['judul' => 'Update dokumentasi',           'priority' => 'high'],
                ['judul' => 'Deploy ke production',         'priority' => 'high'],
                ['judul' => 'Monitor error logs',           'priority' => 'high'],
                ['judul' => 'Kirim announcement',           'priority' => 'medium'],
            ],
            'ta' => [ // Tugas Akhir
                ['judul' => 'Bab I - Pendahuluan',          'priority' => 'high'],
                ['judul' => 'Bab II - Tinjauan Pustaka',    'priority' => 'high'],
                ['judul' => 'Bab III - Metodologi',         'priority' => 'high'],
                ['judul' => 'Bab IV - Implementasi',        'priority' => 'high'],
                ['judul' => 'Bab V - Pengujian',            'priority' => 'high'],
                ['judul' => 'Bab VI - Penutup',             'priority' => 'medium'],
                ['judul' => 'Bimbingan dengan pembimbing',  'priority' => 'high'],
                ['judul' => 'Revisi & finalisasi',          'priority' => 'medium'],
            ],
        ];

        $selected = null;
        $templateName = '';
        foreach ($templates as $name => $tasks) {
            if (Str::contains($msg, $name)) {
                $selected     = $tasks;
                $templateName = $name;
                break;
            }
        }

        if (!$selected) {
            $names = implode(', ', array_keys($templates));
            return $this->respond($this->t(
                "📋 Template tersedia: **{$names}**\n\nContoh: \"template meeting\" atau \"template ta\" untuk tugas akhir.",
                "📋 Available templates: **{$names}**\n\nExample: \"template sprint\" to create sprint tasks."
            ));
        }

        $taskActions = array_map(fn($t) => ['type' => 'create_task', 'data' => $t], $selected);
        $count       = count($selected);

        return $this->respond(
            $this->t(
                "📋 Template \"{$templateName}\" diterapkan! {$count} tugas dibuat. Ada lagi?",
                "📋 Template \"{$templateName}\" applied! {$count} tasks created."
            ),
            ['type' => 'batch_create', 'data' => ['tasks' => $taskActions]],
            ['daily_planner', 'focus_mode']
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // RESCHEDULE OVERDUE
    // ═══════════════════════════════════════════════════════════════

    private function parseRescheduleOverdue(string $msg): ?array
    {
        if (!$this->hasRescheduleOverdueKeyword($msg)) return null;

        $overdue = $this->todos->where('is_completed', false)
            ->filter(fn($t) => $t->deadline && Carbon::parse($t->deadline)->isPast());

        if ($overdue->isEmpty()) {
            return $this->respond("Tidak ada tugas terlambat saat ini, {$this->userName}. Semua tugas tepat waktu! 🌟");
        }

        $targetDate = $this->parseDeadline($msg);
        if (!$targetDate) $targetDate = $this->now->copy()->addDay()->startOfDay()->setTime(23, 59);

        $titles    = $overdue->take(5)->pluck('judul')->map(fn($t) => "\"$t\"")->toArray();
        $titleList = implode(', ', $titles);
        $extra     = $overdue->count() > 5 ? ' dan ' . ($overdue->count() - 5) . ' lainnya' : '';

        return $this->respond(
            "Baik {$this->userName}, {$overdue->count()} tugas terlambat ({$titleList}{$extra}) dijadwalkan ulang ke {$this->formatDate($targetDate)}.",
            ['type' => 'reschedule_all_overdue', 'data' => ['target_date' => $targetDate->format('Y-m-d H:i:s')]]
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // FILTERED LIST / SEARCH / SCHEDULE / STATS
    // ═══════════════════════════════════════════════════════════════

    private function respondFilteredList(string $msg): array
    {
        $filtered = $this->todos;
        $label    = 'Semua tugas';

        if (preg_match('/\b(overdue|terlambat)\b/i', $msg)) {
            $filtered = $this->todos->where('is_completed', false)
                ->filter(fn($t) => $t->deadline && Carbon::parse($t->deadline)->isPast());
            $label = 'Tugas terlambat';
        } elseif (preg_match('/\b(selesai|completed|done|sudah)\b/i', $msg)) {
            $filtered = $this->todos->where('is_completed', true);
            $label = 'Tugas selesai';
        } elseif (preg_match('/\b(belum|pending|incomplete)\b/i', $msg)) {
            $filtered = $this->todos->where('is_completed', false);
            $label = 'Tugas pending';
        } elseif (preg_match('/\b(high|tinggi)\b/i', $msg)) {
            $filtered = $this->todos->where('is_completed', false)->where('priority', 'high');
            $label = 'Tugas prioritas tinggi';
        } elseif (preg_match('/\b(low|rendah)\b/i', $msg)) {
            $filtered = $this->todos->where('is_completed', false)->where('priority', 'low');
            $label = 'Tugas prioritas rendah';
        } elseif (preg_match('/\b(hari ini|today)\b/i', $msg)) {
            $filtered = $this->todos->where('is_completed', false)
                ->filter(fn($t) => $t->deadline && Carbon::parse($t->deadline)->isToday());
            $label = 'Tugas hari ini';
        } elseif (preg_match('/\b(besok|tomorrow)\b/i', $msg)) {
            $filtered = $this->todos->where('is_completed', false)
                ->filter(fn($t) => $t->deadline && Carbon::parse($t->deadline)->isTomorrow());
            $label = 'Tugas besok';
        } elseif (preg_match('/\b(minggu ini|this week)\b/i', $msg)) {
            $weekEnd  = $this->now->copy()->endOfWeek();
            $filtered = $this->todos->where('is_completed', false)
                ->filter(fn($t) => $t->deadline
                    && Carbon::parse($t->deadline)->lte($weekEnd)
                    && Carbon::parse($t->deadline)->gte($this->now->copy()->startOfDay()));
            $label = 'Tugas minggu ini';
        }

        if ($filtered->isEmpty())
            return $this->respond("Tidak ada {$label} saat ini, {$this->userName}.");

        $lines = [];
        foreach ($filtered->take(20) as $t) {
            $status  = $t->is_completed ? '✅' : '⬜';
            $p       = strtoupper($t->priority ?? 'medium');
            $dl      = $t->deadline ? Carbon::parse($t->deadline)->format('d M Y H:i') : 'Tanpa deadline';
            $overdue = (!$t->is_completed && $t->deadline && Carbon::parse($t->deadline)->isPast()) ? ' ⚠️' : '';
            $lines[] = "{$status} [{$p}] \"{$t->judul}\" — {$dl}{$overdue}";
        }

        $text = "{$label} ({$filtered->count()}):\n\n" . implode("\n", $lines);
        if ($filtered->count() > 20) $text .= "\n\n(Menampilkan 20 dari {$filtered->count()})";

        return $this->respond($text);
    }

    private function gpuSearch(string $query, array $tasks): array
    {
        if ($this->computeDevice !== 'gpu') return [];

        try {
            $taskStrings = array_map(fn($t) => $t['judul'] . ' ' . ($t['deskripsi'] ?? ''), $tasks);
            $response = Http::timeout(2)->post("{$this->gpuEndpoint}/semantic-search", [
                'query' => $query,
                'tasks' => $taskStrings,
                'threshold' => 0.45
            ]);

            if ($response->successful()) {
                return $response->json()['matches'] ?? [];
            }
        } catch (\Exception $e) {
            Log::warning("GPU Search failed: " . $e->getMessage());
        }

        return [];
    }

    private function respondSearch(string $msg): array
    {
        $searchTerm = preg_replace('/\b(cari|search|find|temukan|mana)\b\s*(tugas|task|todo)?\s*(tentang|about|mengenai|soal|dengan nama|yang)?\s*/i', '', $msg);
        $searchTerm = trim($searchTerm, ' ,:?');

        if (empty($searchTerm))
            return $this->respond($this->t("Mau cari tugas apa, {$this->userName}?", "What task are you looking for, {$this->userName}?"));

        $query = $searchTerm;
        
        // 1. Semantic GPU Search (v13.0)
        $gpuMatches = $this->gpuSearch($query, $this->todos->toArray());
        $gpuIds = collect($gpuMatches)->pluck('index')->toArray();

        // 2. Keyword-based Filter
        $results = $this->todos->filter(function ($t, $index) use ($query, $gpuIds) {
            if (in_array($index, $gpuIds)) return true;
            
            $title = $this->normalize($t->judul);
            $desc  = $this->normalize($t->deskripsi ?? '');
            $q     = $this->normalize($query);
            return Str::contains($title, $q) || Str::contains($desc, $q);
        });

        if ($results->isEmpty()) {
            return $this->respond($this->t(
                "Tidak ditemukan tugas dengan kata kunci \"{$searchTerm}\", {$this->userName}.",
                "No tasks found for \"{$searchTerm}\", {$this->userName}."
            ));
        }

        $lines = [];
        foreach ($results->take(10) as $index => $t) {
            $isSemantic = in_array($index, $gpuIds);
            $status  = $t->is_completed ? '✅' : '⬜';
            $p       = strtoupper($t->priority ?? 'medium');
            $tag     = $isSemantic ? ' [🧠 Semantic]' : '';
            $lines[] = "{$status} [{$p}]{$tag} \"{$t->judul}\"";
        }

        $header = $this->computeDevice === 'gpu' 
            ? "🚀 GPU Accelerated Search Results for \"{$searchTerm}\":"
            : "Hasil pencarian \"{$searchTerm}\":";

        return $this->respond(
            "$header\n\n" . implode("\n", $lines)
        );
    }

    private function respondSchedule(string $msg): array
    {
        $isTomorrow = (bool) preg_match('/\b(besok|tomorrow)\b/i', $msg);
        $targetDate = $isTomorrow ? $this->now->copy()->addDay()->startOfDay() : $this->now->copy()->startOfDay();
        $targetEnd  = $targetDate->copy()->endOfDay();
        $dayLabel   = $isTomorrow ? 'besok' : 'hari ini';

        $tasks   = $this->todos->where('is_completed', false)
            ->filter(fn($t) => $t->deadline && Carbon::parse($t->deadline)->gte($targetDate)
                && Carbon::parse($t->deadline)->lte($targetEnd))
            ->sortBy(fn($t) => Carbon::parse($t->deadline));
        $overdue = $this->todos->where('is_completed', false)
            ->filter(fn($t) => $t->deadline && Carbon::parse($t->deadline)->lt($targetDate));
        $noDl    = $this->todos->where('is_completed', false)->filter(fn($t) => !$t->deadline);

        $text  = "Jadwal {$dayLabel} ({$targetDate->format('l, d M Y')}), {$this->userName}:\n\n";

        if ($overdue->isNotEmpty()) {
            $text .= "⚠️ TERLAMBAT ({$overdue->count()}):\n";
            foreach ($overdue->take(5) as $t) {
                $p     = strtoupper($t->priority ?? 'medium');
                $text .= "  [{$p}] \"{$t->judul}\" — seharusnya " . Carbon::parse($t->deadline)->format('d M') . "\n";
            }
            $text .= "\n";
        }

        if ($tasks->isNotEmpty()) {
            $text .= "📋 TUGAS {$dayLabel} ({$tasks->count()}):\n";
            foreach ($tasks as $t) {
                $p     = strtoupper($t->priority ?? 'medium');
                $time  = Carbon::parse($t->deadline)->format('H:i');
                $text .= "  {$time} — [{$p}] \"{$t->judul}\"\n";
            }
        } else {
            $text .= "Tidak ada tugas terjadwal untuk {$dayLabel}.\n";
        }

        if ($noDl->isNotEmpty() && $noDl->count() <= 5) {
            $text .= "\n📌 TANPA DEADLINE ({$noDl->count()}):\n";
            foreach ($noDl->take(5) as $t) $text .= "  \"{$t->judul}\"\n";
        }

        $totalPending = $this->todos->where('is_completed', false)->count();
        $text .= "\nTotal pending: {$totalPending}";

        return $this->respond($text, null, ['daily_planner', 'deep_work', 'focus_mode']);
    }

    private function handleListingSession(string $msg, string $original, array $state): ?array
    {
        // "lagi" / "more" / "tampilkan lagi" → paginate
        if ($this->matchesAny($msg, ['lagi', 'more', 'tampilkan lagi', 'berikutnya', 'selanjutnya', 'next'])) {
            $offset = ($state['offset'] ?? 0) + 15;
            return $this->respondList($offset);
        }

        // If user wants to delete/edit from the listing context, hand off
        if ($this->hasDeleteKeyword($msg)) {
            $this->memory->forget('context', 'listing_task_state');
            return $this->parseDelete($msg, $original);
        }
        if (preg_match('/\b(ubah|ganti|edit|update|change)\b/i', $msg)) {
            $this->memory->forget('context', 'listing_task_state');
            return null; // let main dispatcher handle
        }
        if ($this->hasToggleKeyword($msg)) {
            $this->memory->forget('context', 'listing_task_state');
            return $this->parseToggle($msg, $original);
        }

        // Any other input → exit listing session naturally
        $this->memory->forget('context', 'listing_task_state');
        return null; // fall through to normal intent scoring
    }

    private function respondList(int $offset = 0): array
    {
        if ($this->todos->isEmpty()) {
            return $this->respond($this->t(
                "Anda tidak memiliki tugas saat ini, {$this->userName}. Mau buat tugas baru?",
                "No tasks yet, {$this->userName}. Shall I create one?"
            ));
        }

        $pending = $this->todos->where('is_completed', false)->sortBy(function ($t) {
            $pOrd = ['high' => 0, 'medium' => 1, 'low' => 2];
            return ($pOrd[$t->priority ?? 'medium'] ?? 1) . '-' . ($t->deadline ?? '9999');
        });

        $totalPending = $pending->count();
        $paginated    = $pending->slice($offset, 15);
        $completed    = $this->todos->where('is_completed', true);

        $lines = [];
        $idx   = $offset + 1;
        foreach ($paginated as $t) {
            $p       = strtoupper($t->priority ?? 'medium');
            $dl      = $t->deadline ? Carbon::parse($t->deadline)->format('d M Y') : $this->t('Tanpa deadline','No deadline');
            $overdue = ($t->deadline && Carbon::parse($t->deadline)->isPast()) ? ' ⚠️' : '';
            $lines[] = "{$idx}. ⬜ [{$p}] \"{$t->judul}\" — {$dl}{$overdue}";
            $idx++;
        }

        if ($completed->isNotEmpty() && $offset === 0) {
            $lines[] = '';
            foreach ($completed->take(5) as $t) $lines[] = "✅ \"{$t->judul}\"";
            if ($completed->count() > 5) {
                $rem = $completed->count() - 5;
                $lines[] = $this->t("...dan $rem tugas selesai lainnya",
                                     "...and $rem more completed");
            }
        }

        $header = $this->t("Tugas Anda, {$this->userName}:", "Your tasks, {$this->userName}:");
        $text   = "{$header}\n\n" . implode("\n", $lines);

        $footer = "\n\nPending: {$totalPending} | " . $this->t("Selesai","Done") . ": {$completed->count()}";
        if ($totalPending > $offset + 15) {
            $footer .= "\n" . $this->t("Ucapkan \"tampilkan lagi\" untuk melihat berikutnya.", "Say \"show more\" to see next.");
        }
        $text .= $footer;

        // Save listing session so user can say "lagi" / "more"
        if ($this->memory) {
            $this->memory->remember('context', 'listing_task_state', ['offset' => $offset], now()->addMinutes(10)->toDateTimeString());
        }

        $quickReplies = ['tampilkan lagi', 'focus_mode', 'keluar'];
        if ($totalPending <= $offset + 15) {
            $quickReplies = ['focus_mode', 'eisenhower', 'daily_planner'];
        }

        return $this->respond($text, null, $quickReplies);
    }

    private function respondStats(): array
    {
        $total        = $this->todos->count();
        $pending      = $this->todos->where('is_completed', false)->count();
        $completed    = $total - $pending;
        $high         = $this->todos->where('is_completed', false)->where('priority', 'high')->count();
        $medium       = $this->todos->where('is_completed', false)->where('priority', 'medium')->count();
        $low          = $this->todos->where('is_completed', false)->where('priority', 'low')->count();
        $overdue      = $this->todos->where('is_completed', false)
            ->filter(fn($t) => $t->deadline && Carbon::parse($t->deadline)->isPast())->count();
        $todayTasks   = $this->todos->where('is_completed', false)
            ->filter(fn($t) => $t->deadline && Carbon::parse($t->deadline)->isToday())->count();
        $tomorrowTasks= $this->todos->where('is_completed', false)
            ->filter(fn($t) => $t->deadline && Carbon::parse($t->deadline)->isTomorrow())->count();
        $completionRate = $total > 0 ? round(($completed / $total) * 100) : 0;

        if ($this->lang === 'en') {
            $text  = "📊 Task Report, {$this->userName}:\n\n";
            $text .= "Total: {$total} | Pending: {$pending} | Done: {$completed}\n";
            $text .= "Completion rate: {$completionRate}%\n\n";
            $text .= "Priority — High: {$high} | Medium: {$medium} | Low: {$low}\n";
            $text .= "Overdue: {$overdue} | Today: {$todayTasks} | Tomorrow: {$tomorrowTasks}\n\n";
            if ($overdue > 0)         $text .= "⚠️ {$overdue} overdue task(s). Complete or reschedule soon.";
            elseif ($completionRate >= 80) $text .= "🎉 Outstanding! {$completionRate}% completion rate.";
            elseif ($pending == 0)    $text .= "✅ All tasks completed. Well done!";
            elseif ($high > 3)        $text .= "📌 {$high} high-priority tasks. Focus on them one at a time.";
            else                       $text .= "Everything is on track. Anything else I can help with?";
        } else {
            $text  = "📊 Laporan Tugas, {$this->userName}:\n\n";
            $text .= "Total: {$total} | Pending: {$pending} | Selesai: {$completed}\n";
            $text .= "Completion rate: {$completionRate}%\n\n";
            $text .= "Prioritas — Tinggi: {$high} | Sedang: {$medium} | Rendah: {$low}\n";
            $text .= "Terlambat: {$overdue} | Hari ini: {$todayTasks} | Besok: {$tomorrowTasks}\n\n";
            if ($overdue > 0)         $text .= "⚠️ Ada {$overdue} tugas terlambat. Segera selesaikan atau ubah deadline-nya.";
            elseif ($completionRate >= 80) $text .= "🎉 Luar biasa! Tingkat penyelesaian {$completionRate}%. Terus semangat!";
            elseif ($pending == 0)    $text .= "✅ Semua tugas sudah selesai. Kerja bagus, {$this->userName}!";
            elseif ($high > 3)        $text .= "📌 Anda punya {$high} tugas prioritas tinggi. Fokus satu per satu.";
            else                       $text .= "Semua berjalan lancar. Ada yang perlu dibantu?";
        }

        return $this->respond($text, null, ['eisenhower', 'weekly_review', 'productivity']);
    }

    private function respondProductivityInsight(): array
    {
        $total          = $this->todos->count();
        $completed      = $this->todos->where('is_completed', true)->count();
        $pending        = $total - $completed;
        $rate           = $total > 0 ? round(($completed / $total) * 100) : 0;
        $overdue        = $this->todos->where('is_completed', false)
            ->filter(fn($t) => $t->deadline && Carbon::parse($t->deadline)->isPast())->count();
        $highCompleted  = $this->todos->where('is_completed', true)->where('priority', 'high')->count();
        $highTotal      = $this->todos->where('priority', 'high')->count();
        $highRate       = $highTotal > 0 ? round(($highCompleted / $highTotal) * 100) : 0;
        $todayCompleted = $this->todos->where('is_completed', true)
            ->filter(fn($t) => $t->updated_at && Carbon::parse($t->updated_at)->isToday())->count();

        $text  = "📊 " . $this->t("Laporan Produktivitas","Productivity Report") . ", {$this->userName}:\n\n";
        $text .= "✅ Completion Rate: {$rate}% ({$completed}/{$total})\n";
        $text .= "🔴 " . $this->t("Prioritas Tinggi","High Priority") . ": {$highRate}% ({$highCompleted}/{$highTotal})\n";
        $text .= "⚠️ " . $this->t("Terlambat","Overdue") . ": {$overdue}\n";
        $text .= "📅 " . $this->t("Selesai hari ini","Done today") . ": {$todayCompleted}\n\n";

        if ($rate >= 80)       $text .= "🌟 " . $this->t("Luar biasa! Tingkat penyelesaian sangat tinggi!","Outstanding! Very high completion rate!");
        elseif ($rate >= 50)   $text .= "💪 " . $this->t("Progres bagus! Fokus tugas prioritas tinggi.","Good progress! Focus on high-priority tasks.");
        elseif ($pending > 0)  $text .= "💡 " . $this->t("Mulai dari tugas terkecil atau paling mendesak.","Start with the smallest or most urgent task.");
        else                    $text .= "🎉 " . $this->t("Tidak ada tugas pending. Mau buat rencana baru?","No pending tasks. Want to set a new goal?");

        return $this->respond($text, null, ['weekly_review', 'streak', 'eisenhower']);
    }

    // ═══════════════════════════════════════════════════════════════
    // FUZZY TASK FINDER with Levenshtein + word overlap
    // ═══════════════════════════════════════════════════════════════

    private function findTask(string $query): ?Todo
    {
        if ($this->todos->isEmpty()) return null;

        // Smart Context Check: Ordinal/Pronoun references
        if (preg_match('/\b(itu|tadi|terakhir|last one|that one|the task)\b/i', $query)) {
            $lastId = $this->memory->recall('context', 'last_discussed_task')['id'] ?? null;
            if ($lastId) {
                $task = $this->todos->firstWhere('id', $lastId);
                if ($task) return $task;
            }
        }

        $query      = $this->normalize($query);
        $bestMatch  = null;
        $bestScore  = 0;

        foreach ($this->todos as $todo) {
            $title = $this->normalize($todo->judul);

            if ($title === $query) return $todo; // exact match

            // Contains check
            if (Str::contains($title, $query) || Str::contains($query, $title)) {
                $score = similar_text($title, $query);
                if ($score > $bestScore) { $bestScore = $score; $bestMatch = $todo; }
                continue;
            }

            // Word overlap scoring
            $qWords    = array_filter(explode(' ', $query));
            $tWords    = array_filter(explode(' ', $title));
            $overlap   = count(array_intersect($qWords, $tWords));
            if ($overlap > 0) {
                $oScore = ($overlap / max(count($qWords), 1)) * 100;
                if ($oScore > $bestScore) { $bestScore = $oScore; $bestMatch = $todo; }
                continue;
            }

            // Levenshtein distance (typo tolerance)
            $lev = levenshtein($title, $query);
            if ($lev <= self::TYPO_TOLERANCE) {
                $levScore = 100 - ($lev * 10);
                if ($levScore > $bestScore) { $bestScore = $levScore; $bestMatch = $todo; }
                continue;
            }

            // Fuzzy similarity
            similar_text($title, $query, $percent);
            if ($percent > 45 && $percent > $bestScore) {
                $bestScore = $percent;
                $bestMatch = $todo;
            }
        }

        if ($bestMatch) {
            $this->memory->remember('context', 'last_discussed_task', ['id' => $bestMatch->id], now()->addMinutes(30)->toDateTimeString());
        }

        return $bestMatch;
    }

    private function findTaskByName(string $name): ?Todo
    {
        $name = mb_strtolower(trim($name));
        if (empty($name)) return null;
        foreach ($this->todos as $t) {
            if (mb_strtolower($t->judul) === $name) return $t;
        }
        foreach ($this->todos as $t) {
            if (Str::contains(mb_strtolower($t->judul), $name)) return $t;
        }
        $bestMatch = null;
        $bestScore = 0;
        foreach ($this->todos as $t) {
            $sim = 0;
            similar_text($name, mb_strtolower($t->judul), $sim);
            if ($sim > $bestScore && $sim >= 60) { $bestScore = $sim; $bestMatch = $t; }
        }
        return $bestMatch;
    }

    // ═══════════════════════════════════════════════════════════════
    // ADVANCED DATE/TIME PARSER
    // ═══════════════════════════════════════════════════════════════

    private function parseDeadline(string $text): ?Carbon
    {
        $today = $this->now->copy()->startOfDay();

        // ── Relative keywords ──────────────────────────────────────
        if (preg_match('/\b(besok|tomorrow)\b/i', $text))
            return $this->applyTime(\Illuminate\Support\Carbon::parse($today->copy()->addDay()), $text);
        if (preg_match('/\b(lusa|day after tomorrow)\b/i', $text))
            return $this->applyTime(\Illuminate\Support\Carbon::parse($today->copy()->addDays(2)), $text);
        if (preg_match('/\b(minggu depan|next week)\b/i', $text))
            return $this->applyTime(\Illuminate\Support\Carbon::parse($today->copy()->addWeek()), $text);
        if (preg_match('/\b(bulan depan|next month)\b/i', $text))
            return $this->applyTime(\Illuminate\Support\Carbon::parse($today->copy()->addMonth()), $text);

        // ── End of period ─────────────────────────────────────────
        if (preg_match('/\b(akhir minggu|end of week|weekend)\b/i', $text))
            return $this->now->copy()->endOfWeek()->setTime(23, 59);
        if (preg_match('/\b(akhir bulan|end of month)\b/i', $text))
            return $this->now->copy()->endOfMonth()->setTime(23, 59);
        if (preg_match('/\b(akhir tahun|end of year)\b/i', $text))
            return $this->now->copy()->endOfYear()->setTime(23, 59);

        // ── Quarter references ─────────────────────────────────────
        if (preg_match('/\b(akhir|end of)?\s*Q([1-4])\b/i', $text, $m)) {
            $q         = (int) $m[2];
            $endMonths = [3, 6, 9, 12];
            $endMonth  = $endMonths[$q - 1];
            $year      = $this->now->year;
            if ($this->now->month > $endMonth) $year++;
            return Carbon::createFromDate($year, $endMonth, 1)->endOfMonth()->setTime(23, 59);
        }

        // ── "N hari/minggu/bulan lagi" ─────────────────────────────
        if (preg_match('/(\d+)\s*(hari|day|days)\s*(lagi|from now|later)?/i', $text, $m))
            return $this->applyTime(\Illuminate\Support\Carbon::parse($today->copy()->addDays((int) $m[1])), $text);
        if (preg_match('/(\d+)\s*(minggu|week|weeks)\s*(lagi|from now|later)?/i', $text, $m))
            return $this->applyTime(\Illuminate\Support\Carbon::parse($today->copy()->addWeeks((int) $m[1])), $text);
        if (preg_match('/(\d+)\s*(bulan|month|months)\s*(lagi|from now|later)?/i', $text, $m))
            return $this->applyTime(\Illuminate\Support\Carbon::parse($today->copy()->addMonths((int) $m[1])), $text);
        if (preg_match('/(\d+)\s*(jam|hour|hours)\s*(lagi|from now|later)?/i', $text, $m))
            return \Illuminate\Support\Carbon::parse($this->now->copy()->addHours((int) $m[1]));
        if (preg_match('/(\d+)\s*(menit|minute|minutes|min)\s*(lagi|from now|later)?/i', $text, $m))
            return \Illuminate\Support\Carbon::parse($this->now->copy()->addMinutes((int) $m[1]));

        // ── Day names ─────────────────────────────────────────────
        $days = [
            'senin'=>'Monday','selasa'=>'Tuesday','rabu'=>'Wednesday',
            'kamis'=>'Thursday','jumat'=>'Friday','sabtu'=>'Saturday',
            'minggu'=>'Sunday',
            'monday'=>'Monday','tuesday'=>'Tuesday','wednesday'=>'Wednesday',
            'thursday'=>'Thursday','friday'=>'Friday','saturday'=>'Saturday',
            'sunday'=>'Sunday',
        ];
        foreach ($days as $key => $day) {
            if (preg_match('/\b(hari\s+)?' . $key . '\b/i', $text)) {
                $next = $this->now->copy()->next($day)->startOfDay();
                // "minggu depan senin" → add another week
                if (preg_match('/\b(depan|next)\b.*\b' . $key . '\b|\b' . $key . '\b.*\b(depan|next)\b/i', $text))
                    $next->addWeek();
                return $this->applyTime($next, $text);
            }
        }

        // ── Indonesian month names ─────────────────────────────────
        $months = [
            'januari'=>1,'februari'=>2,'maret'=>3,'april'=>4,'mei'=>5,'juni'=>6,
            'juli'=>7,'agustus'=>8,'september'=>9,'oktober'=>10,'november'=>11,'desember'=>12,
            'january'=>1,'february'=>2,'march'=>3,'may'=>5,'june'=>6,'july'=>7,
            'august'=>8,'october'=>10,'december'=>12,
        ];
        foreach ($months as $monthName => $monthNum) {
            if (preg_match('/(\d{1,2})\s+' . $monthName . '(?:\s+(\d{4}))?/i', $text, $m)) {
                $year = isset($m[2]) ? (int)$m[2] : $this->now->year;
                $date = Carbon::createFromDate($year, $monthNum, (int) $m[1]);
                if ($date->isPast() && !isset($m[2])) $date->addYear();
                return $this->applyTime($date, $text);
            }
        }

        // ── ISO date: 2026-04-01 ──────────────────────────────────
        if (preg_match('/(\d{4})-(\d{1,2})-(\d{1,2})/', $text, $m)) {
            try { return $this->applyTime(Carbon::createFromDate($m[1], $m[2], $m[3]), $text); } catch (\Exception $e) {}
        }

        // ── DD/MM/YYYY or DD-MM-YYYY ─────────────────────────────
        if (preg_match('/(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})/', $text, $m)) {
            try { return $this->applyTime(Carbon::createFromDate($m[3], $m[2], $m[1]), $text); } catch (\Exception $e) {}
        }

        // ── Today keyword ─────────────────────────────────────────
        if (preg_match('/\b(hari ini|today)\b/i', $text))
            return $this->applyTime($today->copy(), $text);

        // ── Standalone time → today ───────────────────────────────
        if (preg_match('/\b(?:jam\s+)(\d{1,2})(?::(\d{2}))?\s*(pagi|siang|sore|malam|am|pm)\b/i', $text))
            return $this->applyTime($today->copy(), $text);

        return null;
    }

    private function applyTime(Carbon $date, string $text): Carbon
    {
        if (preg_match('/(?:jam\s+)?(\d{1,2})(?::(\d{2}))?\s*(pagi|siang|sore|malam|am|pm)?/i', $text, $m)) {
            $hour   = (int) $m[1];
            $minute = isset($m[2]) ? (int) $m[2] : 0;
            $period = mb_strtolower($m[3] ?? '');
            if ($hour <= 12) {
                if (in_array($period, ['sore','malam','pm']) && $hour < 12) $hour += 12;
                elseif (in_array($period, ['pagi','am']) && $hour == 12) $hour = 0;
            }
            if ($hour >= 0 && $hour <= 23) return $date->setTime($hour, $minute);
        }
        if (preg_match('/\b(pagi|morning)\b/i', $text))    return $date->setTime(8, 0);
        if (preg_match('/\b(siang|afternoon)\b/i', $text)) return $date->setTime(13, 0);
        if (preg_match('/\b(sore|evening)\b/i', $text))    return $date->setTime(16, 0);
        if (preg_match('/\b(malam|night)\b/i', $text))     return $date->setTime(20, 0);
        return $date->setTime(23, 59, 0);
    }

    private function removeDeadlineText(string $text): string
    {
        $patterns = [
            '/\b(besok|tomorrow|lusa|day after tomorrow|hari ini|today)\b/i',
            '/\b(minggu depan|next week|bulan depan|next month|akhir minggu|end of week|akhir bulan|end of month)\b/i',
            '/\b(akhir tahun|end of year)\b/i',
            '/\b(akhir|end of)?\s*Q[1-4]\b/i',
            '/\d+\s*(hari|day|days|jam|hour|hours|menit|minute|minutes|min|minggu|week|weeks|bulan|month|months)\s*(lagi|from now|later)?/i',
            '/\b(hari\s+)?(senin|selasa|rabu|kamis|jumat|sabtu|minggu|monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b/i',
            '/\b(?:jam\s+)?\d{1,2}(?::\d{2})?\s*(?:pagi|siang|sore|malam|am|pm)?\b/i',
            '/\b(pagi|siang|sore|malam|morning|afternoon|evening|night)\b/i',
            '/\b\d{4}-\d{1,2}-\d{1,2}\b/',
            '/\d{1,2}[\/\-]\d{1,2}[\/\-]\d{4}/',
            '/\d{1,2}\s*(januari|februari|maret|april|mei|juni|juli|agustus|september|oktober|november|desember|january|february|march|may|june|july|august|october|december)(\s+\d{4})?/i',
            '/\b(due|deadline|pada|at|on|nanti|depan|next)\b/i',
        ];
        foreach ($patterns as $p) $text = preg_replace($p, '', $text);
        return $text;
    }

    // ═══════════════════════════════════════════════════════════════
    // UTILITY
    // ═══════════════════════════════════════════════════════════════

    private function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = preg_replace('/[^\w\s\d.,!?\'\-\/]/u', '', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        // Apply synonym expansion (regional slang → canonical)
        return $this->expandSynonyms($text);
    }

    /**
     * Expand regional slang/abbreviations to canonical Indonesian.
     * This ensures all scoring logic works regardless of input dialect.
     */
    private function expandSynonyms(string $text): string
    {
        $map = [
            // Betawi / Jakarta slang
            'gue'     => 'saya',
            'gw'      => 'saya',
            'aye'     => 'saya',
            'elo'     => 'kamu',
            'lo'      => 'kamu',
            'lu'      => 'kamu',
            'kagak'   => 'tidak',
            'engga'   => 'tidak',
            'enggak'  => 'tidak',
            'nggak'   => 'tidak',
            'gak'     => 'tidak',
            'ga'      => 'tidak',
            'udah'    => 'sudah',
            'udh'     => 'sudah',
            'dah'     => 'sudah',
            'belom'   => 'belum',
            'blm'     => 'belum',
            'nyari'   => 'cari',
            'mau'     => 'ingin',
            'pengen'  => 'ingin',
            'pgn'     => 'ingin',
            'dong'    => '',
            'donk'    => '',
            'sih'     => '',
            'deh'     => '',
            'nih'     => 'ini',
            'tuh'     => 'itu',
            'emang'   => 'memang',
            'gimana'  => 'bagaimana',
            'gmn'     => 'bagaimana',
            'apaan'   => 'apa',
            'apain'   => 'apa',
            'yg'      => 'yang',
            'dgn'     => 'dengan',
            'utk'     => 'untuk',
            'dr'      => 'dari',
            'kerjain' => 'kerjakan',
            'lakuin'  => 'lakukan',
            'tambahin'=> 'tambahkan',
            'hapusin' => 'hapuskan',
            'bikin'   => 'buat',
            // Javanese
            'gawean'  => 'tugas',
            'gawe'    => 'buat',
            'nggawe'  => 'buat',
            'mbusak'  => 'hapus',
            'delok'   => 'lihat',
            'saiki'   => 'sekarang',
            'besuk'   => 'besok',
            'iki'     => 'ini',
            'iku'     => 'itu',
            'opo'     => 'apa',
            'sopo'    => 'siapa',
            'piye'    => 'bagaimana',
            'rampung' => 'selesai',
            'durung'  => 'belum',
            'wis'     => 'sudah',
            'awakmu'  => 'kamu',
            'kowe'    => 'kamu',
            // Sundanese
            'bikeun'  => 'buat',
            'naon'    => 'apa',
            'iraha'   => 'kapan',
            'mana'    => 'mana',
            'abdi'    => 'saya',
            'anjeun'  => 'kamu',
            'mangga'  => 'silakan',
            'hapunten'=> 'maaf',
            // Common abbreviations
            'tgs'     => 'tugas',
            'krj'     => 'kerjakan',
            'jgn'     => 'jangan',
            'hrs'     => 'harus',
            'bsk'     => 'besok',
            'hr'      => 'hari',
            'mgg'     => 'minggu',
            'bln'     => 'bulan',
            'thn'     => 'tahun',
        ];

        $words = explode(' ', $text);
        $result = [];
        foreach ($words as $word) {
            $replacement = $map[$word] ?? null;
            if ($replacement === '') continue; // skip filler words
            $result[] = $replacement ?? $word;
        }
        return implode(' ', $result);
    }

    /**
     * Multi-language translation helper.
     * Returns the best match for the current language.
     * JV/SU/BT will use their own string if provided, otherwise fall back to Indonesian.
     */
    private function t(string $id, string $en, ?string $jv = null, ?string $su = null, ?string $bt = null): string
    {
        return match ($this->lang) {
            'en' => $en,
            'jv' => $jv ?? $id,
            'su' => $su ?? $id,
            'bt' => $bt ?? $id,
            default => $id,
        };
    }

    private function cleanTitle(string $text): string
    {
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text, ' ,.:;-');
        
        // Comprehensive Indonesian and English conversational filler removal
        $fillers = [
            'judulnya itu', 'judulnya', 'namanya', 'berjudul', 'bernama', 'tentang',
            'mengenai', 'buat tugas', 'tambah tugas', 'bikin tugas', 'tugasnya',
            'tugas', 'task', 'todo', 'titled', 'called', 'named', 'about', 'for',
            'dengan judul', 'yang berjudul', 'kalo judulnya', 'if the title is',
            'gawean', 'gaweane', 'iki', 'niku'
        ];
        
        foreach ($fillers as $filler) {
            $text = preg_replace('/^' . preg_quote($filler, '/') . '\s+/i', '', $text);
        }

        return trim($text);
    }

    private function parsePriorityValue(string $text): ?string
    {
        if (preg_match('/\b(high|tinggi|penting|urgent|banget)\b/i', $text)) return 'high';
        if (preg_match('/\b(medium|sedang|menengah|biasa|normal)\b/i', $text)) return 'medium';
        if (preg_match('/\b(low|rendah|santai|tidak penting)\b/i', $text)) return 'low';
        return null;
    }

    private function formatDate(Carbon $date): string
    {
        if ($date->isToday())    return 'hari ini jam ' . $date->format('H:i');
        if ($date->isTomorrow()) return 'besok jam ' . $date->format('H:i');
        return $date->format('l, d M Y H:i');
    }

    private function getTimeGreeting(): string
    {
        $hour = $this->now->hour;
        if ($this->lang === 'en') {
            if ($hour < 12) return 'Good morning';
            if ($hour < 17) return 'Good afternoon';
            return 'Good evening';
        }
        if ($this->lang === 'jv') {
            if ($hour < 11)  return 'Sugeng enjing, Tuan';
            if ($hour < 15)  return 'Sugeng siang, Tuan';
            if ($hour < 18)  return 'Sugeng sonten, Tuan';
            return 'Sugeng dalu, Tuan';
        }
        if ($this->lang === 'su') {
            if ($hour < 11)  return 'Wilujeng enjing, Tuan';
            if ($hour < 15)  return 'Wilujeng siang, Tuan';
            if ($hour < 18)  return 'Wilujeng sonten, Tuan';
            return 'Wilujeng wengi, Tuan';
        }
        if ($this->lang === 'bt') {
            if ($hour < 11) return 'Selamet pagi, Tuan';
            if ($hour < 15) return 'Selamet siang, Tuan';
            if ($hour < 18) return 'Selamet sore, Tuan';
            return 'Selamet malem, Tuan';
        }
        // Indonesian default
        if ($hour < 11) return 'Selamat pagi Tuan';
        if ($hour < 15) return 'Selamat siang Tuan';
        if ($hour < 18) return 'Selamat sore Tuan';
        return 'Selamat malam Tuan';
    }

    // ═══════════════════════════════════════════════════════════════
    // GREETING & IDENTITY
    // ═══════════════════════════════════════════════════════════════

    private function respondGreeting(string $msg): array
    {
        $overdueTasks = $this->todos->where('is_completed', false)
            ->filter(fn($t) => $t->deadline && Carbon::parse($t->deadline)->isPast());
        $overdueCount = $overdueTasks->count();
        $pending      = $this->todos->where('is_completed', false)->count();
        $todayTasks   = $this->todos->where('is_completed', false)
            ->filter(fn($t) => $t->deadline && Carbon::parse($t->deadline)->isToday())->count();
        $completed    = $this->todos->where('is_completed', true)->count();

        $isGoodMorning = preg_match('/\b(selamat pagi|good morning|pagi|wilujeng enjing|sugeng enjing)\b/i', $msg);
        $action = null;
        $greetingPrefix = $this->getTimeGreeting();

        // Contextual personality: check last conversation and sentiment
        $sentiment = $this->analyzeSentiment($msg);
        $lastConvo = $this->memory ? $this->memory->getLastConversation() : null;

        // Build personalized opening
        $parts = ["{$greetingPrefix}, {$this->userName}."];

        // Returning user recognition
        if ($lastConvo && !empty($lastConvo['summary'])) {
            $parts[] = $this->t(
                "Senang bertemu lagi! Terakhir kali kita membahas tentang tugas-tugas Anda.",
                "Nice to see you again! Last time we discussed your tasks."
            );
        }

        // Morning auto-reschedule overdue
        if ($isGoodMorning && $overdueCount > 0) {
            $action = ['type' => 'reschedule_overdue', 'data' => []];
            $parts[] = $this->t(
                "Saya perhatikan ada {$overdueCount} tugas yang sudah lewat batas waktu. Saya telah menjadwalkan ulang semuanya ke hari ini.",
                "I noticed {$overdueCount} overdue tasks. I have rescheduled them to today for you."
            );
        } elseif ($overdueCount > 0) {
            $parts[] = $this->t(
                "Ada {$overdueCount} tugas terlambat yang perlu perhatian segera.",
                "You have {$overdueCount} overdue task(s) requiring immediate attention."
            );
        }

        if ($todayTasks > 0) {
            $parts[] = $this->t(
                "Anda punya {$todayTasks} tugas untuk hari ini.",
                "You have {$todayTasks} task(s) due today."
            );
        }

        if ($pending > 0 && $overdueCount == 0 && $todayTasks == 0) {
            $parts[] = $this->t("Anda memiliki {$pending} tugas pending.", "You have {$pending} pending task(s).");
        }

        if ($pending == 0 && $completed > 0) {
            // Celebration! All tasks done
            $celebrationId = [
                "Semua tugas selesai! Luar biasa, {$this->userName}! Mau buat tantangan baru?",
                "Tugas bersih total! Anda memang produktif, {$this->userName}. Siap untuk goal baru?",
                "Zero pending! Pencapaian yang mengagumkan. Ada rencana baru?",
            ];
            $celebrationEn = [
                "All tasks done! Amazing, {$this->userName}! Ready for a new challenge?",
                "Clean slate! You're on fire, {$this->userName}. Ready for a new goal?",
                "Zero pending! That's an impressive achievement. Any new plans?",
            ];
            $idx = abs(crc32($msg)) % count($celebrationId);
            $parts[] = $this->t($celebrationId[$idx], $celebrationEn[$idx]);
        } elseif ($pending == 0) {
            $parts[] = $this->t("Tidak ada tugas pending. Mau buat tugas baru?", "No pending tasks. Shall I create one?");
        } else {
            // Time-aware personality
            $hour = $this->now->hour;
            if ($hour >= 6 && $hour < 12) {
                $parts[] = $this->t(
                    "Pagi yang produktif menanti! Ada yang bisa saya bantu?",
                    "A productive morning awaits! How may I assist you?"
                );
            } elseif ($hour >= 12 && $hour < 17) {
                $parts[] = $this->t(
                    "Semangat siang! Ada yang bisa saya bantu?",
                    "Keep the afternoon momentum! How can I help?"
                );
            } elseif ($hour >= 17 && $hour < 21) {
                $parts[] = $this->t(
                    "Sore yang tenang. Mau review tugas hari ini atau rencanakan besok?",
                    "Quiet evening. Want to review today's tasks or plan tomorrow?"
                );
            } else {
                $parts[] = $this->t(
                    "Masih terjaga? Santai saja, besok bisa dilanjutkan. Ada yang urgent?",
                    "Still up? Take it easy, tomorrow is another day. Anything urgent?"
                );
            }
        }

        return $this->respond(implode(' ', $parts), $action, ['stats', 'focus_mode', 'daily_planner']);
    }

    private function respondIdentity(): array
    {
        $features = $this->t(
            "CRUD tugas (buat/edit/hapus/cari/selesaikan), Eisenhower Matrix, jadwal Deep Work, timer Pomodoro, Mode Fokus, Rencana Harian, Pelacak Kebiasaan, Streak & Pencapaian, Goal Tracking, Saran Cerdas, Analisis Beban Kerja, Review Mingguan, Prioritisasi Cerdas, operasi massal, Template Tugas, Pengingat, Ganti Bahasa, Memory & Training — semuanya offline.",
            "Task CRUD (create/edit/delete/search/complete), Eisenhower Matrix, Deep Work scheduling, Pomodoro timer, Focus Mode, Daily Planner, Habit Tracker, Streak & Achievements, Goal Tracking, Smart Suggestions, Workload Analysis, Weekly Review, Smart Prioritization, Bulk/Batch operations, Task Templates, Reminders, Language Switching, Memory & Training — all offline.",
            "Nggawe, mbusak, rampungake tugas, Eisenhower Matrix, Pomodoro, Focus Mode, lan sapanunggalane — kabeh offline.",
            "Nyieun, ngahapus, ngarengsekeun tugas, Eisenhower Matrix, Pomodoro, Focus Mode, sareng sajabina — sadaya offline."
        );

        return $this->respond(
            $this->t(
                "Saya Jarvis v5, asisten AI canggih Anda. Saya bisa: {$features}",
                "I'm Jarvis v5, your advanced AI assistant. I can: {$features}",
                "Kula Jarvis v5, asisten AI panjenengan. Kula saged: {$features}",
                "Abdi Jarvis v5, asisten AI anjeun. Abdi tiasa: {$features}"
            ),
            null,
            ['help', 'stats']
        );
    }

    private function respondHelp(): array
    {
        if ($this->lang === 'en') {
            return $this->respond(
                "📖 Jarvis v3 Commands:\n\n" .
                "📝 CREATE: \"Create task X tomorrow at 3pm [high]\"\n" .
                "✅ COMPLETE: \"Mark task X as done\"\n" .
                "🗑️ DELETE: \"Delete task X\" / \"Delete all completed\"\n" .
                "✏️ EDIT: \"Change deadline/priority/name of X\"\n" .
                "📋 VIEW: \"Show tasks\" / \"Overdue tasks\" / \"Today\"\n" .
                "🔍 SEARCH: \"Find tasks about meeting\"\n" .
                "📊 ANALYTICS: \"Stats\" / \"Weekly review\" / \"Workload\"\n" .
                "🧭 MATRIX: \"Eisenhower matrix\"\n" .
                "🧠 PLANNING: \"Plan my day\" / \"Deep work schedule\"\n" .
                "⏱️ POMODORO: \"Start pomodoro\" / \"25 min focus\"\n" .
                "🎯 FOCUS: \"Focus mode\" / \"What should I do first\"\n" .
                "🏆 ACHIEVE: \"Show my achievements\" / \"My streak\"\n" .
                "🎯 GOALS: \"Set goal X\" / \"Check my goals\"\n" .
                "📋 TEMPLATE: \"Template sprint/meeting/study/ta/launch\"\n" .
                "🔔 REMINDER: \"Remind me about X tomorrow at 9am\"\n" .
                "🔄 BATCH: \"Update all deadlines to next week\"\n" .
                "🤖 MULTI: \"Create tasks A, B, and C\"\n" .
                "💡 SUGGEST: \"Smart suggestions\" / \"Tips\"\n" .
                "❤️ MOOD: \"I'm stressed\" / \"Feeling burnout\""
            );
        }
        return $this->respond(
            "📖 Perintah Jarvis v3:\n\n" .
            "📝 BUAT: \"Buat tugas X besok jam 3 sore [high]\"\n" .
            "✅ SELESAI: \"Tandai tugas X selesai\"\n" .
            "🗑️ HAPUS: \"Hapus tugas X\" / \"Hapus semua selesai\"\n" .
            "✏️ EDIT: \"Ubah deadline/prioritas/nama X\"\n" .
            "📋 LIHAT: \"Lihat tugas\" / \"Tugas overdue\" / \"Hari ini\"\n" .
            "🔍 CARI: \"Cari tugas tentang meeting\"\n" .
            "📊 ANALITIK: \"Statistik\" / \"Review mingguan\" / \"Beban kerja\"\n" .
            "🧭 MATRIX: \"Eisenhower matrix\"\n" .
            "🧠 PERENCANAAN: \"Rencana harian\" / \"Jadwal deep work\"\n" .
            "⏱️ POMODORO: \"Mulai pomodoro\" / \"25 menit fokus\"\n" .
            "🎯 FOKUS: \"Mode fokus\" / \"Mana yang harus duluan\"\n" .
            "🏆 PENCAPAIAN: \"Lihat pencapaian\" / \"Streak saya\"\n" .
            "🎯 GOAL: \"Buat goal X\" / \"Lihat goal saya\"\n" .
            "📋 TEMPLATE: \"Template sprint/meeting/study/ta/launch\"\n" .
            "🔔 PENGINGAT: \"Ingatkan saya X besok jam 9\"\n" .
            "🔄 BATCH: \"Ganti semua deadline ke minggu depan\"\n" .
            "🤖 MULTI: \"Buat tugas A, B, dan C\"\n" .
            "💡 SARAN: \"Saran cerdas\" / \"Tips\"\n" .
            "❤️ MOOD: \"Saya stres\" / \"Merasa burnout\""
        );
    }

    private function respondMotivation(): array
    {
        $pending   = $this->todos->where('is_completed', false)->count();
        $completed = $this->todos->where('is_completed', true)->count();

        if ($this->lang === 'en') {
            $tips = [
                "Start with the smallest task. Small wins build big momentum, {$this->userName}.",
                "Pomodoro technique: 25 min focus → 5 min break. Proven to boost productivity!",
                "Progress > Perfection. An imperfect done task beats a perfect unfinished one.",
                "Break big tasks into micro-steps. What's the ONE next action?",
                "Your brain is a doing machine, not a storage machine. Trust your system!",
            ];
        } else {
            $tips = [
                "Mulai dari tugas paling kecil. Kemenangan kecil membangun momentum besar, {$this->userName}.",
                "Teknik Pomodoro: 25 menit fokus → 5 menit istirahat. Terbukti meningkatkan produktivitas!",
                "Progress > Perfection. Tugas selesai yang tidak sempurna lebih baik dari tugas sempurna yang belum selesai.",
                "Pecah tugas besar jadi langkah mikro. Apa SATU tindakan berikutnya?",
                "Otak Anda adalah mesin eksekusi, bukan mesin penyimpanan. Percayai sistem Anda!",
            ];
        }

        $tip          = $tips[array_rand($tips)];
        $completedMsg = $completed > 0 ? $this->t(" Anda sudah selesaikan {$completed} tugas — bukti Anda bisa!"," You've completed {$completed} — proof you can!") : '';
        $pendingMsg   = $pending > 0   ? $this->t(" Ada {$pending} tugas menunggu."," {$pending} tasks waiting.") : '';

        return $this->respond("💪 {$tip}{$completedMsg}{$pendingMsg}", null, ['focus_mode', 'pomodoro']);
    }

    private function respondTaskNotFound(string $query): array
    {
        $suggestions = $this->todos->where('is_completed', false)->take(5);
        $text        = $this->t(
            "Maaf {$this->userName}, tidak ada tugas yang cocok dengan \"{$query}\".",
            "Sorry {$this->userName}, no task matches \"{$query}\"."
        );
        if ($suggestions->isNotEmpty()) {
            $text .= $this->t(" Tugas yang ada:\n", " Available tasks:\n");
            foreach ($suggestions as $t) $text .= "— \"{$t->judul}\"\n";
        }
        return $this->respond($text);
    }

    // ═══════════════════════════════════════════════════════════════
    // RESPONSE BUILDER — with optional quick_replies
    // ═══════════════════════════════════════════════════════════════

    /**
     * Build unified response payload.
     *
     * @param string      $message       Text to show the user
     * @param array|null  $action        Mutation action (create_task, toggle_task, etc.)
     * @param array|null  $quickReplies  Suggested follow-up commands (intent keys → shown as chips)
     * @param array       $extra         Additional metadata (session progress, chain-of-thought, etc.)
     */
    private function respond(string $message, ?array $action = null, ?array $quickReplies = null, array $extra = []): array
    {
        // v13.0: Always include compute device info
        $extra['compute_device'] = $this->computeDevice;
        $extra['version'] = self::VERSION;

        $res = [
            'message'      => $message,
            'action'       => $action,
            'quickReplies' => $quickReplies ?: $this->getDefaultQuickReplies(),
        ];
        
        return array_merge($res, $extra);
    }

    /**
     * Remove markdown, brackets, and emojis for clean TTS output
     */
    private function cleanTextForTts(string $text): string
    {
        // Remove markdown symbols
        $text = preg_replace('/[*_#~`>]/', '', $text);
        // Remove bracketed IDs [ID:123] or [1]
        $text = preg_replace('/\[[^\]]+\]/', '', $text);
        
        // v12.1: Hardened Emoji removal (preserves text while removing all visual noise for TTS)
        $text = preg_replace('/[\x{1F300}-\x{1F64F}\x{1F680}-\x{1F6FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}\x{1F900}-\x{1F9FF}\x{1F1E0}-\x{1F1FF}]/u', '', $text);
        
        // Clean up whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }

    /**
     * Convert intent keys → human-readable labels in current language
     */
    private function resolveQuickReplyLabels(array $intents): array
    {
        $map = [
            'focus_mode'        => ['🎯 Mode Fokus',         '🎯 Focus Mode'],
            'daily_planner'     => ['📋 Rencana Harian',     '📋 Daily Plan'],
            'deep_work'         => ['🧠 Deep Work',          '🧠 Deep Work'],
            'pomodoro'          => ['⏱️ Pomodoro',           '⏱️ Pomodoro'],
            'eisenhower'        => ['🧭 Eisenhower Matrix',  '🧭 Eisenhower Matrix'],
            'stats'             => ['📊 Statistik',          '📊 Stats'],
            'weekly_review'     => ['📅 Review Mingguan',    '📅 Weekly Review'],
            'productivity'      => ['💡 Produktivitas',      '💡 Productivity'],
            'workload'          => ['⚡ Beban Kerja',         '⚡ Workload'],
            'smart_suggest'     => ['💡 Saran Cerdas',       '💡 Smart Tips'],
            'streak'            => ['🏆 Pencapaian',         '🏆 Achievements'],
            'set_goal'          => ['🎯 Buat Goal',          '🎯 Set Goal'],
            'check_goal'        => ['📌 Lihat Goal',         '📌 My Goals'],
            'reschedule_overdue'=> ['🔄 Jadwal Ulang',       '🔄 Reschedule Overdue'],
            'help'              => ['❓ Bantuan',             '❓ Help'],
            'motivation'        => ['💪 Motivasi',           '💪 Motivation'],
            'recall_memory'     => ['🧠 Apa yang kau ingat?', '🧠 What do you remember?'],
            'memory_stats'      => ['📦 Status Memori',      '📦 Memory Stats'],
            'batal'             => ['❌ Batal',              '❌ Cancel'],
            'skip'              => ['⏭️ Lewati',            '⏭️ Skip'],
        ];

        $labels = [];
        $isEn   = $this->lang === 'en';
        foreach (array_slice($intents, 0, 3) as $intent) {
            if (isset($map[$intent])) {
                $labels[] = $isEn ? $map[$intent][1] : $map[$intent][0];
            }
        }
        return $labels;
    }

    // ═══════════════════════════════════════════════════════════════
    // MEMORY INTENT SCORING
    // ═══════════════════════════════════════════════════════════════

    private function scoreRememberPreference(string $msg): int
    {
        // Don't trigger if it's a forget command
        if (str_contains($msg, 'lupakan') || str_contains($msg, 'forget') || str_contains($msg, 'hapus')) {
            return 0;
        }

        $patterns = [
            'panggil aku', 'panggil saya', 'nama saya', 'nama aku', 'call me',
            'my name is', 'ingat bahwa', 'remember that', 'ingat ini',
            'remember this', 'saya suka', 'i prefer', 'i like',
            'bahasa favorit', 'preferred language', 'jam kerja', 'work hours',
            'gaya sapaan', 'greeting style',
        ];
        return $this->matchesAny($msg, $patterns) ? 92 : 0;
    }

    private function scoreRecallMemory(string $msg): int
    {
        $patterns = [
            'apa yang kau ingat', 'apa yang kamu ingat', 'what do you remember',
            'what do you know about me', 'apa yang kau tahu', 'ingat apa tentang',
            'nama panggilan', 'my nickname', 'siapa aku', 'who am i',
            'kamu tau apa', 'you know about',
        ];
        return $this->matchesAny($msg, $patterns) ? 90 : 0;
    }

    private function scoreForgetMemory(string $msg): int
    {
        $patterns = [
            'lupakan', 'forget', 'hapus memori', 'delete memory', 'clear memory',
            'reset preferences', 'reset preferensi', 'lupakan nama', 'forget my name',
            'hapus ingatan', 'jangan ingat',
        ];
        return $this->matchesAny($msg, $patterns) ? 90 : 0;
    }

    private function scoreTeachAi(string $msg): int
    {
        return $this->matchesAny($msg, ['kalau aku bilang', 'if i say', 'artinya adalah', 'itu artinya', 'maksudnya adalah']) ? 95 : 0;
    }

    private function scoreConversationSummary(string $msg): int
    {
        $patterns = [
            'apa yang kita bahas', 'what did we discuss', 'percakapan terakhir',
            'last conversation', 'recap', 'rekap chat', 'ringkasan percakapan',
            'conversation summary', 'obrolan sebelumnya', 'previous chat',
        ];
        return $this->matchesAny($msg, $patterns) ? 85 : 0;
    }

    private function scoreMemoryStats(string $msg): int
    {
        $patterns = [
            'status memori', 'memory stats', 'berapa banyak ingatan',
            'how much do you remember', 'memory status', 'kapasitas memori',
            'memory capacity', 'statistik memori',
        ];
        return $this->matchesAny($msg, $patterns) ? 82 : 0;
    }

    // ═══════════════════════════════════════════════════════════════
    // MEMORY INTENT RESPONSES
    // ═══════════════════════════════════════════════════════════════

    private function respondRememberPreference(string $msg, string $original): array
    {
        if (!$this->memory) {
            return $this->respond($this->t(
                "Maaf {$this->userName}, fitur memori belum aktif. Hubungi admin untuk mengaktifkan Supabase.",
                "Sorry {$this->userName}, memory feature is not active. Contact admin to enable Supabase."
            ));
        }

        // Parse "panggil aku X" / "call me X" / "nama saya X"
        if (preg_match('/(?:panggil (?:aku|saya)|call me|my name is|nama (?:saya|aku))\s+(.+)/iu', $original, $m)) {
            $nickname = trim($m[1], ' ."\'!?');
            $this->memory->setNickname($nickname);
            $this->userName = $nickname;
            return $this->respond($this->t(
                "Baik, mulai sekarang saya akan memanggil Anda \"{$nickname}\". Senang berkenalan!",
                "Got it! I'll call you \"{$nickname}\" from now on. Nice to meet you!"
            ), null, ['recall_memory', 'help']);
        }

        // Parse "saya suka X" / "i prefer X"
        if (preg_match('/(?:saya suka|i prefer|i like|bahasa favorit|preferred language)\s+(.+)/iu', $original, $m)) {
            $pref = trim($m[1], ' ."\'!?');
            $this->memory->remember('preference', 'likes', $pref);
            return $this->respond($this->t(
                "Tercatat! Saya akan ingat bahwa Anda suka \"{$pref}\".",
                "Noted! I'll remember that you like \"{$pref}\"."
            ), null, ['recall_memory', 'help']);
        }

        // Parse "jam kerja X-Y" / "work hours X to Y"
        if (preg_match('/(?:jam kerja|work hours?)\s*(\d{1,2})[:\.]?(\d{2})?\s*[-to]+\s*(\d{1,2})[:\.]?(\d{2})?/iu', $original, $m)) {
            $start = sprintf('%02d:%s', $m[1], $m[2] ?? '00');
            $end   = sprintf('%02d:%s', $m[3], $m[4] ?? '00');
            $this->memory->remember('preference', 'work_hours', ['start' => $start, 'end' => $end]);
            return $this->respond($this->t(
                "Tercatat! Jam kerja Anda: {$start} - {$end}.",
                "Noted! Your work hours: {$start} - {$end}."
            ), null, ['recall_memory', 'daily_planner']);
        }

        // Parse "ingat bahwa X" / "remember that X"
        if (preg_match('/(?:ingat (?:bahwa|ini|ya)|remember (?:that|this))\s+(.+)/iu', $original, $m)) {
            $fact = trim($m[1], ' ."\'!?');
            $key  = mb_substr(preg_replace('/[^a-z0-9_]/', '_', mb_strtolower($fact)), 0, 50);
            $this->memory->rememberFact($key, $fact);
            return $this->respond($this->t(
                "Tercatat! Saya akan mengingat: \"{$fact}\".",
                "Noted! I'll remember: \"{$fact}\"."
            ), null, ['recall_memory', 'help']);
        }

        return $this->respond($this->t(
            "Saya bisa mengingat nama panggilan, preferensi, atau fakta tentang Anda. Contoh: \"Panggil aku Fajar\" atau \"Ingat bahwa saya suka kopi\".",
            "I can remember your nickname, preferences, or facts about you. Example: \"Call me Fajar\" or \"Remember that I like coffee\"."
        ), null, ['recall_memory', 'help']);
    }

    private function respondRecallMemory(string $msg): array
    {
        if (!$this->memory) {
            return $this->respond($this->t(
                "Fitur memori belum aktif.",
                "Memory feature is not active."
            ));
        }

        $parts = [];

        // Nickname
        $nickname = $this->memory->getNickname();
        if ($nickname) {
            $parts[] = $this->t("Nama panggilan: {$nickname}", "Nickname: {$nickname}");
        }

        // Facts
        $facts = $this->memory->getAllFacts();
        if (!empty($facts)) {
            $factList = collect($facts)->map(fn($f) => "- " . ($f['value']['data'] ?? json_encode($f['value'])))->implode("\n");
            $parts[] = $this->t("Fakta yang saya ingat:\n{$factList}", "Facts I remember:\n{$factList}");
        }

        // Preferences
        $prefs = $this->memory->recallAll('preference');
        $prefItems = collect($prefs)->filter(fn($p) => $p['key'] !== 'nickname')->values();
        if ($prefItems->isNotEmpty()) {
            $prefList = $prefItems->map(fn($p) => "- {$p['key']}: " . ($p['value']['data'] ?? json_encode($p['value'])))->implode("\n");
            $parts[] = $this->t("Preferensi:\n{$prefList}", "Preferences:\n{$prefList}");
        }

        // Last conversation
        $lastConvo = $this->memory->getLastConversation();
        if ($lastConvo) {
            $parts[] = $this->t(
                "Percakapan terakhir ({$lastConvo['date']}): {$lastConvo['summary']}",
                "Last conversation ({$lastConvo['date']}): {$lastConvo['summary']}"
            );
        }

        if (empty($parts)) {
            return $this->respond($this->t(
                "Saya belum punya memori tentang Anda, {$this->userName}. Coba katakan \"Panggil aku [nama]\" untuk memulai!",
                "I don't have any memories about you yet, {$this->userName}. Try saying \"Call me [name]\" to start!"
            ), null, ['remember_preference', 'help']);
        }

        $text = $this->t("Ini yang saya ingat tentang Anda, {$this->userName}:\n\n", "Here's what I remember about you, {$this->userName}:\n\n");
        $text .= implode("\n\n", $parts);

        return $this->respond($text, null, ['forget_memory', 'memory_stats']);
    }

    private function respondForgetMemory(string $msg): array
    {
        if (!$this->memory) {
            return $this->respond($this->t("Fitur memori belum aktif.", "Memory feature is not active."));
        }

        // Forget nickname specifically
        if ($this->matchesAny($msg, ['lupakan nama', 'forget my name', 'forget nickname', 'hapus nama'])) {
            $this->memory->forget('preference', 'nickname');
            return $this->respond($this->t(
                "Nama panggilan sudah saya lupakan. Saya akan memanggil Anda 'Tuan' lagi.",
                "Nickname forgotten. I'll call you 'Tuan' again."
            ), null, ['remember_preference', 'help']);
        }

        // Forget all preferences
        if ($this->matchesAny($msg, ['reset preference', 'reset preferensi', 'clear preference'])) {
            $this->memory->forgetAll('preference');
            return $this->respond($this->t(
                "Semua preferensi sudah direset.",
                "All preferences have been reset."
            ), null, ['remember_preference', 'help']);
        }

        // Forget everything
        if ($this->matchesAny($msg, ['clear memory', 'hapus semua memori', 'hapus ingatan', 'lupakan semua', 'forget everything'])) {
            $this->memory->forgetAll('preference');
            $this->memory->forgetAll('fact');
            $this->memory->forgetAll('correction');
            $this->memory->forgetAll('pattern');
            return $this->respond($this->t(
                "Semua memori sudah dihapus. Kita mulai dari awal!",
                "All memories cleared. We start fresh!"
            ), null, ['remember_preference', 'help']);
        }

        return $this->respond($this->t(
            "Apa yang ingin Anda lupakan? Contoh: \"Lupakan nama\" atau \"Hapus semua memori\".",
            "What do you want me to forget? Example: \"Forget my name\" or \"Clear memory\"."
        ), null, ['recall_memory', 'help']);
    }

    private function respondConversationSummary(): array
    {
        if (!$this->memory) {
            return $this->respond($this->t("Fitur memori belum aktif.", "Memory feature is not active."));
        }

        $conversations = $this->memory->getRecentConversations(5);
        if (empty($conversations)) {
            return $this->respond($this->t(
                "Belum ada riwayat percakapan yang tersimpan, {$this->userName}.",
                "No conversation history saved yet, {$this->userName}."
            ), null, ['help', 'stats']);
        }

        $text = $this->t("Riwayat percakapan terbaru:\n\n", "Recent conversations:\n\n");
        foreach ($conversations as $i => $convo) {
            $num = $i + 1;
            $topics = !empty($convo['topics']) ? ' [' . implode(', ', $convo['topics']) . ']' : '';
            $mood   = $convo['mood'] ? " ({$convo['mood']})" : '';
            $text  .= "{$num}. {$convo['date']}{$mood}{$topics}\n   {$convo['summary']}\n\n";
        }

        return $this->respond($text, null, ['stats', 'help']);
    }

    private function respondMemoryStats(): array
    {
        if (!$this->memory) {
            return $this->respond($this->t("Fitur memori belum aktif.", "Memory feature is not active."));
        }

        $stats = $this->memory->getStats();
        $text = $this->t(
            "Status Memori AI:\n" .
            "Total: {$stats['total']} item\n" .
            "Preferensi: {$stats['preferences']}\n" .
            "Koreksi: {$stats['corrections']}\n" .
            "Pola: {$stats['patterns']}\n" .
            "Fakta: {$stats['facts']}\n" .
            "Konteks: {$stats['contexts']}",
            "AI Memory Status:\n" .
            "Total: {$stats['total']} items\n" .
            "Preferences: {$stats['preferences']}\n" .
            "Corrections: {$stats['corrections']}\n" .
            "Patterns: {$stats['patterns']}\n" .
            "Facts: {$stats['facts']}\n" .
            "Contexts: {$stats['contexts']}"
        );

        return $this->respond($text, null, ['recall_memory', 'help']);
    }

    private function respondTeachAi(string $msg): array
    {
        if (!$this->memory) {
            return $this->respond($this->t("Fitur memori belum aktif.", "Memory feature is not active."));
        }

        $phrase = '';
        $intentStr = '';

        if (preg_match('/bilang [\'"](.+?)[\'"]/', $msg, $m1)) {
            $phrase = $m1[1];
        }

        if (preg_match('/artinya [\'"](.+?)[\'"]/', $msg, $m2)) {
            $intentStr = $m2[1];
        }

        if (!$phrase && preg_match('/bilang (.+?) itu/', $msg, $m1)) {
            $phrase = trim($m1[1]);
        }
        if (!$intentStr && preg_match('/artinya (.+)$/', $msg, $m2)) {
            $intentStr = trim($m2[1]);
        }

        if ($phrase && $intentStr) {
            $intentKey = $this->mapLabelToIntent($intentStr);
            $this->memory->learnSynonym($phrase, $intentKey);
            
            return $this->respond($this->t(
                "Baik {$this->userName}, saya sudah belajar! Mulai sekarang kalau Anda bilang '{$phrase}', saya akan menganggapnya sebagai perintah '{$intentKey}'.",
                "Got it {$this->userName}, I've learned! From now on, when you say '{$phrase}', I'll treat it as a '{$intentKey}' command."
            ), null, ['memory_stats', 'help']);
        }

        return $this->respond($this->t(
            "Cara mengajari saya: \"Kalau aku bilang 'makan', itu artinya 'buat tugas'\"",
            "To teach me: \"If I say 'eat', it means 'create task'\""
        ), null, ['help']);
    }

    // ═══════════════════════════════════════════════════════════════
    // LANGUAGE SWITCHING — dynamic runtime language change
    // ═══════════════════════════════════════════════════════════════

    private function scoreSwitchLanguage(string $msg): int
    {
        $patterns = [
            'pakai bahasa','ganti bahasa','switch to','use english','pake bahasa',
            'speak in','talk in','bahasa inggris','bahasa indonesia','bahasa jawa',
            'bahasa sunda','bahasa betawi','ganti ke bahasa','switch language',
            'change language','gunakan bahasa','ngomong pake', 'update bahasa',
        ];
        return $this->matchesAny($msg, $patterns) ? 96 : 0;
    }

    private function respondSwitchLanguage(string $msg, string $original): array
    {
        // Detect the target language from the message
        $targetLang = $this->parseTargetLanguage($msg);

        if (!$targetLang) {
            return $this->respond($this->t(
                "Bahasa apa yang Anda inginkan? Pilihan: Indonesia, English, Jawa, Sunda, Betawi.",
                "Which language would you like? Options: Indonesia, English, Javanese, Sundanese, Betawi."
            ), null, ['help']);
        }

        $oldLang = $this->lang;
        $this->lang = $targetLang;

        // Save to Supabase memory
        if ($this->memory) {
            $this->memory->setPreferredLanguage($targetLang);
        }

        $langNames = [
            'id' => ['Indonesia', 'Indonesian'],
            'en' => ['English', 'English'],
            'jv' => ['Jawa', 'Javanese'],
            'su' => ['Sunda', 'Sundanese'],
            'bt' => ['Betawi', 'Betawi'],
        ];

        $name = $langNames[$targetLang] ?? ['Unknown', 'Unknown'];

        return $this->respond($this->t(
            "Bahasa berhasil diganti ke {$name[0]}! Mulai sekarang saya akan merespons dalam {$name[0]}, {$this->userName}.",
            "Language switched to {$name[1]}! From now on I'll respond in {$name[1]}, {$this->userName}.",
            "Basa sampun diganti dados Jawi! Samenika kula badhe mangsuli ngangge basa Jawi, {$this->userName}.",
            "Basa parantos diganti ka Sunda! Ti ayeuna abdi bade ngawaler ku basa Sunda, {$this->userName}.",
            "Bahasa udah diganti ke Betawi! Mulai sekarang gue bakal bales pake bahasa Betawi, {$this->userName}."
        ), null, ['help', 'stats']);
    }

    /**
     * Parse the target language from a language switch request.
     */
    private function parseTargetLanguage(string $msg): ?string
    {
        // English
        if (preg_match('/\b(english|inggris|en)\b/i', $msg)) return 'en';
        // Javanese
        if (preg_match('/\b(jawa|javanese|jv|jowo)\b/i', $msg)) return 'jv';
        // Sundanese
        if (preg_match('/\b(sunda|sundanese|su|urang sunda)\b/i', $msg)) return 'su';
        // Betawi
        if (preg_match('/\b(betawi|bt|jakarta|betawe)\b/i', $msg)) return 'bt';
        // Indonesian (check last since it's the default)
        if (preg_match('/\b(indonesia|indonesian|id|indo|melayu)\b/i', $msg)) return 'id';

        return null;
    }

    private function mapLabelToIntent(string $label): string
    {
        $map = [
            'buat tugas' => 'create',
            'tambah tugas' => 'create',
            'hapus tugas' => 'delete',
            'daftar tugas' => 'list',
            'cari tugas' => 'search',
            'statistik' => 'stats',
            'fokus' => 'focus_mode',
            'istirahat' => 'pomodoro',
        ];

        foreach ($map as $key => $intent) {
            if (stripos($label, $key) !== false) return $intent;
        }

        return 'general_chat';
    }

    // ═══════════════════════════════════════════════════════════════
    // LEARNED PATTERN MATCHING — boost scores from memory
    // ═══════════════════════════════════════════════════════════════

    /**
     * Apply learned synonym/pattern boosts from Supabase memory.
     * If the user previously taught the AI a phrase → intent mapping,
     * boost that intent's score when the phrase appears again.
     */
    private function applyLearnedPatterns(string $msg, array $scores): array
    {
        if (!$this->memory) return $scores;

        try {
            $patterns = $this->memory->getLearnedPatterns();
            foreach ($patterns as $pattern) {
                $phrase = mb_strtolower($pattern['value']['phrase'] ?? '');
                $intent = $pattern['value']['intent'] ?? '';
                if ($phrase && $intent && stripos($msg, $phrase) !== false) {
                    $boost = min(95, ($pattern['hits'] ?? 1) * 10 + 70);
                    if (isset($scores[$intent])) {
                        $scores[$intent] = max($scores[$intent], $boost);
                    }
                }
            }

            // Also apply corrections: if user corrected a phrase before, remap
            $corrections = $this->memory->getCorrections();
            foreach ($corrections as $correction) {
                $original  = mb_strtolower($correction['value']['original'] ?? '');
                $corrected = $correction['value']['corrected'] ?? '';
                if ($original && stripos($msg, $original) !== false) {
                    // Re-score with the corrected text instead
                    // This is a lightweight re-mapping, not a full re-score
                }
            }
        } catch (\Exception $e) {
            // Memory unavailable — continue without learned patterns
        }

        return $scores;
    }
}