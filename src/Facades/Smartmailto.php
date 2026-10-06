<?php

namespace Agavesoft\Smartmailto\Facades;

use Agavesoft\Smartmailto\Testing\SmartmailtoFake;
use Illuminate\Support\Facades\Facade;

/**
 * @method static array|null identify(\Agavesoft\Smartmailto\Identity $identity, array $attributes = [])
 * @method static array|null track(string $event, \Agavesoft\Smartmailto\Identity $identity, array $properties = [], string $eventId = '', array $secrets = [], ?array $object = null, ?\DateTimeInterface $occurredAt = null)
 * @method static array|null send(string $template, \Agavesoft\Smartmailto\Identity $identity, array $data = [], string $idempotencyKey = '', ?\DateTimeInterface $sendBefore = null)
 * @method static array|null health()
 * @method static array|null contact(\Agavesoft\Smartmailto\Identity $identity)
 * @method static bool forget(\Agavesoft\Smartmailto\Identity $identity)
 * @method static array|null renderedEmail(int $sendId)
 * @method static \Agavesoft\Smartmailto\PendingBatch batch(bool $backfill = false)
 * @method static array|null eventStatus(int $id)
 * @method static bool enabled()
 * @method static bool isConfigured()
 *
 * @see \Agavesoft\Smartmailto\Smartmailto
 */
class Smartmailto extends Facade
{
    /**
     * Reemplaza el SDK por un doble que registra las llamadas sin red (para las pruebas de tu app).
     */
    public static function fake(): SmartmailtoFake
    {
        $app = static::getFacadeApplication();
        $fake = new SmartmailtoFake($app);
        static::swap($fake);
        $app->instance(\Agavesoft\Smartmailto\Smartmailto::class, $fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return 'smartmailto';
    }
}
