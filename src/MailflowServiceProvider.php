<?php

namespace Agavesoft\Mailflow;

use Illuminate\Support\ServiceProvider;

class MailflowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/mailflow.php', 'mailflow');

        $this->app->singleton('mailflow', function () {
            return new MailflowClient(
                config('mailflow.api_url'),
                config('mailflow.api_token'),
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/mailflow.php' => config_path('mailflow.php'),
        ], 'mailflow-config');
    }
}
