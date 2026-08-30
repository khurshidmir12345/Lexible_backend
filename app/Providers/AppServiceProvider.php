<?php

namespace App\Providers;

use App\Services\Dictionary\Contracts\Translator;
use App\Services\Dictionary\Providers\ClaudeTranslator;
use App\Services\Dictionary\Providers\GeminiTranslator;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Which engine translates the dictionary is a config choice, not a
        // code change — Gemini for the bulk, Claude when quality matters more
        // than price.
        $this->app->bind(Translator::class, fn () => match (config('dictionary.translator')) {
            'claude' => $this->app->make(ClaudeTranslator::class),
            default => $this->app->make(GeminiTranslator::class),
        });

        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
