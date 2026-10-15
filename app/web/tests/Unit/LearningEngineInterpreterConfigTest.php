<?php

use Symfony\Component\Process\Process;

it('keeps the qualified synthetic interpreter path when the environment is not mounted at config load', function () {
    $config = learningInterpreterConfig(null);
    $binary = PHP_OS_FAMILY === 'Windows' ? 'Scripts/python.exe' : 'bin/python';

    expect($config['synthetic_python'])->toBe('/unmounted synthetic fixture/app/ai-service/.venv-learning/'.$binary)
        ->and($config['enabled'])->toBeFalse()
        ->and($config['scheduled'])->toBeFalse()
        ->and($config['authority'])->toBe('deny')
        ->and($config['synthetic_only'])->toBeTrue();
});

it('uses an explicit operator interpreter unchanged for both learning launchers', function () {
    $python = '/operator supplied learning environment/bin/python';
    $config = learningInterpreterConfig($python);

    expect($config['synthetic_python'])->toBe($python)
        ->and($config['python'])->toBe($python);
});

function learningInterpreterConfig(?string $python): array
{
    // Execute the real config in an isolated PHP process. No dependency mounts,
    // database, environment mutation, shell interpolation or Python are needed.
    $script = <<<'PHP'
        function base_path($path = '') { return '/unmounted synthetic fixture/app/web'.($path === '' ? '' : '/'.$path); }
        function storage_path($path) { return base_path('storage/'.$path); }
        function env($key, $default = null) {
            global $argv;
            return $key === 'LEARNING_ENGINE_PYTHON' && $argv[2] !== '' ? $argv[2] : $default;
        }
        echo json_encode(require $argv[1], JSON_THROW_ON_ERROR);
        PHP;
    $process = new Process([PHP_BINARY, '-r', $script, dirname(__DIR__, 2).'/config/learning_engine.php', $python ?? '']);
    $process->setTimeout(5);
    $process->mustRun();

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}
