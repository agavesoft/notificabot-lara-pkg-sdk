<?php

namespace Agavesoft\Smartmailto;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;

class SmartmailtoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/smartmailto.php', 'smartmailto');

        // El cliente se construye solo cuando se usa: con SMARTMAILTO_ENABLED=false nunca se crea y
        // credenciales vacias no rompen el arranque de la app.
        $this->app->bind(SmartmailtoClient::class, fn ($app) => new SmartmailtoClient(
            $app->make(HttpFactory::class),
            $app['config']->get('smartmailto.api_url'),
            $app['config']->get('smartmailto.api_token'),
            (int) $app['config']->get('smartmailto.timeout', 10),
        ));

        $this->app->singleton(Smartmailto::class, fn ($app) => new Smartmailto($app));
        $this->app->alias(Smartmailto::class, 'smartmailto');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/smartmailto.php' => $this->app->configPath('smartmailto.php'),
            ], 'smartmailto-config');
        }
    }
}
