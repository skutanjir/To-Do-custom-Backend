<?php
// app/Services/Ai/Memory/HabitPatternStore.php

namespace App\Services\Ai\Memory;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class HabitPatternStore
{
    private ?int $userId;
    private ?string $deviceId;

    public function __construct(?int $userId, ?string $deviceId)
    {
        $this->userId = $userId;
        $this->deviceId = $deviceId;
    }

    /**
     * Record an action to build frequency analysis data.
     */
    public function recordAction(string $taskTitle): void
    {
        $hash = md5(strtolower(trim($taskTitle)));
        $key = "habit_freq_" . ($this->userId ?? $this->deviceId) . "_" . $hash;

        $count = Cache::increment($key);
        
        // If frequency hits a threshold, promote to persistent pattern
        if ($count >= 3) {
            $this->persistPattern($taskTitle, $count);
        }
    }

    /**
     * Persist pattern to Local Postgres and Supabase.
     */
    private function persistPattern(string $title, int $frequency): void
    {
        DB::table('ai_memory')->updateOrInsert(
            [
                'user_id' => $this->userId,
                'device_id' => $this->deviceId,
                'key' => 'habit_pattern_' . md5($title)
            ],
            [
                'value' => json_encode([
                    'title' => $title,
                    'frequency' => $frequency,
                    'last_seen' => now()->toIso8601String(),
                    'is_habit' => true
                ]),
                'updated_at' => now()
            ]
        );
    }

    /**
     * Get predicted habits based on frequency.
     */
    public function getPredictedHabits(): array
    {
        return DB::table('ai_memory')
            ->where('user_id', $this->userId)
            ->where('key', 'like', 'habit_pattern_%')
            ->get()
            ->map(fn($item) => json_decode($item->value, true))
            ->toArray();
    }
}
