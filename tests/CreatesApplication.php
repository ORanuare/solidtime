<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

trait CreatesApplication
{
    /**
     * Creates the application.
     */
    public function createApplication(): Application
    {
        $this->clearCachedConfigInTestingIfPresent();

        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        if ($app->bound('config') && $this->isRunningPhpunitWithTestingEnv()) {
            $app->make('config')->set('database.default', 'pgsql_test');
        }

        return $app;
    }

    /**
     * If config was built with "php artisan config:cache" while the default DB
     * connection was the app (pgsql + solidtime), then tests would run
     * migrate:fresh on that same connection and wipe the dev database. Cached
     * config also ignores PHPUNIT / env overrides for the default connection.
     */
    private function clearCachedConfigInTestingIfPresent(): void
    {
        if (! $this->isRunningPhpunitWithTestingEnv()) {
            return;
        }
        $path = dirname(__DIR__).'/bootstrap/cache/config.php';
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function isRunningPhpunitWithTestingEnv(): bool
    {
        return (($_ENV['APP_ENV'] ?? null) === 'testing')
            || (getenv('APP_ENV') === 'testing');
    }
}
