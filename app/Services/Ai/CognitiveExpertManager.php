<?php
// app/Services/Ai/CognitiveExpertManager.php

namespace App\Services\Ai;

use Illuminate\Support\Collection;

/**
 * Orchestrates specialized AI "Experts" to build a combined intelligent response.
 * Part of the Jarvis v9.0 Ground-Up Custom Engine.
 */
class CognitiveExpertManager
{
    /** @var Collection */
    private Collection $experts;

    public function __construct()
    {
        $this->experts = collect();
    }

    /**
     * Register a new cognitive expert.
     */
    public function register(ExpertInterface $expert): void
    {
        $this->experts[] = $expert;
    }

    /**
     * Run the multi-pass reasoning loop across all experts.
     */
    public function reason(string $message, array $context): array
    {
        $findings = [];
        $actions = [];
        $suggestions = [];
        $confidence = 0;

        foreach ($this->experts as $expert) {
            $result = $expert->evaluate($message, $context);
            
            if (!empty($result['findings'])) {
                $findings = array_merge($findings, $result['findings']);
            }
            
            if (!empty($result['actions'])) {
                $actions = array_merge($actions, $result['actions']);
            }

            if (!empty($result['suggestions'])) {
                $suggestions = array_merge($suggestions, $result['suggestions']);
            }

            $confidence = max($confidence, $result['confidence'] ?? 0);
        }

        return [
            'findings' => array_unique($findings),
            'actions' => array_unique($actions, SORT_REGULAR),
            'suggestions' => array_unique($suggestions),
            'confidence' => $confidence,
            'reasoning_path' => $this->experts->map(fn($e) => get_class($e))->toArray(),
        ];
    }
}
