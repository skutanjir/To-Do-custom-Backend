<?php
// app/Providers/AiServiceProvider.php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\Ai\IntentManager;
use App\Services\Ai\Intents\WorkspaceIntentHandler;
use App\Services\Ai\Intents\HierarchyIntentHandler;
use App\Services\Ai\Intents\StateMachineIntentHandler;
use App\Services\Ai\Intents\PredictiveIntentHandler;

class AiServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(IntentManager::class, function ($app) {
            $manager = new IntentManager();
            
            // Register all specialized v6.0 handlers
            $manager->register(new WorkspaceIntentHandler());
            $manager->register(new HierarchyIntentHandler());
            $manager->register(new StateMachineIntentHandler());
            $manager->register(new PredictiveIntentHandler());
            
            return $manager;
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
