<?php
// app/Services/Ai/ExpertInterface.php

namespace App\Services\Ai;

/**
 * Interface for specialized Cognitive Experts in the Jarvis v9.0 ground-up engine.
 */
interface ExpertInterface
{
    /**
     * Evaluate the user message and context to provide findings, actions, and suggestions.
     * 
     * @param string $message The user input.
     * @param array $context Current session/user context.
     * @return array {
     *   findings: string[],
     *   actions: array[],
     *   suggestions: string[],
     *   confidence: int (0-100)
     * }
     */
    public function evaluate(string $message, array $context): array;
}
