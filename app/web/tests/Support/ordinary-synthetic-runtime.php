<?php

namespace Tests\Support;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use RuntimeException;

/** Prepare filesystem-only synthetic fixtures; PHPUnit owns key/database settings. */
function ordinarySyntheticRuntime(Application $app): void
{
    $environments = [$_ENV['APP_ENV'] ?? null, $_SERVER['APP_ENV'] ?? null, getenv('APP_ENV')];
    $explicit = array_filter($environments, static fn ($value) => $value !== null && $value !== false);
    if ($explicit === [] || array_filter($explicit, static fn ($value) => $value !== 'testing') !== []) {
        throw new RuntimeException('Ordinary synthetic bootstrap requires explicit APP_ENV=testing.');
    }
    if ($app->configurationIsCached()) {
        throw new RuntimeException('Ordinary synthetic bootstrap refuses cached application configuration.');
    }

    $normalize = static function (string $path): string {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    };
    $web = dirname(__DIR__, 2);
    if (realpath($web) === false || $normalize(realpath($web)) !== $normalize($web)
        || $normalize($app->basePath()) !== $normalize($web)) {
        throw new RuntimeException('Ordinary synthetic bootstrap refuses an aliased application root.');
    }
    $directory = static function (string $path) use ($normalize): string {
        if (! is_dir($path) && ! mkdir($path, 0700)) {
            throw new RuntimeException('Cannot create ordinary synthetic runtime directory.');
        }
        $resolved = realpath($path);
        if ($resolved === false || is_link($path) || $normalize($resolved) !== $normalize($path)) {
            throw new RuntimeException('Ordinary synthetic runtime directory escapes its owned path.');
        }
        return $path;
    };
    $parent = $web;
    foreach (['storage', 'framework', 'testing'] as $segment) {
        $parent = $directory($parent.'/'.$segment);
    }
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    $uuid = substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
    $root = $parent.'/'.$uuid;
    // Exclusive creation: never reuse a fixture root, even on a collision.
    if (! mkdir($root, 0700)) {
        throw new RuntimeException('Cannot exclusively create ordinary synthetic runtime root.');
    }
    $directory($root);
    foreach (['environment', 'app', 'app/private', 'app/public', 'framework', 'framework/views',
        'framework/cache', 'framework/cache/data', 'framework/sessions', 'logs'] as $relative) {
        $directory($root.'/'.$relative);
    }
    foreach (['environment/synthetic-test-settings.ini' => "# Synthetic test settings; values supplied explicitly by PHPUnit.\n",
        'app/.gitignore' => "*\n!.gitignore\n"] as $relative => $contents) {
        $handle = fopen($root.'/'.$relative, 'x');
        if ($handle === false) {
            throw new RuntimeException('Cannot exclusively create ordinary synthetic fixture.');
        }
        try {
            if (fwrite($handle, $contents) !== strlen($contents)) {
                throw new RuntimeException('Cannot write ordinary synthetic fixture.');
            }
        } finally {
            fclose($handle);
        }
    }

    $app->useEnvironmentPath($root.'/environment');
    $app->loadEnvironmentFrom('synthetic-test-settings.ini');
    $app->useStoragePath($root);
    // Keep compiled views owned even when the caller supplies a view-path override.
    $app->afterBootstrapping(LoadConfiguration::class, static function (Application $app) use ($root): void {
        $app['config']->set('view.compiled', $root.'/framework/views');
    });
}
