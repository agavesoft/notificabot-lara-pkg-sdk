<?php

namespace Agavesoft\Mailflow;

use Illuminate\Support\Facades\Facade;

/**
 * @method static array|null identify(string $userId, array $attrs = [], bool $async = false)
 * @method static array|null track(string $userId, string $event, array $props = [], bool $async = false)
 * @method static array|null send(string $userId, string $templateSlug, array $data = [], bool $async = false)
 * @method static array|null eventStatus(int $eventId)
 *
 * @see \Agavesoft\Mailflow\MailflowClient
 */
class MailflowFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'mailflow';
    }

    public static function isConfigured(): bool
    {
        $url   = config('mailflow.api_url', '');
        $token = config('mailflow.api_token', '');
        return !empty($url) && !empty($token);
    }
}
