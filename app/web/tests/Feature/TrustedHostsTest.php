<?php

use App\Providers\AppServiceProvider;
use App\Support\AppUrlWarning;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

afterEach(function () {
    Request::setTrustedHosts([]);
});

function deploymentAppConfig(array $environment): array
{
    $previous = [];
    foreach ($environment as $name => $value) {
        $previous[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)];
        if ($value === null) {
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);
        } else {
            $_ENV[$name] = $_SERVER[$name] = $value;
            putenv($name.'='.$value);
        }
    }

    try {
        return require config_path('app.php');
    } finally {
        foreach ($previous as $name => [$env, $server, $process]) {
            unset($_ENV[$name], $_SERVER[$name]);
            if ($env !== null) {
                $_ENV[$name] = $env;
            }
            if ($server !== null) {
                $_SERVER[$name] = $server;
            }
            putenv($process === false ? $name : $name.'='.$process);
        }
    }
}

it('combines explicit, canonical and default internal hosts and removes placeholders', function () {
    $config = deploymentAppConfig([
        'APP_URL' => 'https://AIRIS.SYGRIS.COM',
        'TRUSTED_HOSTS' => ' Extra.example.net,EXAMPLE.COM, app.example.org, ,WEB,extra.example.net',
        'INTERNAL_TRUSTED_HOSTS' => null,
        'APP_ENV' => 'production',
        'ENFORCE_TRUSTED_HOSTS' => null,
    ]);

    expect($config['trusted_hosts'])->toBe(['extra.example.net', 'web', 'airis.sygris.com', 'localhost', '127.0.0.1']);
    expect($config['enforce_trusted_hosts'])->toBeTrue();
});

it('accepts custom internal hosts and preserves explicit enforcement opt out', function () {
    $config = deploymentAppConfig([
        'APP_URL' => 'https://APP.EXAMPLE.ORG',
        'TRUSTED_HOSTS' => '',
        'INTERNAL_TRUSTED_HOSTS' => 'WEB, api.internal,Example.com, ,web',
        'APP_ENV' => 'production',
        'ENFORCE_TRUSTED_HOSTS' => 'false',
    ]);

    expect($config['trusted_hosts'])->toBe(['web', 'api.internal']);
    expect($config['enforce_trusted_hosts'])->toBeFalse();
});

it('serves canonical and internal requests while rejecting unknown hosts', function () {
    $config = deploymentAppConfig([
        'APP_URL' => 'https://airis.sygris.com',
        'TRUSTED_HOSTS' => 'example.com',
        'INTERNAL_TRUSTED_HOSTS' => null,
        'ENFORCE_TRUSTED_HOSTS' => 'true',
    ]);
    config(['app.url' => $config['url'], 'app.trusted_hosts' => $config['trusted_hosts'], 'app.enforce_trusted_hosts' => true]);
    Route::middleware('web')->get('/_test/trusted-host', fn () => response('ok'));

    $this->get('https://airis.sygris.com/_test/trusted-host')->assertOk();
    $this->get('http://web/_test/trusted-host')->assertOk();
    // Next must see an ordinary unauthenticated session response, not a host error.
    $this->getJson('https://airis.sygris.com/api/auth/session')->assertUnauthorized();
    $this->getJson('http://web/api/auth/session')->assertUnauthorized();
    $this->get('https://unknown.example.net/_test/trusted-host')->assertStatus(400);
});

it('logs an invalid production APP_URL once without aborting requests or revealing its value', function (string $url) {
    $this->app['env'] = 'production';
    config(['app.url' => $url, 'app.enforce_trusted_hosts' => false]);
    if (! is_readable('/proc/self/stat')) {
        $this->markTestSkipped('The public Docker runtime uses Linux procfs.');
    }
    $directory = sys_get_temp_dir().'/i4s-app-url-test-'.bin2hex(random_bytes(12));
    $this->app->instance(AppUrlWarning::class, new AppUrlWarning($directory));
    Log::spy();

    try {
        $provider = new AppServiceProvider($this->app);
        $provider->boot();
        $provider->boot();
        Route::middleware('web')->get('/_test/invalid-url', fn () => response('ok'));
        $this->get('/_test/invalid-url')->assertOk();
        Log::shouldHaveReceived('error')->once()->with('APP_URL must have a public host; its host is empty, localhost or a placeholder.');
    } finally {
        @unlink($directory.'/'.getmypid());
        @rmdir($directory);
    }
})->with(['', 'http://localhost', 'https://example.com', 'https://app.example.org']);

it('does not let a marker from a previous process start suppress the APP_URL warning', function () {
    if (! is_readable('/proc/self/stat')) {
        $this->markTestSkipped('The public Docker runtime uses Linux procfs.');
    }
    $directory = sys_get_temp_dir().'/i4s-app-url-test-'.bin2hex(random_bytes(12));
    mkdir($directory, 0700);
    $marker = $directory.'/'.getmypid();
    file_put_contents($marker, 'previous-boot:previous-start');
    Log::spy();

    try {
        (new AppUrlWarning($directory))->logOnce();
        (new AppUrlWarning($directory))->logOnce();
        Log::shouldHaveReceived('error')->once()->with(AppUrlWarning::MESSAGE);
        expect(file_get_contents($marker))->not->toBe('previous-boot:previous-start');
    } finally {
        unlink($marker);
        rmdir($directory);
    }
});

it('reports a broken warning marker directory without aborting', function () {
    $file = tempnam(sys_get_temp_dir(), 'i4s-app-url-test-');
    Log::spy();
    try {
        (new AppUrlWarning($file.'/unwritable'))->logOnce();
        Log::shouldHaveReceived('error')->once()->with(AppUrlWarning::MESSAGE.' Warning deduplication requires readable procfs and a writable temporary directory.');
    } finally {
        unlink($file);
    }
});
