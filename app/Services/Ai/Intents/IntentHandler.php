<?php
// app/Services/Ai/Intents/IntentHandler.php

namespace App\Services\Ai\Intents;

interface IntentHandler
{
    /**
     * Scores the user message for this specific intent.
     * Returns a score between 0 and 100.
     */
    public function score(string $msg, array $context): int;

    /**
     * Handles the intent and returns the AI response payload.
     */
    public function handle(string $msg, array $context): array;
}
