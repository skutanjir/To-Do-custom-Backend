<?php
// app/Services/Ai/IntentManager.php

namespace App\Services\Ai;

use App\Services\Ai\Intents\IntentHandler;
use Illuminate\Support\Collection;

class IntentManager
{
    /** @var Collection */
    protected Collection $handlers;

    public function __construct(array $handlers = [])
    {
        $this->handlers = new Collection();
        foreach ($handlers as $h) {
            $this->handlers->push($h);
        }
    }

    /**
     * Registers a new intent handler.
     */
    public function register(IntentHandler $handler): void
    {
        $this->handlers[] = $handler;
    }

    /**
     * Determines the best intent handler for the given message.
     */
    public function resolve(string $msg, array $context): ?IntentHandler
    {
        return $this->handlers
            ->sortByDesc(fn($handler) => $handler->score($msg, $context))
            ->first(fn($handler) => $handler->score($msg, $context) > 0);
    }

    /**
     * Executes the best intent handler and returns the response.
     */
    public function handle(string $msg, array $context): array
    {
        $handler = $this->resolve($msg, $context);

        if (!$handler) {
            return [
                'intent' => 'unknown',
                'content' => json_encode([
                    'message' => "I'm not sure how to handle that complex request yet. Jarvis is still learning your enterprise ecosystem.",
                    'action' => null
                ])
            ];
        }

        return $handler->handle($msg, $context);
    }
}
