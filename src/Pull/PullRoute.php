<?php

namespace Agavesoft\Smartmailto\Pull;

use Agavesoft\Smartmailto\Contracts\PullResolver;
use Agavesoft\Smartmailto\Http\Controllers\PullController;
use Illuminate\Contracts\Container\Container;
use Illuminate\Routing\Router;

/**
 * F-011: registro de `POST {prefix}/smartmailto/pull`. Solo existe con `smartmailto.pull.enabled` (el
 * interruptor propio del pull, apagado por default) y un resolver (`pull.resolver` o un binding de
 * Contracts\PullResolver); si no, la ruta no existe (404). Fuera del grupo `web` (sin CSRF).
 *
 * La condicion se evalua al arrancar la app: con `route:cache` queda fija hasta regenerar la cache.
 */
final class PullRoute
{
    public const NAME = 'smartmailto.pull';

    /** Registra la ruta si aplica y no existe ya. Devuelve si quedo registrada. */
    public static function register(Container $app): bool
    {
        if (! self::shouldRegister($app)) {
            return false;
        }

        /** @var Router $router */
        $router = $app->make('router');
        $name = (string) config('smartmailto.pull.route.name', self::NAME);
        if ($router->getRoutes()->getByName($name) !== null) {
            return true;
        }

        $throttle = (string) config('smartmailto.pull.route.throttle', '120,1');
        // El throttle va antes de la firma: las peticiones con firma invalida tambien cuentan.
        $middleware = [
            ...($throttle !== '' ? ["throttle:{$throttle}"] : []),
            'smartmailto.pull',
            ...(array) config('smartmailto.pull.route.middleware', []),
        ];

        $router->post(self::uri(), PullController::class)->middleware($middleware)->name($name);
        $router->getRoutes()->refreshNameLookups();

        return true;
    }

    public static function uri(): string
    {
        $prefix = trim((string) config('smartmailto.pull.route.prefix', 'api'), '/');

        return ($prefix !== '' ? $prefix.'/' : '').'smartmailto/pull';
    }

    public static function resolver(Container $app): ?PullResolver
    {
        if ($app->bound(PullResolver::class)) {
            return $app->make(PullResolver::class);
        }

        $class = config('smartmailto.pull.resolver');
        if (! is_string($class) || $class === '' || ! class_exists($class)) {
            return null;
        }

        $resolver = $app->make($class);

        return $resolver instanceof PullResolver ? $resolver : null;
    }

    private static function shouldRegister(Container $app): bool
    {
        if (! config('smartmailto.pull.enabled', false)) {
            return false;
        }

        $class = config('smartmailto.pull.resolver');

        return $app->bound(PullResolver::class) || (is_string($class) && $class !== '' && is_subclass_of($class, PullResolver::class));
    }
}
