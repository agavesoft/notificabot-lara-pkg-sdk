<?php

namespace Agavesoft\Smartmailto\Tests;

use Agavesoft\Smartmailto\SmartmailtoServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [SmartmailtoServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('smartmailto.api_url', 'https://smartmailto.test');
        $app['config']->set('smartmailto.api_token', 'mf_live_test');
        // F-011: dedupe de request-id y catalogo del pull en una cache sin infraestructura.
        $app['config']->set('cache.default', 'array');
    }
}
