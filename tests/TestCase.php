<?php

namespace DiscoveryUkraine\SagaLaraFlow\Tests;

use DiscoveryUkraine\SagaLaraFlow\SagaLaraFlowServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            SagaLaraFlowServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        // Every shipped migration, in filename order — the package now ships more
        // than one, and a suite that only ran the first would be missing columns.
        foreach ($this->packageMigrations() as $path) {
            (include $path)->up();
        }
    }

    /**
     * @return list<string>
     */
    protected function packageMigrations(): array
    {
        $paths = glob(__DIR__.'/../database/migrations/*.php') ?: [];

        sort($paths);

        return array_values($paths);
    }
}
