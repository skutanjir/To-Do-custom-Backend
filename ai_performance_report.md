# AI Endpoint Performance Profiling Report

**Project**: Nexuze (PDBL) Backend  
**Date**: 2026-03-24  
**Scope**: All AI endpoints in `AiChatController`, `AiHistoryController`, and supporting services  
**Method**: Static code analysis — query counting, object instantiation audit, HTTP call tracing

---

## Executive Summary

The AI subsystem has **3 critical performance bottlenecks** and **4 moderate issues**. The most severe is the GPU health check HTTP call (`checkGpuAvailability()`) executing on **every single AI request** with a 3-second timeout. Combined with 19 expert objects being instantiated per request and unbounded message loading in history, these issues can cause **200-3000ms+ added latency per request**.

| Severity | Count | Estimated Impact |
|----------|-------|-----------------|
| 🔴 Critical | 3 | 200-3000ms latency per request |
| 🟡 Moderate | 4 | 50-200ms latency or memory waste |
| 🟢 Low | 2 | Minor inefficiency |

---

## Endpoint Inventory

| # | Method | Endpoint | Controller Method | DB Queries | HTTP Calls | Objects Created |
|---|--------|----------|-------------------|------------|------------|-----------------|
| 1 | POST | `/api/ai/chat` | `chat()` | 8-10 | 1-2 | 19 experts + engine |
| 2 | POST | `/api/ai/stream` | `stream()` | 8-10 | 1-2 | 19 experts + engine |
| 3 | POST | `/api/ai/tts` | `tts()` | 0-1 | 0 | — |
| 4 | GET/POST | `/api/ai/voice-preference` | `voicePreference()` | 0 | 0 | — (cache only) |
| 5 | GET | `/api/ai/experts/insights` | `expertInsights()` | 3-5 | 1-2 | 19 experts + engine |
| 6 | GET | `/api/ai/habits` | `habitRecommendations()` | 1-3 | 0-1 | — |
| 7 | GET | `/api/ai/mental-load` | `mentalLoadMonitor()` | 3-5 | 0 | — |
| 8 | POST | `/api/ai/knowledge` | `knowledgeQuery()` | 0-1 | 0 | KnowledgeBaseService |
| 9 | POST | `/api/ai/code-assistant` | `codeAssistant()` | 0 | 0 | CodeAssistantExpert |
| 10 | POST | `/api/ai/translate` | `translateText()` | 0 | 0 | TranslationExpert |
| 11 | POST | `/api/ai/creative` | `creativeWrite()` | 0 | 0 | CreativeWritingExpert |
| 12 | GET | `/api/ai/history` | `AiHistoryController@index` | 2 | 0 | — |
| 13 | GET | `/api/ai/history/{id}` | `AiHistoryController@show` | 2 | 0 | — |
| 14 | POST | `/api/ai/compute-mode` | `toggleComputeMode()` | 0 | 0 | — (cache only) |

---

## 🔴 Critical Issues

### CRIT-1: GPU Health Check on Every Request (200-3000ms)

**File**: `app/Services/LocalAiEngine.php` line 111-134  
**Impact**: Every request that creates a `LocalAiEngine` (chat, stream, expertInsights) makes an HTTP GET to `http://127.0.0.1:8080/status` with a **3-second timeout**.

```php
// Called in constructor — EVERY request
private function checkGpuAvailability(): void
{
    $response = Http::timeout(3)->get("{$this->gpuEndpoint}/status");
    // ...
}
```

**Affected endpoints**: `/api/ai/chat`, `/api/ai/stream`, `/api/ai/experts/insights`  
**Worst case**: If GPU sidecar is down, adds **3000ms** to every AI request (timeout wait).  
**Best case**: Even when sidecar is up, adds **50-200ms** for the HTTP roundtrip.

**Fix**: Cache the GPU status for 30-60 seconds:
```php
private function checkGpuAvailability(): void
{
    $cached = Cache::get('gpu_status');
    if ($cached !== null) {
        $this->computeDevice = $cached;
        return;
    }
    try {
        $response = Http::timeout(2)->get("{$this->gpuEndpoint}/status");
        $device = ($response->json()['device'] ?? '') === 'cuda' ? 'gpu' : 'cpu';
        Cache::put('gpu_status', $device, now()->addSeconds(30));
        $this->computeDevice = $device;
    } catch (\Exception $e) {
        Cache::put('gpu_status', 'cpu', now()->addSeconds(30));
        $this->computeDevice = 'cpu';
    }
}
```

---

### CRIT-2: 19 Expert Objects Instantiated Per Request

**File**: `app/Services/LocalAiEngine.php` lines 65-84  
**Impact**: The constructor creates **19 `new Expert()` objects** on every single AI request, regardless of which expert is actually needed.

```php
public function __construct(...)
{
    $this->expertManager->register(new DeadlineExpert());
    $this->expertManager->register(new PriorityExpert());
    $this->expertManager->register(new HabitExpert());
    // ... 16 more experts
}
```

**Memory**: ~19 object allocations + constructor logic per request.  
**CPU**: Each expert may have initialization logic in its constructor.

**Fix**: Use Laravel's service container for singleton registration, or lazy-load experts:
```php
// Option A: Register experts as singletons in a ServiceProvider
// Option B: Lazy proxy pattern — only instantiate when expert is actually invoked
$this->expertManager->registerLazy('deadline', fn() => new DeadlineExpert());
```

---

### CRIT-3: Unbounded Message Loading in AiHistoryController@show

**File**: `app/Http/Controllers/Ai/AiHistoryController.php` line 45  
**Impact**: Loads ALL messages for a chat session without any limit or pagination.

```php
'messages' => $aiChat->messages()->orderBy('created_at', 'asc')->get()
```

A long conversation (100+ messages) will load all of them into memory at once. With `data` JSON column containing action results, each message can be 1-5KB.

**Fix**: Add pagination or a reasonable limit:
```php
'messages' => $aiChat->messages()->orderBy('created_at', 'asc')->paginate(50)
```

---

## 🟡 Moderate Issues

### MOD-1: `buildTaskContext()` Iterates Collection 6+ Times

**File**: `app/Http/Controllers/Ai/AiChatController.php` lines 1268-1295  
**Impact**: After `loadTodos()` returns up to 600 tasks, `buildTaskContext()` iterates the collection **6 times** for stats (count, where, filter) plus a full `foreach` loop to build the text representation.

```php
$total     = $todos->count();           // iteration 1
$completed = $todos->where(...);         // iteration 2
$high      = $todos->where(...)->where(...); // iteration 3
$overdue   = $todos->where(...)->filter(...); // iteration 4
$today     = $todos->where(...)->filter(...); // iteration 5
foreach ($todos as $t) { ... }           // iteration 6
```

**Fix**: Single-pass aggregation:
```php
$stats = ['total' => 0, 'completed' => 0, 'high' => 0, 'overdue' => 0, 'today' => 0];
$lines = ["## TASKS"];
foreach ($todos as $t) {
    $stats['total']++;
    if ($t->is_completed) $stats['completed']++;
    if (!$t->is_completed && $t->priority === 'high') $stats['high']++;
    if (!$t->is_completed && $t->deadline?->isPast()) $stats['overdue']++;
    if (!$t->is_completed && $t->deadline?->isToday()) $stats['today']++;
    // ... build line
}
```

---

### MOD-2: `verifyOwnership()` May Trigger Lazy Loading

**File**: `app/Http/Controllers/Ai/AiChatController.php` lines 1238-1246  
**Impact**: `$user->teams->pluck('id')` triggers lazy loading of the `teams` relationship if not already loaded. Called in every single-task action (update, delete, toggle, duplicate, move).

For bulk operations, this is fine (teams loaded once). But for sequential single-task ops in a batch_create loop, the relationship is already loaded. The concern is in the `chat()` flow where `loadTodos()` already accesses `$user->teams->pluck('id')` (line 1224) which loads the relationship. Subsequent calls reuse the loaded data.

**Verdict**: Low risk due to Eloquent caching, but worth adding `$user->loadMissing('teams')` at the start of `chat()` for explicit eager loading.

---

### MOD-3: `mentalLoadMonitor()` Re-calls `loadTodos()` Without Caching

**File**: `app/Http/Controllers/Ai/AiChatController.php` lines 878-897  
**Impact**: Calls `loadTodos()` (2 DB queries via UNION) then performs collection filtering. This is fine for a standalone call, but if a user's frontend polls this endpoint frequently, it hits the DB every time.

**Fix**: Cache the result per user for 30-60 seconds:
```php
$cacheKey = 'mental_load_' . ($user?->id ?? $deviceId);
return Cache::remember($cacheKey, 30, fn() => $this->loadTodos($user, $deviceId));
```

---

### MOD-4: Duplicate Chat Persistence Logic in `stream()`

**File**: `app/Http/Controllers/Ai/AiChatController.php` lines 242-281  
**Impact**: The `stream()` method duplicates the entire chat persistence logic from `chat()` (AiChat lookup/create, 2x AiChatMessage::create). This is a maintainability issue that could lead to divergent behavior, and adds 3 DB queries inside the streaming closure.

**Fix**: Extract shared chat persistence into a private method:
```php
private function persistChat(string $userMessage, string $aiContent, ?array $actionData, $deviceId, ?int $chatId): AiChat
```

---

## 🟢 Low Issues

### LOW-1: `AiHistoryController@index` Uses `take(50)` Instead of `paginate()`

**File**: `app/Http/Controllers/Ai/AiHistoryController.php` line 33  
**Impact**: Returns max 50 chats which is reasonable, but doesn't support pagination for users with 100+ chat sessions. Uses `withCount('messages')` which adds a subquery per row.

**Fix**: Replace `->take(50)->get()` with `->paginate(20)`.

---

### LOW-2: `saveConversationToMemory()` HTTP Call in Request Path

**File**: `app/Http/Controllers/Ai/AiChatController.php` lines 1492-1508  
**Impact**: Makes an HTTP call to Supabase (or local DB insert) synchronously after every chat response. Wrapped in try/catch so it won't break the response, but adds 50-200ms latency.

**Fix**: Queue this operation for background processing:
```php
dispatch(fn() => $this->saveConversationToMemory(...))->afterResponse();
```

---

## Query Count Breakdown — `/api/ai/chat` (Critical Path)

| Step | Query/Call | Count |
|------|-----------|-------|
| `resolveIdentity()` | `$request->user('sanctum')` | 1 DB |
| `isRateLimited()` | `Cache::get/put` | 0 DB (cache) |
| `loadTodos()` | UNION query (pending + completed) | 1 DB |
| `loadTodos()` → `$user->teams->pluck('id')` | Teams relationship | 1 DB (if not loaded) |
| `AiMemoryService->getPersonality()` | Supabase HTTP or local DB | 1 HTTP/DB |
| `checkGpuAvailability()` | HTTP GET to GPU sidecar | 1 HTTP |
| `LocalAiEngine->handle()` | CPU processing (no DB) | 0 DB |
| `AiChat::where()->first()` | Chat session lookup | 1 DB |
| `AiChat::create()` or `->touch()` | Chat session persist | 1 DB |
| `AiChatMessage::create()` x2 | User + assistant messages | 2 DB |
| `executeAction()` (if action) | Varies by action type | 1-3 DB |
| `saveConversationToMemory()` | Supabase HTTP or local DB | 1 HTTP/DB |
| **TOTAL** | | **7-10 DB + 2 HTTP** |

**Estimated latency breakdown** (no action, GPU sidecar up):
- DB queries: ~7 × 5ms = **35ms**
- GPU health check HTTP: **50-200ms** ← biggest bottleneck
- Supabase memory HTTP: **50-150ms**
- LocalAiEngine processing: **10-50ms** (CPU, no ML inference)
- PHP object creation (19 experts): **5-15ms**
- **Total: ~150-450ms** (best case) to **3200ms+** (GPU sidecar down)

---

## Recommended Fix Priority

| Priority | Issue | Effort | Impact |
|----------|-------|--------|--------|
| 1 | CRIT-1: Cache GPU health check | 15 min | Saves 50-3000ms/req |
| 2 | CRIT-2: Lazy-load or singleton experts | 30 min | Saves 5-15ms/req + memory |
| 3 | CRIT-3: Paginate history messages | 5 min | Prevents OOM on long chats |
| 4 | MOD-1: Single-pass task context | 20 min | Saves 10-30ms with 500+ tasks |
| 5 | MOD-3: Cache mental load result | 10 min | Reduces DB load from polling |
| 6 | LOW-2: Queue memory save | 10 min | Saves 50-200ms/req |
| 7 | MOD-4: Extract chat persistence | 15 min | Maintainability |
| 8 | LOW-1: Paginate history index | 5 min | Scalability |

**Total estimated effort**: ~2 hours for all fixes.  
**Expected improvement**: `/api/ai/chat` response time from **150-450ms → 50-100ms** (GPU up) or **3200ms+ → 50-100ms** (GPU down, with cache).

---

## Conclusion

The AI subsystem's core logic is sound. The main performance issue is **external I/O in the hot path** — specifically the GPU health check HTTP call and Supabase memory save. Caching the GPU status alone (CRIT-1) would eliminate the single largest source of latency. Combined with expert lazy-loading (CRIT-2), the `/api/ai/chat` endpoint can realistically achieve **sub-100ms** response times for the PHP layer (excluding any actual GPU inference if enabled).
