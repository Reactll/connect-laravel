<?php

namespace Reactll\Connect\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Reactll\Connect\ConnectServiceProvider;

abstract class TestCase extends Orchestra
{
    protected const SECRET = 'secret-123';

    protected const KEY = 'rc_aaaaaaaaaaaaaaaaaaaa';

    protected function getPackageProviders($app): array
    {
        return [ConnectServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('reactll-connect.token', self::token());
        $app['config']->set('app.url', 'https://shop.example.test');
        $app['config']->set('app.timezone', 'UTC');
        $app['config']->set('cache.default', 'array');
    }

    protected static function token(): string
    {
        return 'rcc_'.rtrim(strtr(base64_encode(json_encode(['u' => 'https://connect.example.test/v1/', 'k' => self::KEY, 's' => self::SECRET])), '+/', '-_'), '=');
    }
}
