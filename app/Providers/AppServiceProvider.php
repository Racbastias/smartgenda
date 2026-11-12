<?php

namespace App\Providers;

use Anthropic\Client;
use App\Inbox\ClaudeSummarizer;
use App\Inbox\EmailSummarizer;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Con config:cache el .env no se carga como variable de entorno: la clave se pasa explícita.
        $this->app->bind(Client::class, fn () => new Client(apiKey: (string) config('services.anthropic.key')));
        $this->app->bind(EmailSummarizer::class, ClaudeSummarizer::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
