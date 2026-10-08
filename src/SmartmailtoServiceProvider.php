<?php

namespace Agavesoft\Smartmailto;

use Agavesoft\Smartmailto\Console\ProvisionCommand;
use Agavesoft\Smartmailto\Http\Middleware\VerifySmartmailtoPull;
use Agavesoft\Smartmailto\Http\Middleware\VerifySmartmailtoWebhook;
use Agavesoft\Smartmailto\Pull\PullRoute;
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
        // F-008 (B2): middleware opcional para la ruta del webhook de falla. F-011: el de la ruta del pull.
        $this->callAfterResolving('router', function ($router) {
            $router->aliasMiddleware('smartmailto.webhook', VerifySmartmailtoWebhook::class);
            $router->aliasMiddleware('smartmailto.pull', VerifySmartmailtoPull::class);
        });

        // F-011: la ruta del pull solo existe con pull.enabled y un resolver. Despues de arrancar, para ver
        // los bindings de PullResolver que la app registre en sus providers.
        if (! $this->app->routesAreCached()) {
            $this->app->booted(fn () => PullRoute::register($this->app));
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/smartmailto.php' => $this->app->configPath('smartmailto.php'),
            ], 'smartmailto-config');

            $this->commands([ProvisionCommand::class]);
        }
    }
}
