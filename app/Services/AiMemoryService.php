<?php
// app/Services/AiMemoryService.php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Models\AiMemory;
use App\Models\AiConversation;

/**
 * ╔══════════════════════════════════════════════════════════════╗
 * ║       A I   M E M O R Y   S E R V I C E                    ║
 * ║                                                              ║
 * ║   Persistent memory layer stored in Supabase via REST API.   ║
 * ║   Types: preference, correction, pattern, fact, context      ║
 * ║                                                              ║
 * ║   Storage: Supabase (REST API)                               ║
 * ║   App data: Local PostgreSQL (todos, teams)                  ║
 * ╚══════════════════════════════════════════════════════════════╝
 */
class AiMemoryService
{
    private ?int $userId;
    private ?string $deviceId;
    private string $baseUrl;
    private string $apiKey;

    public function __construct(?int $userId = null, ?string $deviceId = null)
    {
        $this->userId   = $userId;
        $this->deviceId = $deviceId;
        $this->baseUrl  = rtrim(config('services.supabase.url', ''), '/') . '/rest/v1';
        $this->apiKey   = config('services.supabase.key', '');
    }

    private function useLocal(): bool
    {
        return empty($this->apiKey) || empty(config('services.supabase.url')) || env('AI_MEMORY_LOCAL', false);
    }

    // ═══════════════════════════════════════════════════════════════
    // HTTP HELPERS — Supabase PostgREST
    // ═══════════════════════════════════════════════════════════════

    private function request(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withHeaders([
            'apikey'        => $this->apiKey,
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type'  => 'application/json',
            'Prefer'        => 'return=representation',
        ])->timeout(10);
    }

    private function ownerFilter(): string
    {
        if ($this->userId) {
            return 'user_id=eq.' . $this->userId;
        }
        if ($this->deviceId) {
            return 'device_id=eq.' . $this->deviceId;
        }
        return 'user_id=is.null&device_id=is.null';
    }

    // ═══════════════════════════════════════════════════════════════
    // CORE MEMORY OPERATIONS
    // ═══════════════════════════════════════════════════════════════

    /**
     * Store or update a memory. If key already exists, update value and increment hits.
     */
    public function remember(string $type, string $key, mixed $value, ?string $expiresAt = null): ?array
    {
        if ($this->useLocal()) {
            return $this->rememberLocal($type, $key, $value, $expiresAt);
        }

        try {
            $existing = $this->findMemory($type, $key);
            $result = $this->executeSupabaseRemember($existing, $type, $key, $value, $expiresAt);
            if ($result) return $result;
            
            return $this->rememberLocal($type, $key, $value, $expiresAt);
        } catch (\Exception $e) {
            return $this->rememberLocal($type, $key, $value, $expiresAt);
        }
    }

    private function executeSupabaseRemember(?array $existing, string $type, string $key, mixed $value, ?string $expiresAt = null): ?array
    {
        if ($existing) {
            $resp = $this->request()
                ->patch("{$this->baseUrl}/ai_memories?id=eq.{$existing['id']}", [
                    'value'      => is_array($value) ? $value : ['data' => $value],
                    'hits'       => ($existing['hits'] ?? 1) + 1,
                    'expires_at' => $expiresAt,
                    'updated_at' => now()->toISOString(),
                ]);
            return $resp->successful() ? ($resp->json()[0] ?? $existing) : null;
        }

        $resp = $this->request()
            ->post("{$this->baseUrl}/ai_memories", [
                'user_id'    => $this->userId,
                'device_id'  => $this->userId ? null : $this->deviceId,
                'type'       => $type,
                'key'        => $key,
                'value'      => is_array($value) ? $value : ['data' => $value],
                'hits'       => 1,
                'expires_at' => $expiresAt,
                'created_at' => now()->toISOString(),
                'updated_at' => now()->toISOString(),
            ]);

        return $resp->successful() ? ($resp->json()[0] ?? null) : null;
    }

    private function rememberLocal(string $type, string $key, mixed $value, ?string $expiresAt = null): ?array
    {
        $memory = AiMemory::firstOrNew(
            ['user_id' => $this->userId, 'device_id' => $this->deviceId, 'type' => $type, 'key' => $key]
        );

        $memory->value      = is_array($value) ? $value : ['data' => $value];
        $memory->hits       = ($memory->hits ?? 0) + 1;
        $memory->expires_at = $expiresAt;
        $memory->save();

        return $memory->toArray();
    }

    /**
     * Retrieve a specific memory by type and key.
     */
    public function recall(string $type, string $key): ?array
    {
        if ($this->useLocal()) {
            return $this->recallLocal($type, $key);
        }

        try {
            $memory = $this->findMemory($type, $key);
            if ($memory) {
                // Increment hit count in background
                $this->request()->patch("{$this->baseUrl}/ai_memories?id=eq.{$memory['id']}", [
                    'hits' => ($memory['hits'] ?? 1) + 1,
                ]);
                return $memory['value'] ?? null;
            }
            // Fallback to local DB if not found in Supabase
            return $this->recallLocal($type, $key);
        } catch (\Exception $e) {
            Log::warning('AiMemory: Failed to recall from Supabase, falling back to local', ['error' => $e->getMessage()]);
            return $this->recallLocal($type, $key);
        }
    }

    private function recallLocal(string $type, string $key): ?array
    {
        try {
            $query = AiMemory::where('type', $type)->where('key', $key);

            if ($this->userId) {
                $query->where('user_id', $this->userId);
            } elseif ($this->deviceId) {
                $query->where('device_id', $this->deviceId);
            } else {
                $query->whereNull('user_id')->whereNull('device_id');
            }

            // Filter expired memories
            $query->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });

            $memory = $query->first();
            if ($memory) {
                $memory->increment('hits');
                return $memory->value;
            }
            return null;
        } catch (\Exception $e) {
            Log::warning('AiMemory: Failed to recall locally', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Get all active memories of a given type.
     */
    public function recallAll(string $type, int $limit = 50): array
    {
        if ($this->useLocal()) {
            return AiMemory::where('type', $type)
                ->where(fn($q) => $q->where('user_id', $this->userId)->orWhere('device_id', $this->deviceId))
                ->orderBy('hits', 'desc')
                ->limit($limit)
                ->get()
                ->toArray();
        }

        try {
            $filter = $this->ownerFilter();
            $resp = $this->request()
                ->get("{$this->baseUrl}/ai_memories?{$filter}&type=eq.{$type}&order=hits.desc&limit={$limit}" .
                    '&or=(expires_at.is.null,expires_at.gt.' . now()->toISOString() . ')');

            if ($resp instanceof \Illuminate\Http\Client\Response) {
                if ($resp->successful()) {
                    return collect($resp->json())->map(fn($m) => [
                        'key'   => $m['key'],
                        'value' => $m['value'],
                        'hits'  => $m['hits'],
                    ])->toArray();
                }
            }
            
            return $this->recallAllLocal($type, $limit);
        } catch (\Exception $e) {
            return $this->recallAllLocal($type, $limit);
        }
    }

    private function recallAllLocal(string $type, int $limit): array
    {
        return AiMemory::where('type', $type)
            ->where(fn($q) => $q->where('user_id', $this->userId)->orWhere('device_id', $this->deviceId))
            ->orderBy('hits', 'desc')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    /**
     * Delete a specific memory.
     */
    public function forget(string $type, string $key): bool
    {
        $localResult = $this->forgetLocal($type, $key);

        if ($this->useLocal()) {
            return $localResult;
        }

        try {
            $filter = $this->ownerFilter();
            $resp = $this->request()
                ->delete("{$this->baseUrl}/ai_memories?{$filter}&type=eq.{$type}&key=eq.{$key}");
            $supabaseResult = ($resp instanceof \Illuminate\Http\Client\Response) ? $resp->successful() : false;
            return $supabaseResult || $localResult;
        } catch (\Exception $e) {
            Log::warning('AiMemory: Failed to forget from Supabase', ['error' => $e->getMessage()]);
            return $localResult;
        }
    }

    private function forgetLocal(string $type, string $key): bool
    {
        try {
            $query = AiMemory::where('type', $type)->where('key', $key);
            if ($this->userId) {
                $query->where('user_id', $this->userId);
            } elseif ($this->deviceId) {
                $query->where('device_id', $this->deviceId);
            }
            return $query->delete() > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Forget all memories of a specific type.
     */
    public function forgetAll(string $type): bool
    {
        $localResult = false;
        try {
            $query = AiMemory::where('type', $type);
            if ($this->userId) {
                $query->where('user_id', $this->userId);
            } elseif ($this->deviceId) {
                $query->where('device_id', $this->deviceId);
            }
            $localResult = $query->delete() > 0;
        } catch (\Exception $e) {
            // continue to Supabase
        }

        if ($this->useLocal()) {
            return $localResult;
        }

        try {
            $filter = $this->ownerFilter();
            $resp = $this->request()
                ->delete("{$this->baseUrl}/ai_memories?{$filter}&type=eq.{$type}");
            $supabaseResult = ($resp instanceof \Illuminate\Http\Client\Response) ? $resp->successful() : false;
            return $supabaseResult || $localResult;
        } catch (\Exception $e) {
            Log::warning('AiMemory: Failed to forgetAll from Supabase', ['error' => $e->getMessage()]);
            return $localResult;
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // LEARNING OPERATIONS
    // ═══════════════════════════════════════════════════════════════

    /**
     * Learn from a user correction: "aku bilang X, maksudnya Y".
     */
    public function learnCorrection(string $wrong, string $right): ?array
    {
        return $this->remember('correction', mb_strtolower($wrong), [
            'original'  => $wrong,
            'corrected' => $right,
        ]);
    }

    /**
     * Learn a synonym/alias for an intent keyword.
     */
    public function learnSynonym(string $phrase, string $intentKey): ?array
    {
        return $this->remember('pattern', mb_strtolower($phrase), [
            'phrase' => $phrase,
            'intent' => $intentKey,
        ]);
    }

    /**
     * Get all learned corrections.
     */
    public function getCorrections(): array
    {
        return $this->recallAll('correction');
    }

    /**
     * Get all learned synonyms/patterns.
     */
    public function getLearnedPatterns(): array
    {
        return $this->recallAll('pattern');
    }

    // ═══════════════════════════════════════════════════════════════
    // PERSONALITY / PREFERENCES
    // ═══════════════════════════════════════════════════════════════

    /**
     * Build a personality profile from stored preferences.
     */
    public function getPersonality(): array
    {
        $cacheKey = "ai_personality_{$this->userId}_{$this->deviceId}";
        return Cache::remember($cacheKey, 300, function () {
            $prefs = $this->recallAll('preference');
            $profile = [
                'nickname'       => null,
                'preferred_lang' => 'id',
                'greeting_style' => 'formal',
                'work_hours'     => ['start' => '09:00', 'end' => '17:00'],
                'interests'      => [],
            ];

            foreach ($prefs as $pref) {
                match ($pref['key']) {
                    'nickname'       => $profile['nickname']       = $pref['value']['data'] ?? null,
                    'preferred_lang' => $profile['preferred_lang'] = $pref['value']['data'] ?? 'id',
                    'greeting_style' => $profile['greeting_style'] = $pref['value']['data'] ?? 'formal',
                    'work_hours'     => $profile['work_hours']     = $pref['value'] ?? $profile['work_hours'],
                    'interests'      => $profile['interests']      = $pref['value']['data'] ?? [],
                    default          => null,
                };
            }

            return $profile;
        });
    }

    /**
     * Get nickname (cached).
     */
    public function getNickname(): ?string
    {
        $profile = $this->getPersonality();
        return $profile['nickname'] ?? null;
    }

    /**
     * Set nickname (clears personality cache).
     */
    public function setNickname(string $name): ?array
    {
        Cache::forget("ai_personality_{$this->userId}_{$this->deviceId}");
        return $this->remember('preference', 'nickname', $name);
    }

    /**
     * Set preferred language (clears personality cache).
     */
    public function setPreferredLanguage(string $lang): ?array
    {
        Cache::forget("ai_personality_{$this->userId}_{$this->deviceId}");
        return $this->remember('preference', 'preferred_lang', $lang);
    }

    /**
     * Get preferred language (cached via personality).
     */
    public function getPreferredLanguage(): string
    {
        $profile = $this->getPersonality();
        return $profile['preferred_lang'] ?? 'id';
    }

    // ═══════════════════════════════════════════════════════════════
    // CONVERSATION HISTORY
    // ═══════════════════════════════════════════════════════════════

    /**
     * Save a conversation summary after a chat session.
     */
    public function saveConversation(string $summary, array $topics = [], ?string $mood = null, int $messageCount = 1): ?array
    {
        if ($this->useLocal()) {
            $convo = AiConversation::create([
                'user_id'       => $this->userId,
                'device_id'     => $this->userId ? null : $this->deviceId,
                'summary'       => $summary,
                'topics'        => $topics,
                'mood'          => $mood,
                'message_count' => $messageCount,
            ]);
            return $convo->toArray();
        }

        try {
            $resp = $this->request()
                ->post("{$this->baseUrl}/ai_conversations", [
                    'user_id'       => $this->userId,
                    'device_id'     => $this->userId ? null : $this->deviceId,
                    'summary'       => $summary,
                    'topics'        => $topics,
                    'mood'          => $mood,
                    'message_count' => $messageCount,
                    'created_at'    => now()->toISOString(),
                    'updated_at'    => now()->toISOString(),
                ]);
            return ($resp instanceof \Illuminate\Http\Client\Response && $resp->successful()) ? ($resp->json()[0] ?? null) : null;
        } catch (\Exception $e) {
            Log::warning('AiMemory: Failed to save conversation', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Get recent conversations for context.
     */
    public function getRecentConversations(int $limit = 5): array
    {
        if ($this->useLocal()) {
            return AiConversation::where(fn($q) => $q->where('user_id', $this->userId)->orWhere('device_id', $this->deviceId))
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get()
                ->map(fn($c) => [
                    'summary'       => $c->summary,
                    'topics'        => $c->topics ?? [],
                    'mood'          => $c->mood,
                    'message_count' => $c->message_count,
                    'date'          => $c->created_at->toISOString(),
                ])->toArray();
        }

        try {
            $filter = $this->ownerFilter();
            $resp = $this->request()
                ->get("{$this->baseUrl}/ai_conversations?{$filter}&order=created_at.desc&limit={$limit}");

            if (!$resp instanceof \Illuminate\Http\Client\Response || !$resp->successful()) return [];

            return collect($resp->json())->map(fn($c) => [
                'summary'       => $c['summary'],
                'topics'        => $c['topics'] ?? [],
                'mood'          => $c['mood'],
                'message_count' => $c['message_count'],
                'date'          => $c['created_at'] ?? null,
            ])->toArray();
        } catch (\Exception $e) {
            Log::warning('AiMemory: Failed to get conversations', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Get last conversation summary.
     */
    public function getLastConversation(): ?array
    {
        $convos = $this->getRecentConversations(1);
        return $convos[0] ?? null;
    }

    // ═══════════════════════════════════════════════════════════════
    // FACTS — Things AI learns about the user
    // ═══════════════════════════════════════════════════════════════

    public function rememberFact(string $key, mixed $value): ?array
    {
        return $this->remember('fact', $key, $value);
    }

    public function recallFact(string $key): ?string
    {
        $val = $this->recall('fact', $key);
        return $val['data'] ?? null;
    }

    public function getAllFacts(): array
    {
        return $this->recallAll('fact');
    }

    // ═══════════════════════════════════════════════════════════════
    // MEMORY STATS
    // ═══════════════════════════════════════════════════════════════

    public function getStats(): array
    {
        try {
            $all = $this->request()
                ->get("{$this->baseUrl}/ai_memories?{$this->ownerFilter()}&select=type");
            if (!$all->successful()) return ['total' => 0];

            $types = collect($all->json())->groupBy('type');
            return [
                'total'       => $all->json() ? count($all->json()) : 0,
                'preferences' => $types->get('preference', collect())->count(),
                'corrections' => $types->get('correction', collect())->count(),
                'patterns'    => $types->get('pattern', collect())->count(),
                'facts'       => $types->get('fact', collect())->count(),
                'contexts'    => $types->get('context', collect())->count(),
            ];
        } catch (\Exception $e) {
            return ['total' => 0];
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // PRIVATE HELPERS
    // ═══════════════════════════════════════════════════════════════

    private function findMemory(string $type, string $key): ?array
    {
        if ($this->useLocal()) {
            return $this->findMemoryLocal($type, $key);
        }

        try {
            $filter = $this->ownerFilter();
            $resp = $this->request()
                ->get("{$this->baseUrl}/ai_memories?{$filter}&type=eq.{$type}&key=eq.{$key}&limit=1" .
                    '&or=(expires_at.is.null,expires_at.gt.' . now()->toISOString() . ')');
            
            /** @var \Illuminate\Http\Client\Response $resp */
            if ($resp->successful() && !empty($resp->json())) {
                return $resp->json()[0];
            }
            // Supabase returned empty — try local
            return $this->findMemoryLocal($type, $key);
        } catch (\Exception $e) {
            return $this->findMemoryLocal($type, $key);
        }
    }

    private function findMemoryLocal(string $type, string $key): ?array
    {
        try {
            $query = AiMemory::where('type', $type)->where('key', $key);

            if ($this->userId) {
                $query->where('user_id', $this->userId);
            } elseif ($this->deviceId) {
                $query->where('device_id', $this->deviceId);
            } else {
                $query->whereNull('user_id')->whereNull('device_id');
            }

            $query->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });

            $memory = $query->first();
            return $memory ? $memory->toArray() : null;
        } catch (\Exception $e) {
            return null;
        }
    }
}
