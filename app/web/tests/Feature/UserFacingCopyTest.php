<?php

use Illuminate\Support\Facades\File;

function activeCopyFiles(string $root): array
{
    if (! File::exists($root)) {
        return [];
    }

    return collect(File::allFiles($root))
        ->reject(fn (SplFileInfo $file) => str_contains(str_replace('\\', '/', $file->getPathname()), '/archive/'))
        ->filter(fn (SplFileInfo $file) => in_array($file->getExtension(), ['blade', 'php'], true))
        ->values()
        ->all();
}

test('active backend user-facing copy does not expose backend framework names in any locale', function () {
    $files = [
        ...activeCopyFiles(resource_path('views')),
        ...activeCopyFiles(lang_path()),
    ];

    $offenders = [];

    foreach ($files as $file) {
        $relative = str_replace('\\', '/', $file->getRelativePathname());
        $source = File::get($file->getPathname());

        foreach (preg_split('/\r?\n/', $source) as $index => $line) {
            if (! str_contains($line, 'Laravel')) {
                continue;
            }

            if (str_contains($line, "class_exists('Laravel\\\\Socialite")) {
                continue;
            }

            $offenders[] = "{$relative}:".($index + 1).': '.trim($line);
        }
    }

    expect($offenders)->toBe([]);
});
