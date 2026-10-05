<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;

trait CreatesApplication
{
    public function createApplication()
    {
        require_once __DIR__.'/Support/ordinary-synthetic-runtime.php';

        $app = require __DIR__.'/../bootstrap/app.php';

        \Tests\Support\ordinarySyntheticRuntime($app);

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
