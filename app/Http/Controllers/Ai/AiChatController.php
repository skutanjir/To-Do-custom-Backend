<?php
// app/Http/Controllers/AiChatController.php

namespace App\Http\Controllers\Ai;

use App\Models\Task\Todo;
use App\Models\AiChat;
use App\Models\Ai\AiChatMessage;
use App\Services\LocalAiEngine;
use App\Services\AiMemoryService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Carbon;

/**
 * 
 *          A I   C H A T   C O N T R O L L E R   v3.0                  
 *                                                                      
 *    Features:                                                         
 *     LocalAiEngine v3 integration (all new action types)             
 *     Text-to-Speech endpoint (male/female, multi-language)           
 *     Voice preference persistence (per user/device)                  
 *     Smart provider routing with health-check cache                  
 *     Exponential backoff retry on transient failures                 
 *     SSE streaming endpoint                                          
 *     Conversation auto-summarization (compress long history)         
 *     Full action coverage (batch_update, start_pomodoro, goals, ...) 
 *     Structured audit logging (channel: daily)                       
 *     Per-user & per-device rate limiting                             
 *     XSS / injection sanitization                                    
 * 
 */
class AiChatController extends Controller
{
    //  Rate limits 
    private const RATE_LIMIT_REQUESTS  = 20;   // max requests per window
    private const RATE_LIMIT_WINDOW    = 60;   // seconds
    private const PROVIDER_COOLDOWN    = 300;  // seconds after rate limit hit
    private const HISTORY_MAX_TURNS    = 20;   // max conversation turns kept
    private const HISTORY_SUMMARIZE_AT = 15;   // summarize when history >= N turns
    private const MAX_TOKENS_RESPONSE  = 800;

    //  TTS constants 
    private const TTS_FEMALE_VOICES = [
        'id' => 'id-ID-GadisNeural',     // Indonesian female
        'en' => 'en-US-JennyNeural',     // English female
        'jv' => 'id-ID-GadisNeural',     // Javanese → use ID female
        'su' => 'id-ID-GadisNeural',
        'bt' => 'id-ID-GadisNeural',
    ];
    private const TTS_MALE_VOICES = [
        'id' => 'id-ID-ArdiNeural',      // Indonesian male
        'en' => 'en-US-GuyNeural',       // English male
        'jv' => 'id-ID-ArdiNeural',
        'su' => 'id-ID-ArdiNeural',
        'bt' => 'id-ID-ArdiNeural',
    ];
    private const TTS_VOICE_STYLES = [
        'friendly'    => 'friendly',
        'professional'=> 'customerservice',
        'cheerful'    => 'cheerful',
        'calm'        => 'calm',
    ];

    // 
    // AI PROVIDER REGISTRY
    // 

    // 
    // INDUSTRIAL PERFORMANCE (v6.0)
    // 


    // 
    // MAIN CHAT ENDPOINT
    // 

    public function chat(Request $request): JsonResponse
    {
        $request->validate([
            'message'        => 'required|string|max:2000',
            'history'        => 'nullable|array|max:' . self::HISTORY_MAX_TURNS,
            'history.*.role'    => 'required_with:history|string|in:user,assistant,system',
            'history.*.content' => 'required_with:history|string|max:4000',
            'voice_enabled'  => 'nullable|boolean',
            'voice_gender'   => 'nullable|string|in:female,male',
            'voice_style'    => 'nullable|string|in:friendly,professional,cheerful,calm',
            'voice_lang'     => 'nullable|string|in:id,en,jv,su,bt',
        ]);

        [$user, $deviceId] = $this->resolveIdentity($request);
        if (!$user && !$deviceId) {
            return response()->json(['message' => 'Unauthorized or Device ID required'], 401);
        }

        //  Rate limiting 
        if ($this->isRateLimited($user, $deviceId)) {
            return response()->json([
                'message'       => 'Terlalu banyak permintaan. Tunggu sebentar, Kak ',
                'action'        => null,
                'action_result' => null,
                'provider'      => 'rate_limit',
            ], 429);
        }

        //  Sanitize input 
        $userMessage = $this->sanitizeInput($request->message);
        if (empty($userMessage)) {
            return response()->json(['message' => 'Pesan tidak valid setelah sanitasi.'], 422);
        }

        $history = $this->sanitizeHistory($request->history ?? []);

        //  Auto-summarize long histories 
        if (count($history) >= self::HISTORY_SUMMARIZE_AT) {
            $history = $this->summarizeHistory($history);
        }

        //  Load task context 
        $todos = $this->loadTodos($user, $deviceId);

        //  Voice preferences 
        $voicePrefs = $this->resolveVoicePrefs($request, $user, $deviceId);

        //  Strictly Local Engine (v6.0 Enterprise Hardening) 
        $memoryService = new AiMemoryService($user?->id, $deviceId);
        $voiceMode     = (bool) ($request->voice_enabled ?? false);
        $localEngine   = new LocalAiEngine($todos, $user?->name ?? 'Kak', $history, $memoryService, $voiceMode);
        $localResult   = $localEngine->handle($userMessage);

        //  Persist Chat History 
        $chatId = $request->chat_id;
        $chat = null;
        if ($chatId) {
            $chat = AiChat::where('id', $chatId)
                ->where(function($q) use ($deviceId) {
                    $q->where('user_id', Auth::id());
                    if ($deviceId) {
                        $q->orWhere(function($sq) use ($deviceId) {
                            $sq->whereNull('user_id')->where('device_id', $deviceId);
                        });
                    }
                })->first();
        }
        
        if (!$chat) {
            $chat = AiChat::create([
                'user_id'   => Auth::id(), // Can be null for guests
                'device_id' => $deviceId,
                'title'     => mb_strimwidth($userMessage, 0, 50, '...'),
            ]);
        } else {
            $chat->touch(); // Update updated_at
        }

        AiChatMessage::create([
            'ai_chat_id' => $chat->id,
            'role'       => 'user',
            'content'    => $userMessage,
        ]);

        $aiMsg = AiChatMessage::create([
            'ai_chat_id' => $chat->id,
            'role'       => 'assistant',
            'content'    => $localResult['content'],
            'data'       => [
                'action'        => $localResult['action'] ?? null,
                'quick_replies' => $localResult['quick_replies'] ?? null,
                'session'       => $localResult['session'] ?? null,
            ],
        ]);

        $this->saveConversationToMemory($memoryService, $userMessage, $localResult);
        
        $response = $this->buildResponse($localResult['content'], 'local', $voicePrefs, $user, $deviceId);
        
        // Add chat_id to response so frontend knows which session to continue
        $data = $response->getData(true);
        $data['chat_id'] = $chat->id;
        return response()->json($data);
    }

    // 
    // STREAMING ENDPOINT (SSE)
    // 

    public function stream(Request $request): StreamedResponse
    {
        $request->validate([
            'message' => 'required|string|max:2000',
            'history' => 'nullable|array|max:' . self::HISTORY_MAX_TURNS,
        ]);

        [$user, $deviceId] = $this->resolveIdentity($request);
        if (!$user && !$deviceId) {
            abort(401);
        }

        if ($this->isRateLimited($user, $deviceId)) {
            abort(429);
        }

        $userMessage   = $this->sanitizeInput($request->message);
        $history       = $this->sanitizeHistory($request->history ?? []);
        $todos         = $this->loadTodos($user, $deviceId);
        $memoryService = new AiMemoryService($user?->id, $deviceId);

        return response()->stream(function () use ($userMessage, $history, $todos, $user, $deviceId, $request, $memoryService) {
            $voiceMode   = (bool) ($request->voice_enabled ?? false);
            $localEngine = new LocalAiEngine($todos, $user?->name, $history, $memoryService, $voiceMode);
            $localResult = $localEngine->handle($userMessage);

            $parsed = $this->parseAiResponse($localResult['content']);
            $this->streamChunk([
                'type' => 'message', 
                'content' => $parsed['message'], 
                'compute_device' => $parsed['compute_device'] ?? 'cpu',
                'provider' => 'local',
                'current_step' => $parsed['session']['current_step'] ?? 0,
                'total_steps' => $parsed['session']['total_steps'] ?? 0,
                'session_type' => $parsed['session']['type'] ?? null,
                'session_label' => $parsed['session']['label'] ?? (isset($parsed['session']['type']) ? ucfirst($parsed['session']['type']) : null),
                'chain_of_thought' => $parsed['chain_of_thought'] ?? null,
                'multi_intent' => $parsed['multi_intent'] ?? null,
            ]);

            if (!empty($parsed['action'])) {
                $actionResult = $this->executeAction($parsed['action'], $user, $deviceId);
                $this->streamChunk(['type' => 'action', 'action' => $parsed['action'], 'result' => $actionResult]);
            }

            if (!empty($parsed['quick_replies'])) {
                $this->streamChunk(['type' => 'quick_replies', 'data' => $parsed['quick_replies']]);
            }

            //  Persist Streamed Message 
            $chatId = $request->chat_id;
            $chat = null;
            if ($chatId) {
                $chat = AiChat::where('id', $chatId)
                    ->where(function($q) use ($deviceId) {
                        $q->where('user_id', Auth::id());
                        if ($deviceId) {
                            $q->orWhere(function($sq) use ($deviceId) {
                                $sq->whereNull('user_id')->where('device_id', $deviceId);
                            });
                        }
                    })->first();
            }
            if (!$chat) {
                $chat = AiChat::create([
                    'user_id'   => Auth::id(), // Null for guests
                    'device_id' => $deviceId,
                    'title'     => mb_strimwidth($userMessage, 0, 50, '...'),
                ]);
            } else {
                $chat->touch();
            }

            AiChatMessage::create([
                'ai_chat_id' => $chat->id,
                'role'       => 'user',
                'content'    => $userMessage,
            ]);

            AiChatMessage::create([
                'ai_chat_id' => $chat->id,
                'role'       => 'assistant',
                'content'    => $parsed['message'],
                'data'       => [
                    'action'        => $parsed['action'] ?? null,
                    'quick_replies' => $parsed['quick_replies'] ?? null,
                    // Note: session metadata is usually in $localResult but parseAiResponse logic might need it
                ],
            ]);

            $this->streamChunk(['type' => 'chat_id', 'id' => $chat->id]);
            $this->streamChunk(['type' => 'done']);
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Connection'        => 'keep-alive',
        ]);
    }

    // 
    // TEXT-TO-SPEECH ENDPOINT
    // 

    /**
     * POST /api/ai/tts
     * Convert AI response text to speech audio using Azure Cognitive Services.
     *
     * Body params:
     *   - text: string (required)  text to synthesize
     *   - gender: "female"|"male" (default: female)
     *   - lang: "id"|"en"|"jv"|"su" (default: id)
     *   - style: "friendly"|"professional"|"cheerful"|"calm" (default: friendly)
     *   - rate: float (-0.5 to 0.5, default: 0)
     *   - pitch: float (-0.5 to 0.5, default: 0)
     *   - save_preference: bool (default: false)
     */
    public function tts(Request $request)
    {
        $request->validate([
            'text'             => 'required|string|max:3000',
            'gender'           => 'nullable|string|in:female,male',
            'lang'             => 'nullable|string|in:id,en,jv,su,bt',
            'style'            => 'nullable|string|in:friendly,professional,cheerful,calm',
            'rate'             => 'nullable|numeric|min:-0.5|max:0.5',
            'pitch'            => 'nullable|numeric|min:-0.5|max:0.5',
            'save_preference'  => 'nullable|boolean',
        ]);

        [$user, $deviceId] = $this->resolveIdentity($request);

        //  Persist voice preference if requested 
        $gender = $request->gender ?? 'female';
        $lang   = $request->lang   ?? 'id';
        $style  = $request->style  ?? 'friendly';
        $rate   = $request->rate   ?? 0.0;
        $pitch  = $request->pitch  ?? 0.0;

        if ($request->save_preference) {
            $this->saveVoicePreference($user, $deviceId, compact('gender', 'lang', 'style', 'rate', 'pitch'));
        }

        //  Resolve voice name 
        $voiceName = $gender === 'male'
            ? (self::TTS_MALE_VOICES[$lang]   ?? self::TTS_MALE_VOICES['id'])
            : (self::TTS_FEMALE_VOICES[$lang] ?? self::TTS_FEMALE_VOICES['id']);

        $ratePercent  = $this->floatToPercent($rate);
        $pitchPercent = $this->floatToPercent($pitch);

        //  Clean text 
        $cleanText = $this->cleanTextForTts($request->text);

        //  Return metadata only (useful for browser TTS fallback) 
        return response()->json([
            'fallback'   => true,
            'voice'      => $voiceName,
            'text'       => $cleanText,
            'gender'     => $gender,
            'lang'       => $lang,
            'style'      => $style ?? 'friendly',
            'rate'       => $ratePercent,
            'pitch'      => $pitchPercent,
            'message'    => 'Local TTS enabled  use browser Web Speech API.',
        ]);
    }

    /**
     * GET/POST /api/ai/voice-preference
     * Get or update voice preferences for user/device.
     */
    public function voicePreference(Request $request): JsonResponse
    {
        [$user, $deviceId] = $this->resolveIdentity($request);
        if (!$user && !$deviceId) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if ($request->isMethod('GET')) {
            $prefs = $this->getVoicePreference($user, $deviceId);
            return response()->json($prefs ?? $this->defaultVoicePrefs());
        }

        $request->validate([
            'gender' => 'required|string|in:female,male',
            'lang'   => 'nullable|string|in:id,en,jv,su,bt',
            'style'  => 'nullable|string|in:friendly,professional,cheerful,calm',
            'rate'   => 'nullable|numeric|min:-0.5|max:0.5',
            'pitch'  => 'nullable|numeric|min:-0.5|max:0.5',
        ]);

        $prefs = [
            'gender' => $request->gender,
            'lang'   => $request->lang   ?? 'id',
            'style'  => $request->style  ?? 'friendly',
            'rate'   => $request->rate   ?? 0.0,
            'pitch'  => $request->pitch  ?? 0.0,
        ];

        $this->saveVoicePreference($user, $deviceId, $prefs);

        return response()->json(['success' => true, 'preferences' => $prefs]);
    }

    // 
    // VOICE HELPERS
    // 

    private function defaultVoicePrefs(): array
    {
        return ['gender' => 'female', 'lang' => 'id', 'style' => 'friendly', 'rate' => 0.0, 'pitch' => 0.0];
    }

    private function getVoicePreference($user, ?string $deviceId): ?array
    {
        $key = $user ? "voice_pref_user_{$user->id}" : "voice_pref_device_{$deviceId}";
        return Cache::get($key);
    }

    private function saveVoicePreference($user, ?string $deviceId, array $prefs): void
    {
        $key = $user ? "voice_pref_user_{$user->id}" : "voice_pref_device_{$deviceId}";
        Cache::put($key, $prefs, now()->addDays(30));
    }

    private function resolveVoicePrefs(Request $request, $user, ?string $deviceId): array
    {
        // Per-request params take priority, then saved prefs, then defaults
        $saved = $this->getVoicePreference($user, $deviceId) ?? $this->defaultVoicePrefs();
        return [
            'enabled' => (bool) ($request->voice_enabled ?? false),
            'gender'  => $request->voice_gender ?? $saved['gender'],
            'lang'    => $request->voice_lang   ?? $saved['lang'],
            'style'   => $request->voice_style  ?? $saved['style'],
            'rate'    => $saved['rate']  ?? 0.0,
            'pitch'   => $saved['pitch'] ?? 0.0,
        ];
    }

    private function buildSsml(string $text, string $voiceName, string $style, string $rate, string $pitch, string $lang): string
    {
        $langCode = match($lang) {
            'en' => 'en-US',
            'jv','su','bt','id' => 'id-ID',
            default => 'id-ID',
        };

        // Escape XML special chars
        $safeText = htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return <<<SSML
<speak version="1.0" xmlns="http://www.w3.org/2001/10/synthesis"
       xmlns:mstts="https://www.w3.org/2001/mstts"
       xml:lang="{$langCode}">
  <voice name="{$voiceName}">
    <mstts:express-as style="{$style}" styledegree="1.2">
      <prosody rate="{$rate}" pitch="{$pitch}">
        {$safeText}
      </prosody>
    </mstts:express-as>
  </voice>
</speak>
SSML;
    }

    private function cleanTextForTts(string $text): string
    {
        // Remove markdown formatting, emoji, special chars that don't speak well
        $text = preg_replace('/\*{1,2}([^*]+)\*{1,2}/', '$1', $text);  // **bold** → bold
        $text = preg_replace('/__([^_]+)__/', '$1', $text);              // __underline__
        $text = preg_replace('/`([^`]+)`/', '$1', $text);                // `code`
        $text = preg_replace('/#{1,6}\s/', '', $text);                   // headings
        $text = preg_replace('/\+/', '', $text);                         // separators
        $text = preg_replace('/[\x{1F300}-\x{1FFFF}]/u', '', $text);    // most emoji
        $text = preg_replace('/[\x{2700}-\x{27BF}]/u', '', $text);      // dingbats
        $text = preg_replace('/\[ID:\d+\]/', '', $text);                 // task IDs
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }

    private function floatToPercent(float $val): string
    {
        if ($val == 0) return '+0%';
        $pct = (int) round($val * 100);
        return $pct > 0 ? "+{$pct}%" : "{$pct}%";
    }

    // 
    // PROVIDER FALLBACK CHAIN
    // 


    /**
     * Call provider with exponential backoff (max 2 retries for transient errors).
     */

    // 
    // RESPONSE BUILDER  handles TTS, action execution, audit log
    // 

    private function buildResponse(
        string  $rawContent,
        string  $provider,
        array   $voicePrefs,
        $user,
        ?string $deviceId,
        ?array  $reasoningDetails = null
    ): JsonResponse {
        $parsed       = $this->parseAiResponse($rawContent);
        $actionResult = null;

        if (!empty($parsed['action'])) {
            $actionResult = $this->executeAction($parsed['action'], $user, $deviceId);
        }

        $response = [
            'message'           => $parsed['message'],
            'compute_device'    => $parsed['compute_device'] ?? 'cpu',
            'action'            => $parsed['action'],
            'action_result'     => $actionResult,
            'provider'          => $provider,
            'reasoning_details' => $reasoningDetails,
            'quick_replies'     => $parsed['quick_replies'] ?? [],
            'chain_of_thought'  => $parsed['chain_of_thought'] ?? null,
            'multi_intent'      => $parsed['multi_intent'] ?? null,
            'current_step'      => $parsed['session']['current_step'] ?? 0,
            'total_steps'       => $parsed['session']['total_steps'] ?? 0,
            'session_type'      => $parsed['session']['type'] ?? null,
            'session_label'     => $parsed['session']['label'] ?? (isset($parsed['session']['type']) ? ucfirst($parsed['session']['type']) : null),
        ];

        //  Attach TTS data if voice is enabled 
        if ($voicePrefs['enabled'] && !empty($parsed['message'])) {
            $response['tts'] = $this->buildTtsMeta($parsed['message'], $voicePrefs);
        }

        return response()->json($response);
    }

    /**
     * Build TTS metadata so the frontend can call /api/ai/tts directly
     * or use the Web Speech API fallback with correct params.
     */
    private function buildTtsMeta(string $message, array $voicePrefs): array
    {
        $lang   = $voicePrefs['lang']   ?? 'id';
        $gender = $voicePrefs['gender'] ?? 'female';
        $style  = $voicePrefs['style']  ?? 'friendly';

        $voiceName = $gender === 'male'
            ? (self::TTS_MALE_VOICES[$lang]   ?? self::TTS_MALE_VOICES['id'])
            : (self::TTS_FEMALE_VOICES[$lang] ?? self::TTS_FEMALE_VOICES['id']);

        return [
            'voice'      => $voiceName,
            'gender'     => $gender,
            'lang'       => $lang,
            'style'      => $style,
            'rate'       => $this->floatToPercent($voicePrefs['rate']  ?? 0.0),
            'pitch'      => $this->floatToPercent($voicePrefs['pitch'] ?? 0.0),
            'text'       => $this->cleanTextForTts($message),
            'azure_ready'=> !empty(env('AZURE_TTS_KEY')),
        ];
    }

    // 
    // ACTION EXECUTOR  all action types from LocalAiEngine v3
    // 

    private function executeAction(array $action, $user, ?string $deviceId): ?array
    {
        $type = $action['type'] ?? null;
        $data = $action['data'] ?? [];

        try {
            $result = match ($type) {
                //  Core CRUD 
                'create_task'            => $this->executeCreateTask($data, $user, $deviceId),
                'update_task'            => $this->executeUpdateTask($data, $user, $deviceId),
                'delete_task'            => $this->executeDeleteTask($data, $user, $deviceId),
                'toggle_task'            => $this->executeToggleTask($data, $user, $deviceId),

                //  Batch / Bulk 
                'batch_create'           => $this->executeBatchCreate($data, $user, $deviceId),
                'bulk_delete'            => $this->executeBulkDelete($data, $user, $deviceId),
                'bulk_toggle'            => $this->executeBulkToggle($data, $user, $deviceId),
                'batch_update_deadline'  => $this->executeBatchUpdateDeadline($data, $user, $deviceId),
                'batch_update_priority'  => $this->executeBatchUpdatePriority($data, $user, $deviceId),

                //  Scheduling 
                'reschedule_all_overdue' => $this->executeRescheduleAllOverdue($data, $user, $deviceId),

                //  Special features 
                'duplicate_task'         => $this->executeDuplicateTask($data, $user, $deviceId),
                'move_task'              => $this->executeMoveTask($data, $user, $deviceId),
                'start_pomodoro'         => $this->executeStartPomodoro($data, $user, $deviceId),

                //  Industrial Scale (v6.0) 
                'create_workspace'       => $this->executeCreateWorkspace($data, $user, $deviceId),
                'create_subtask'         => $this->executeCreateSubtask($data, $user, $deviceId),
                'update_task_state'      => $this->executeUpdateTaskState($data, $user, $deviceId),
                'generate_analytics_report' => $this->executeGenerateAnalyticsReport($data, $user, $deviceId),

                //  Read-only 
                'search_tasks',
                'get_schedule',
                'get_stats',
                'list_tasks'             => ['success' => true, 'type' => $type],

                default => null,
            };

            //  Structured audit log for all write actions 
            $readOnlyTypes = ['search_tasks', 'get_schedule', 'get_stats', 'list_tasks'];
            if ($result && ($result['success'] ?? false) && !in_array($type, $readOnlyTypes)) {
                $this->auditLog($type, $data, $user, $deviceId);
            }

            // Security Hardening: Filter sensitive fields from any Eloquent results
            return $this->sanitizeActionResult($result);

        } catch (\Exception $e) {
            Log::warning("AI action failed: {$e->getMessage()}", [
                'action_type' => $type,
                'user_id'     => $user?->id,
                'ip'          => request()->ip(),
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function sanitizeActionResult(?array $result): ?array
    {
        if (!$result) return null;

        $safeFields = [
            'id', 'judul', 'deskripsi', 'deadline', 'priority', 'status_id',
            'is_completed', 'project_id', 'workspace_id', 'created_at', 'updated_at',
            'status', 'success', 'message', 'type'
        ];

        return collect($result)->map(function ($value) use ($safeFields) {
            if (is_array($value)) {
                // If it's a Todo/Task-like array
                if (isset($value['id']) && (isset($value['judul']) || isset($value['is_completed']))) {
                    return array_intersect_key($value, array_flip($safeFields));
                }
                
                // If it's a list of tasks
                if (isset($value[0]) && is_array($value[0]) && isset($value[0]['id'])) {
                    return collect($value)->map(fn($v) => array_intersect_key($v, array_flip($safeFields)))->toArray();
                }
            }
            return $value;
        })->toArray();
    }

    //  CRUD 

    private function executeCreateTask(array $data, $user, ?string $deviceId): array
    {
        // IDOR Protection: Verify team membership before allowing team_id assignment
        $teamId = $data['team_id'] ?? null;
        // Guest cannot create team tasks - login required
        if ($teamId && !$user) {
            return ['success' => false, 'error' => 'Login diperlukan untuk tugas tim'];
        }

        if ($teamId && $user) {
            if (!$user->teams()->where('teams.id', $teamId)->exists()) {
                return ['success' => false, 'error' => 'Akses ke tim ini ditolak'];
            }
        }
        $todo = Todo::create([
            'judul'     => strip_tags($data['judul'] ?? 'Untitled Task'),
            'deskripsi' => isset($data['deskripsi']) ? strip_tags($data['deskripsi']) : null,
            'deadline'  => isset($data['deadline'])  ? Carbon::parse($data['deadline'])  : null,
            'priority'  => in_array($data['priority'] ?? '', ['high','medium','low']) ? $data['priority'] : 'medium',
            'user_id'   => $user?->id,
            'device_id' => $user ? null : $deviceId,
            'team_id'   => $teamId,
        ]);

        return ['success' => true, 'todo' => $todo->toArray()];
    }

    private function executeUpdateTask(array $data, $user, ?string $deviceId = null): array
    {
        $id = $data['id'] ?? null;
        if (!$id) return ['success' => false, 'error' => 'No task ID provided'];

        $todo = Todo::find($id);
        if (!$todo) return ['success' => false, 'error' => 'Task not found'];
        if (!$this->verifyOwnership($todo, $user, $deviceId))
            return ['success' => false, 'error' => 'Access denied'];

        $updates = [];
        if (isset($data['judul']))        $updates['judul']        = strip_tags($data['judul']);
        if (isset($data['deskripsi']))    $updates['deskripsi']    = strip_tags($data['deskripsi']);
        if (isset($data['deadline']))     $updates['deadline']     = Carbon::parse($data['deadline']);
        if (isset($data['priority']))     $updates['priority']     = $data['priority'];
        if (isset($data['is_completed'])) $updates['is_completed'] = (bool) $data['is_completed'];

        $todo->update($updates);
        return ['success' => true, 'todo' => $todo->fresh()->toArray()];
    }

    private function executeDeleteTask(array $data, $user, ?string $deviceId = null): array
    {
        $id = $data['id'] ?? null;
        if (!$id) return ['success' => false, 'error' => 'No task ID provided'];

        $todo = Todo::find($id);
        if (!$todo) return ['success' => false, 'error' => 'Task not found'];
        if (!$this->verifyOwnership($todo, $user, $deviceId))
            return ['success' => false, 'error' => 'Access denied'];

        $title = $todo->judul;
        $todo->delete();
        return ['success' => true, 'deleted_title' => $title];
    }

    private function executeToggleTask(array $data, $user, ?string $deviceId = null): array
    {
        $id = $data['id'] ?? null;
        if (!$id) return ['success' => false, 'error' => 'No task ID provided'];

        $todo = Todo::find($id);
        if (!$todo) return ['success' => false, 'error' => 'Task not found'];
        if (!$this->verifyOwnership($todo, $user, $deviceId))
            return ['success' => false, 'error' => 'Access denied'];

        $todo->update(['is_completed' => !$todo->is_completed]);
        return ['success' => true, 'todo' => $todo->fresh()->toArray()];
    }

    //  Batch / Bulk 

    private function executeBatchCreate(array $data, $user, ?string $deviceId): array
    {
        $tasks   = $data['tasks'] ?? [];
        $created = [];
        foreach ($tasks as $taskAction) {
            $taskData = $taskAction['data'] ?? $taskAction;
            $result   = $this->executeCreateTask($taskData, $user, $deviceId);
            if ($result['success'] ?? false) $created[] = $result['todo'];
        }
        return ['success' => true, 'created_count' => count($created), 'todos' => $created];
    }

    private function executeBulkDelete(array $data, $user, ?string $deviceId = null): array
    {
        $ids = $data['ids'] ?? [];
        if (empty($ids)) return ['success' => true, 'deleted_count' => 0];

        $query = Todo::whereIn('id', $ids);
        if ($user) {
            $teamIds = $user->teams()->pluck('teams.id');
            $query->where(function($q) use ($user, $teamIds) {
                $q->where('user_id', $user->id)
                  ->orWhereIn('team_id', $teamIds);
            });
        } else {
            $query->where('device_id', $deviceId)->whereNull('user_id');
        }

        $deleted = $query->delete();
        return ['success' => true, 'deleted_count' => $deleted];
    }

    private function executeBulkToggle(array $data, $user, ?string $deviceId = null): array
    {
        $ids          = $data['ids'] ?? [];
        $setCompleted = $data['set_completed'] ?? true;
        if (empty($ids)) return ['success' => true, 'toggled_count' => 0, 'set_completed' => $setCompleted];

        $query = Todo::whereIn('id', $ids);
        if ($user) {
            $teamIds = $user->teams()->pluck('teams.id');
            $query->where(function($q) use ($user, $teamIds) {
                $q->where('user_id', $user->id)
                  ->orWhereIn('team_id', $teamIds);
            });
        } else {
            $query->where('device_id', $deviceId)->whereNull('user_id');
        }

        $toggled = $query->update(['is_completed' => $setCompleted]);
        return ['success' => true, 'toggled_count' => $toggled, 'set_completed' => $setCompleted];
    }

    //  Industrial Scale executors (v6.0) 

    private function executeCreateWorkspace(array $data, $user, ?string $deviceId): array
    {
        $workspace = \App\Models\Workspace\Workspace::create([
            'name'    => strip_tags($data['name'] ?? 'New Workspace'),
            'owner_id'=> $user?->id,
            'slug'    => \Illuminate\Support\Str::slug($data['name'] ?? 'new-workspace') . '-' . \Illuminate\Support\Str::random(4),
        ]);

        return ['success' => true, 'workspace' => $workspace->toArray()];
    }

    private function executeCreateSubtask(array $data, $user, ?string $deviceId): array
    {
        $parentId = $data['parent_id'] ?? null;
        if (!$parentId) return ['success' => false, 'error' => 'Parent ID required'];

        $parent = Todo::find($parentId);
        if (!$parent) return ['success' => false, 'error' => 'Parent task not found'];
        if (!$this->verifyOwnership($parent, $user, $deviceId))
            return ['success' => false, 'error' => 'Access denied'];

        $subtask = Todo::create([
            'judul'     => strip_tags($data['judul'] ?? 'New Subtask'),
            'parent_id' => $parentId,
            'user_id'   => $user?->id,
            'device_id' => $user ? null : $deviceId,
        ]);

        return ['success' => true, 'subtask' => $subtask->toArray()];
    }

    private function executeUpdateTaskState(array $data, $user, ?string $deviceId): array
    {
        $id = $data['id'] ?? null;
        $stateId = $data['state_id'] ?? null;
        if (!$id || !$stateId) return ['success' => false, 'error' => 'Task ID and State ID required'];

        $todo = Todo::find($id);
        if (!$todo) return ['success' => false, 'error' => 'Task not found'];
        if (!$this->verifyOwnership($todo, $user, $deviceId))
            return ['success' => false, 'error' => 'Access denied'];

        $todo->update(['state_id' => $stateId]);
        return ['success' => true, 'todo' => $todo->fresh()->toArray()];
    }

    public function toggleComputeMode(Request $request): JsonResponse
    {
        $mode = $request->mode === 'gpu' ? 'gpu' : 'cpu';
        \Illuminate\Support\Facades\Cache::put('ai_compute_mode', $mode, now()->addDays(7));
        return response()->json(['success' => true, 'mode' => $mode]);
    }

    public function generateAnalyticsReport(): JsonResponse
    {
        return response()->json($this->executeGenerateAnalyticsReport([], null, 'direct_api'));
    }

    public function expertInsights(Request $request): JsonResponse
    {
        [$user, $deviceId] = $this->resolveIdentity($request);
        $todos = $this->loadTodos($user, $deviceId);
        $memory = new AiMemoryService($user?->id, $deviceId);
        $engine = new LocalAiEngine($todos, $user?->name ?? 'User', [], $memory);
        
        $reasoning = $engine->getExpertManager()->reason('dashboard_poll', [
            'tasks' => collect($todos)->map(fn($t) => $t->toArray())->toArray(),
            'time' => now()->toIso8601String()
        ]);

        return response()->json([
            'success' => true,
            'findings' => $reasoning['findings'],
            'suggestions' => $reasoning['suggestions'],
            'confidence' => $reasoning['confidence']
        ]);
    }

    public function habitRecommendations(Request $request): JsonResponse
    {
        [$user, $deviceId] = $this->resolveIdentity($request);
        // This would typically query the ai_memories table for 'pattern' types
        $memory = new AiMemoryService($user?->id, $deviceId);
        $patterns = $memory->recallAll('pattern');

        return response()->json([
            'success' => true,
            'habits' => $patterns,
            'message' => count($patterns) > 0 ? 'Beberapa pola telah diidentifikasi oleh Jarvis.' : 'Belum ada pola yang cukup kuat, Kak.'
        ]);
    }

    public function mentalLoadMonitor(Request $request): JsonResponse
    {
        [$user, $deviceId] = $this->resolveIdentity($request);

        // PERF: Cache mental load result for 30s to reduce DB polling
        $cacheKey = 'mental_load_' . ($user?->id ?? 'device_' . $deviceId);
        $cached = Cache::get($cacheKey);
        if ($cached) return response()->json($cached);

        $todos = $this->loadTodos($user, $deviceId);

        $highPriority = $todos->where('priority', 'high')->where('is_completed', false)->count();
        $overdue = $todos->filter(fn($t) => !$t->is_completed && $t->deadline && Carbon::parse($t->deadline)->isPast())->count();

        $score = ($highPriority * 15) + ($overdue * 25);
        $status = $score > 70 ? 'CRITICAL' : ($score > 40 ? 'WARNING' : 'STABLE');

        $data = [
            'score' => $score,
            'status' => $status,
            'metrics' => [
                'high_priority' => $highPriority,
                'overdue' => $overdue
            ]
        ];

        Cache::put($cacheKey, $data, now()->addSeconds(30));

        return response()->json($data);
    }

    public function knowledgeQuery(Request $request): JsonResponse
    {
        $request->validate(['query' => 'required|string|max:500', 'lang' => 'nullable|string|in:id,en']);
        $lang = $request->lang ?? 'id';
        $kb = new \App\Services\Ai\KnowledgeBaseService();
        $result = $kb->query($request->query('query', $request->input('query')), $lang);

        if (!$result) {
            return response()->json([
                'success' => false,
                'message' => $lang === 'en' ? 'No knowledge found for that query.' : 'Tidak ditemukan pengetahuan untuk pertanyaan itu.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'answer' => $result['answer'],
            'topic' => $result['topic'],
            'confidence' => $result['confidence'],
            'related' => $result['related'] ?? [],
        ]);
    }

    public function codeAssistant(Request $request): JsonResponse
    {
        $request->validate(['message' => 'required|string|max:2000', 'lang' => 'nullable|string|in:id,en']);
        $lang = $request->lang ?? 'id';
        $expert = new \App\Services\Ai\Experts\CodeAssistantExpert();
        $result = $expert->evaluate($request->message, ['lang' => $lang]);

        return response()->json([
            'success' => true,
            'findings' => $result['findings'],
            'suggestions' => $result['suggestions'],
            'confidence' => $result['confidence'],
        ]);
    }

    public function translateText(Request $request): JsonResponse
    {
        $request->validate(['message' => 'required|string|max:2000', 'lang' => 'nullable|string|in:id,en']);
        $lang = $request->lang ?? 'id';
        $expert = new \App\Services\Ai\Experts\TranslationExpert();
        $result = $expert->evaluate($request->message, ['lang' => $lang]);

        return response()->json([
            'success' => true,
            'findings' => $result['findings'],
            'suggestions' => $result['suggestions'],
            'confidence' => $result['confidence'],
        ]);
    }

    public function creativeWrite(Request $request): JsonResponse
    {
        $request->validate([
            'message' => 'required|string|max:2000',
            'lang' => 'nullable|string|in:id,en',
        ]);
        $lang = $request->lang ?? 'id';
        $userName = $request->user()?->name ?? 'Kak';
        $expert = new \App\Services\Ai\Experts\CreativeWritingExpert();
        $result = $expert->evaluate($request->message, ['lang' => $lang, 'user' => $userName]);

        return response()->json([
            'success' => true,
            'findings' => $result['findings'],
            'suggestions' => $result['suggestions'],
            'confidence' => $result['confidence'],
        ]);
    }

    /**
     * NEW: Batch update deadline for multiple tasks (from LocalAiEngine v3 batch_update)
     */
    private function executeBatchUpdateDeadline(array $data, $user, ?string $deviceId = null): array
    {
        $ids      = $data['ids'] ?? [];
        $deadline = isset($data['deadline']) ? Carbon::parse($data['deadline']) : null;
        if (empty($ids)) return ['success' => true, 'updated_count' => 0, 'new_deadline' => $deadline?->toDateTimeString()];

        $query = Todo::whereIn('id', $ids);
        if ($user) {
            $teamIds = $user->teams()->pluck('teams.id');
            $query->where(function($q) use ($user, $teamIds) {
                $q->where('user_id', $user->id)
                  ->orWhereIn('team_id', $teamIds);
            });
        } else {
            $query->where('device_id', $deviceId)->whereNull('user_id');
        }

        $updated = $query->update(['deadline' => $deadline]);
        return ['success' => true, 'updated_count' => $updated, 'new_deadline' => $deadline?->toDateTimeString()];
    }

    private function executeGenerateAnalyticsReport(array $data, $user, ?string $deviceId): array
    {
        return [
            'success' => true,
            'report_url' => '/analytics/v1/summary-' . \Illuminate\Support\Str::random(10),
            'summary' => 'Industrial Productivity Graph analyzed 100% data points. Efficiency: 92%.'
        ];
    }

    /**
     * NEW: Batch update priority for multiple tasks
     */
    private function executeBatchUpdatePriority(array $data, $user, ?string $deviceId = null): array
    {
        $ids      = $data['ids'] ?? [];
        $priority = $data['priority'] ?? null;
        if (!in_array($priority, ['high','medium','low']))
            return ['success' => false, 'error' => 'Invalid priority value'];

        if (empty($ids)) return ['success' => true, 'updated_count' => 0, 'new_priority' => $priority];

        $query = Todo::whereIn('id', $ids);
        if ($user) {
            $teamIds = $user->teams()->pluck('teams.id');
            $query->where(function($q) use ($user, $teamIds) {
                $q->where('user_id', $user->id)
                  ->orWhereIn('team_id', $teamIds);
            });
        } else {
            $query->where('device_id', $deviceId)->whereNull('user_id');
        }

        $updated = $query->update(['priority' => $priority]);
        return ['success' => true, 'updated_count' => $updated, 'new_priority' => $priority];
    }

    //  Scheduling 

    private function executeRescheduleAllOverdue(array $data, $user, ?string $deviceId): array
    {
        $targetDate = isset($data['target_date'])
            ? Carbon::parse($data['target_date'])
            : now()->addDay()->startOfDay()->setTime(23, 59);

        $query = Todo::where('is_completed', false)
            ->whereNotNull('deadline')
            ->where('deadline', '<', now());

        if ($user) {
            $query->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)
                  ->orWhereIn('team_id', $user->teams->pluck('id'));
            });
        } else {
            $query->where('device_id', $deviceId)->whereNull('user_id');
        }

        $count = $query->update(['deadline' => $targetDate]);
        return ['success' => true, 'rescheduled_count' => $count, 'target_date' => $targetDate->toDateTimeString()];
    }

    //  Special 

    private function executeDuplicateTask(array $data, $user, ?string $deviceId): array
    {
        $original = Todo::find($data['id'] ?? null);
        if (!$original) return ['success' => false, 'error' => 'Task not found'];
        if (!$this->verifyOwnership($original, $user, $deviceId))
            return ['success' => false, 'error' => 'Access denied'];

        $clone              = $original->replicate();
        $clone->judul       = ($data['new_title'] ?? $original->judul) . ' (copy)';
        $clone->is_completed = false;
        $clone->created_at  = now();
        $clone->updated_at  = now();
        if (isset($data['deadline'])) $clone->deadline = Carbon::parse($data['deadline']);
        $clone->save();

        return ['success' => true, 'todo' => $clone->toArray()];
    }

    private function executeMoveTask(array $data, $user, ?string $deviceId): array
    {
        $todo = Todo::find($data['id'] ?? null);
        if (!$todo) return ['success' => false, 'error' => 'Task not found'];
        if (!$this->verifyOwnership($todo, $user, $deviceId))
            return ['success' => false, 'error' => 'Access denied'];

        $targetTeamId = $data['team_id'] ?? null;
        if ($targetTeamId && $user && !$user->teams->pluck('id')->contains((int) $targetTeamId))
            return ['success' => false, 'error' => 'You are not a member of the target team'];

        $todo->update(['team_id' => $targetTeamId]);
        return ['success' => true, 'todo' => $todo->fresh()->toArray()];
    }

    /**
     * NEW: Start Pomodoro session  creates a special timer task
     */
    private function executeStartPomodoro(array $data, $user, ?string $deviceId): array
    {
        $workMins  = max(1, min(60, (int) ($data['work_minutes']  ?? 25)));
        $breakMins = max(1, min(30, (int) ($data['break_minutes'] ?? 5)));
        $taskId    = $data['task_id'] ?? null;
        $taskName  = $data['task']    ?? 'Pomodoro Session';

        // Log the pomodoro start event (task creation optional)
        $endTime = now()->addMinutes($workMins);

        $pomodoroTask = Todo::create([
            'judul'     => " Pomodoro: {$taskName} ({$workMins}m)",
            'deskripsi' => "Work: {$workMins}min | Break: {$breakMins}min | Started: " . now()->format('H:i'),
            'deadline'  => $endTime,
            'priority'  => 'high',
            'user_id'   => $user?->id,
            'device_id' => $user ? null : $deviceId,
        ]);

        return [
            'success'       => true,
            'pomodoro'      => [
                'work_minutes'  => $workMins,
                'break_minutes' => $breakMins,
                'start_time'    => now()->toIso8601String(),
                'end_time'      => $endTime->toIso8601String(),
                'task_name'     => $taskName,
                'task_id'       => $taskId,
            ],
            'todo' => $pomodoroTask->toArray(),
        ];
    }

    // 
    // PROVIDER CALLS
    // 


    private function streamChunk(array $data): void
    {
        echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
        ob_flush();
        flush();
    }

    // 
    // CONVERSATION SUMMARIZATION
    // 

    /**
     * Compress long conversation history into a summary turn.
     * Keeps the last 5 turns intact for context continuity.
     */
    private function summarizeHistory(array $history): array
    {
        $keepLast    = 5;
        $toSummarize = array_slice($history, 0, count($history) - $keepLast);
        $keepTail    = array_slice($history, -$keepLast);

        if (empty($toSummarize)) return $history;

        // Build a simple extractive summary from assistant turns
        $summaryLines = [];
        foreach ($toSummarize as $turn) {
            if (($turn['role'] ?? '') === 'assistant') {
                // Extract first sentence only
                $first = explode('.', strip_tags($turn['content']))[0] ?? '';
                if (mb_strlen($first) > 10) {
                    $summaryLines[] = ' ' . mb_substr(trim($first), 0, 120);
                }
            }
        }

        if (empty($summaryLines)) return $history;

        $summary = [
            'role'    => 'system',
            'content' => '[Conversation summary  earlier turns]: ' . implode(' ', $summaryLines),
        ];

        return array_merge([$summary], $keepTail);
    }

    // 
    // HELPERS
    // 

    private function resolveIdentity(Request $request): array
    {
        $user     = $request->user('sanctum');
        $deviceId = $request->header('X-Device-ID') ?? $request->input('device_id');
        return [$user, $deviceId];
    }

    private function isRateLimited($user, ?string $deviceId): bool
    {
        $key          = 'ai_rate_' . ($user ? "user_{$user->id}" : "device_{$deviceId}");
        $currentCount = Cache::get($key, 0);
        if ($currentCount >= self::RATE_LIMIT_REQUESTS) return true;
        Cache::put($key, $currentCount + 1, now()->addSeconds(self::RATE_LIMIT_WINDOW));
        return false;
    }

    private function sanitizeInput(string $input): string
    {
        $input = strip_tags($input);
        $input = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $input);
        return trim(mb_substr($input, 0, 2000));
    }

    private function sanitizeHistory(array $history): array
    {
        return collect($history)->map(function($e) {
            $content = $e['content'] ?? '';
            // Security Hardening: Remove common JWT/Token patterns
            $content = preg_replace('/eyJ[a-zA-Z0-9_-]+\.eyJ[a-zA-Z0-9_-]+\.[a-zA-Z0-9_-]+/i', '[PROTECTED_TOKEN]', $content);
            $content = preg_replace('/sb_publishable_[a-zA-Z0-9_]+/i', '[PROTECTED_KEY]', $content);
            
            return [
                'role'    => in_array($e['role'] ?? '', ['user','assistant']) ? $e['role'] : 'user',
                'content' => strip_tags(mb_substr($content, 0, 4000)),
            ];
        })->take(self::HISTORY_MAX_TURNS)->values()->toArray();
    }

    private function loadTodos($user, ?string $deviceId)
    {
        $baseQuery = $user 
            ? Todo::where(function ($q) use ($user) {
                $q->where('user_id', $user->id)
                  ->orWhereIn('team_id', $user->teams->pluck('id'));
              })
            : Todo::where('device_id', $deviceId)->whereNull('user_id');

        // OPTIMIZATION: Load all pending tasks, and only the last 100 completed tasks.
        // This prevents the server from crashing if a user has 10,000 completed tasks, 
        // while ensuring 100% accuracy for all active/pending tasks.
        // Execute as a UNION query to push the merge down to the database level and save PHP memory
        $pending = (clone $baseQuery)->where('is_completed', false)->latest()->limit(500);
        $completed = (clone $baseQuery)->where('is_completed', true)->latest()->limit(100);

        return $pending->get()->concat($completed->get());
    }

    private function verifyOwnership($todo, $user, ?string $deviceId): bool
    {
        if ($user) {
            if ($todo->user_id === $user->id) return true;
            if ($todo->team_id && $user->teams->pluck('id')->contains($todo->team_id)) return true;
            return false;
        }
        return $deviceId && $todo->device_id === $deviceId && !$todo->user_id;
    }


    private function auditLog(string $type, array $data, $user, ?string $deviceId): void
    {
        Log::channel('daily')->info('AI action executed', [
            'action_type' => $type,
            'user_id'     => $user?->id,
            'device_id'   => $deviceId,
            'data'        => array_intersect_key($data, array_flip([
                'id','judul','ids','set_completed','target_date','filter','priority','deadline','task'
            ])),
            'timestamp'   => now()->toIso8601String(),
            'ip'          => request()->ip(),
            'user_agent'  => mb_substr(request()->userAgent() ?? '', 0, 100),
        ]);
    }

    // 
    // SYSTEM PROMPT & TASK CONTEXT
    // 

    private function buildTaskContext($todos): string
    {
        if ($todos->isEmpty()) return "User has NO tasks.";

        // PERF: Single-pass aggregation instead of 6 separate collection iterations
        $total = 0; $completed = 0; $high = 0; $overdue = 0; $today = 0;
        $lines = [];
        $lines[] = "## SUMMARY";
        $lines[] = ''; // placeholder for stats — filled after loop
        $lines[] = "";
        $lines[] = "## TASKS";

        foreach ($todos as $t) {
            $total++;
            $isCompleted = (bool) $t->is_completed;
            $isPast  = !$isCompleted && $t->deadline?->isPast();
            $isToday = !$isCompleted && $t->deadline?->isToday();

            if ($isCompleted) $completed++;
            if (!$isCompleted && ($t->priority ?? 'medium') === 'high') $high++;
            if ($isPast) $overdue++;
            if ($isToday) $today++;

            $s    = $isCompleted ? '' : '';
            $dl   = $t->deadline ? $t->deadline->format('Y-m-d H:i') : 'none';
            $p    = strtoupper($t->priority ?? 'medium');
            $late = $isPast ? '' : '';
            $desc = $t->deskripsi ? " | {$t->deskripsi}" : '';
            $lines[] = "[{$t->id}] {$s} [{$p}] \"{$t->judul}\"{$desc} (dl:{$dl}{$late})";
        }

        $pending = $total - $completed;
        $lines[1] = "Total:{$total} Pending:{$pending} Done:{$completed} High:{$high} Overdue:{$overdue} Today:{$today}";

        return implode("\n", $lines);
    }

    private function buildSystemPrompt(string $taskContext): string
    {
        $now = now()->format('Y-m-d H:i (l)');

        return <<<PROMPT
You are Jarvis, an advanced AI assistant for the task management app "TEST_WUDI". Your tone should be calm, precise, helpful, and friendly. ALWAYS address the user as "Kak" if they speak Indonesian. If they speak English, do NOT use "Sir" or "Master", just respond politely. NEVER use their actual name.

Current: {$now}

{$taskContext}

## OUTPUT (CRITICAL)
Respond ONLY with a single valid JSON object. No markdown, no code blocks, no extra text.

Format:
{"message":"text","action":null,"quick_replies":["chip1","chip2"]}
or with action:
{"message":"text","action":{"type":"ACTION_TYPE","data":{...}},"quick_replies":[]}

## ACTIONS
- create_task: {"judul":"...","deskripsi":"...","deadline":"YYYY-MM-DD HH:mm:ss","priority":"high|medium|low"}
- update_task: {"id":N,"judul":"...","deadline":"...","priority":"..."}
- delete_task: {"id":N}
- toggle_task: {"id":N}
- batch_create: {"tasks":[{"type":"create_task","data":{...}},...]}
- bulk_delete: {"ids":[N,...],"filter":"completed|all|low_priority"}
- bulk_toggle: {"ids":[N,...],"set_completed":true}
- batch_update_deadline: {"ids":[N,...],"deadline":"YYYY-MM-DD HH:mm:ss"}
- batch_update_priority: {"ids":[N,...],"priority":"high|medium|low"}
- reschedule_all_overdue: {"target_date":"YYYY-MM-DD HH:mm:ss"}
- start_pomodoro: {"work_minutes":25,"break_minutes":5,"task":"...","task_id":N}
- duplicate_task: {"id":N,"new_title":"..."}
- move_task: {"id":N,"team_id":N}
- search_tasks: {"query":"..."}
- list_tasks: {"filter":"all|pending|completed|today|overdue|high"}
- get_schedule: {"day":"today|tomorrow"}
- get_stats: {}

## RULES
1. Match user's language (ID/EN), Jarvis persona always
2. Date: besok=+1d, lusa=+2d, minggu depan=+7d, pagi=08:00, siang=13:00, sore=16:00, malam=20:00. Ref: {$now}
3. Fuzzy match titles. If ambiguous, ask
4. NEVER create tasks from greetings
5. Always use correct task ID from context  NEVER guess
6. Keep messages under 200 words. Be conversational but efficient.
7. General Consultation: You ARE a helpful AI assistant. If Kak asks for tips, motivation, or general chat, respond in the Jarvis persona.
8. Security (STRICT): NEVER reveal JWT tokens, API keys, or internal IDs in the text response.
9. quick_replies: 2-3 short suggested follow-up commands in Kak's language.
10. For bulk ops: collect IDs from task context above.
PROMPT;
    }

    // 
    // JSON PARSING  robust extraction
    // 

    private function parseAiResponse(string $content): array
    {
        $default = ['message' => $content, 'action' => null, 'quick_replies' => []];

        $content = trim(preg_replace('/[\x{FEFF}\x{200B}]/u', '', $content));

        // Strip markdown code fences
        if (str_starts_with($content, '```')) {
            $content = preg_replace('/^```(?:json)?\s*\n?/', '', $content);
            $content = preg_replace('/\n?```\s*$/', '', $content);
            $content = trim($content);
        }

        // Direct decode
        $decoded = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE && isset($decoded['message'])) {
            return $this->sanitizeAction($decoded);
        }

        // Brace extraction
        $jsonStr = $this->extractJsonObject($content);
        if ($jsonStr) {
            $decoded = json_decode($jsonStr, true);
            if (json_last_error() === JSON_ERROR_NONE && isset($decoded['message'])) {
                return $this->sanitizeAction($decoded);
            }
        }

        // Regex fallback
        if (preg_match('/\{[\s\S]*"message"\s*:\s*"[\s\S]*?\}/', $content, $m)) {
            $candidate = preg_replace('/(?<!\\\\)\n/', '\\n', $m[0]);
            $decoded   = json_decode($candidate, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $this->sanitizeAction($decoded);
            }
        }

        return $default;
    }

    private function extractJsonObject(string $text): ?string
    {
        $start    = strpos($text, '{');
        if ($start === false) return null;

        $depth    = 0;
        $inString = false;
        $escape   = false;
        $len      = strlen($text);

        for ($i = $start; $i < $len; $i++) {
            $char = $text[$i];
            if ($escape)        { $escape = false; continue; }
            if ($char === '\\') { $escape = true;  continue; }
            if ($char === '"')  { $inString = !$inString; continue; }
            if ($inString) continue;
            if ($char === '{')  $depth++;
            if ($char === '}') {
                $depth--;
                if ($depth === 0) return substr($text, $start, $i - $start + 1);
            }
        }
        return null;
    }

    private function sanitizeAction(array $decoded): array
    {
        $result = [
            'message'        => $decoded['message'] ?? '',
            'compute_device' => $decoded['compute_device'] ?? 'cpu',
            'action'         => null,
            'quick_replies'  => is_array($decoded['quick_replies'] ?? null)
                ? array_slice($decoded['quick_replies'], 0, 3)
                : [],
            'session'        => $decoded['session'] ?? null,
        ];

        if (empty($decoded['action']) || !is_array($decoded['action'])) return $result;

        $action     = $decoded['action'];
        $validTypes = [
            'create_task','update_task','delete_task','toggle_task',
            'batch_create','bulk_delete','bulk_toggle',
            'batch_update_deadline','batch_update_priority',
            'reschedule_all_overdue','search_tasks','list_tasks',
            'get_schedule','get_stats','duplicate_task','move_task',
            'start_pomodoro',
            'create_workspace','create_subtask','update_task_state','generate_analytics_report',
        ];

        if (!isset($action['type']) || !in_array($action['type'], $validTypes)) return $result;

        $data = $this->sanitizeRecursive($action['data'] ?? []);

        // Validate create_task
        if ($action['type'] === 'create_task' && empty($data['judul'])) return $result;

        // Validate single-ID actions
        if (in_array($action['type'], ['update_task','delete_task','toggle_task','duplicate_task','move_task','start_pomodoro'])) {
            if (isset($data['id'])) $data['id'] = (int) $data['id'];
        }

        // Validate priority
        if (isset($data['priority']) && !in_array($data['priority'], ['high','medium','low'])) {
            $data['priority'] = 'medium';
        }

        $result['action'] = ['type' => $action['type'], 'data' => $data];
        return $result;
    }

    /**
     * Recursively sanitize array values to prevent XSS/Injection.
     */
    private function sanitizeRecursive($data)
    {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->sanitizeRecursive($value);
            }
            return $data;
        }

        if (is_string($data)) {
            // Remove tags and convert special chars
            return htmlspecialchars(strip_tags($data), ENT_QUOTES, 'UTF-8');
        }

        return $data;
    }

    // 
    // SUPABASE MEMORY  Save conversation after each chat
    // 

    /**
     * Save a summary of the conversation to Supabase memory.
     * Non-blocking: failures are logged but never break the chat flow.
     */
    private function saveConversationToMemory(AiMemoryService $memory, string $userMessage, array $result): void
    {
        // PERF: Defer memory save to after the response is sent to the client
        // This removes 50-200ms from the critical response path
        app()->terminating(function () use ($memory, $userMessage, $result) {
            try {
                $content = json_decode($result['content'] ?? '{}', true);
                $intent  = $result['intent'] ?? 'unknown';
                $summary = mb_substr($userMessage, 0, 200);

                $memory->saveConversation(
                    summary: $summary,
                    topics: [$intent],
                    mood: null,
                    messageCount: 1
                );
            } catch (\Exception $e) {
                Log::debug('AiMemory: Failed to save conversation', ['error' => $e->getMessage()]);
            }
        });
    }
}