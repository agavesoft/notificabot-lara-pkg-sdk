<?php

namespace Agavesoft\Smartmailto\Pull;

use Agavesoft\Smartmailto\Smartmailto;
use Illuminate\Contracts\Container\Container;

/**
 * F-011: llaves de atributos de contacto que el pull puede mandar (catalogo de F-009, datos minimos).
 *
 *  - `smartmailto.pull.catalog` con una lista: se usa tal cual, sin red.
 *  - Sin lista (default): `Smartmailto::variables('contact')`, guardado en cache `catalog_ttl` segundos.
 *    Si no se puede leer, null: la ruta responde 503 (Smartmailto reintenta) y nunca manda atributos
 *    sin filtrar.
 */
final class PullCatalog
{
    public const CACHE_KEY = 'smartmailto:pull:catalog';

    public function __construct(private readonly Container $app) {}

    /** @return list<string>|null */
    public function contactKeys(): ?array
    {
        $static = $this->config('catalog');
        if (is_array($static)) {
            return array_values(array_filter($static, 'is_string'));
        }

        $cache = $this->app->make('cache')->store($this->config('cache_store'));
        $cached = $cache->get(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $variables = $this->app->make(Smartmailto::class)->variables('contact');
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        if ($variables === null) {
            return null;
        }

        $keys = array_values(array_unique(array_filter(array_map(fn ($variable) => is_array($variable) ? ($variable['key'] ?? null) : null, $variables), 'is_string')));
        $cache->put(self::CACHE_KEY, $keys, max(1, (int) $this->config('catalog_ttl', 300)));

        return $keys;
    }

    private function config(string $key, mixed $default = null): mixed
    {
        return $this->app->make('config')->get("smartmailto.pull.{$key}", $default);
    }
}
