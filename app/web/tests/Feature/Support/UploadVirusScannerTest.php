<?php

use App\Support\UploadVirusScanner;
use Illuminate\Http\UploadedFile;

it('kills the complete scanner process group after the configured deadline', function () {
    if (PHP_OS_FAMILY === 'Windows') {
        $this->markTestSkipped('POSIX scanner fixture.');
    }

    $fixtureDir = storage_path('framework/cache/i4s-scanner-'.bin2hex(random_bytes(6)));
    mkdir($fixtureDir, 0700, true);
    $script = $fixtureDir.'/clamscan';
    $pidFile = tempnam(storage_path('framework/cache'), 'i4s-scanner-child-');
    file_put_contents(
        $script,
        '#!/bin/sh'.PHP_EOL
        .'sleep 20 &'.PHP_EOL
        .'child=$!'.PHP_EOL
        .'printf \'%s\' "$child" > '.escapeshellarg($pidFile).PHP_EOL
        .'wait "$child"'.PHP_EOL
    );
    chmod($script, 0700);

    config([
        'services.p6_document_upload.scan.enabled' => true,
        'services.p6_document_upload.scan.binary' => $script,
        'services.p6_document_upload.scan.timeout_seconds' => 0.5,
    ]);

    $started = microtime(true);
    try {
        $result = UploadVirusScanner::scan(
            UploadedFile::fake()->createWithContent('report.pdf', "%PDF-1.7\nfixture")
        );
    } finally {
        @unlink($script);
        @rmdir($fixtureDir);
    }

    $childPid = (int) file_get_contents($pidFile);
    @unlink($pidFile);
    $deadline = microtime(true) + 1.0;
    while ($childPid > 0 && @posix_kill($childPid, 0) && microtime(true) < $deadline) {
        usleep(10_000);
    }

    expect($result['ok'])->toBeFalse()
        ->and($result['result'])->toBe(UploadVirusScanner::UNAVAILABLE)
        ->and($result['detail'])->toBe('scanner timed out')
        ->and(microtime(true) - $started)->toBeLessThan(3.0)
        ->and($childPid)->toBeGreaterThan(0)
        ->and(@posix_kill($childPid, 0))->toBeFalse();
});

it('refuses production uploads when malware scanning is disabled', function () {
    $this->app['env'] = 'production';
    config(['services.p6_document_upload.scan.enabled' => false]);

    $result = UploadVirusScanner::scan(
        UploadedFile::fake()->createWithContent('report.pdf', "%PDF-1.7\nfixture")
    );

    expect($result['ok'])->toBeFalse()
        ->and($result['result'])->toBe(UploadVirusScanner::UNAVAILABLE);
});

it('does not replace an explicitly configured scanner path with a PATH fallback', function () {
    config([
        'services.p6_document_upload.scan.enabled' => true,
        'services.p6_document_upload.scan.binary' => '/definitely/missing/i4s-clamscan',
    ]);

    $result = UploadVirusScanner::scan(
        UploadedFile::fake()->createWithContent('report.pdf', "%PDF-1.7\nfixture")
    );

    expect($result['ok'])->toBeFalse()
        ->and($result['result'])->toBe(UploadVirusScanner::UNAVAILABLE)
        ->and($result['detail'])->toContain('not found');
});
