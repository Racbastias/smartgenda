<?php

namespace App\Providers;

use Anthropic\Client;
use App\Inbox\ClaudeSummarizer;
use App\Inbox\EmailSummarizer;
use App\Inbox\RuleSummarizer;
use Illuminate\Support\Facades\View;
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
        // Con clave de Anthropic, resúmenes con IA; sin ella, reglas simples (sin costo).
        $this->app->bind(EmailSummarizer::class, fn ($app) => config('services.anthropic.key')
            ? $app->make(ClaudeSummarizer::class)
            : $app->make(RuleSummarizer::class));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Agendas donde el usuario puede crear eventos (modal global del layout)
        View::composer('components.app-layout', function ($view) {
            $view->with('eventAccounts', auth()->user()?->calendarAccounts()
                ->whereNotNull('calendar_ref')->orderBy('name')->get() ?? collect());
        });
    }
}
