<?php

use App\Models\Characterization;
use App\Services\SectorComparisonGateway;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Factory as HttpFactory;
use Symfony\Component\Process\Process;

/** A single-request, loopback-only upstream; no project host or runtime files. */
function sectorComparisonUpstream(string $mode, array $fixture): Process
{
    $source = <<<'PHP'
$body = stream_get_contents(STDIN);
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($server === false) {
    fwrite(STDERR, 'synthetic listener unavailable');
    exit(1);
}
fwrite(STDOUT, 'http://'.stream_socket_get_name($server, false)."\n");
fflush(STDOUT);
$client = stream_socket_accept($server, 8);
if ($client === false) {
    exit(2);
}
stream_set_timeout($client, 8);
$length = 0;
while (($line = fgets($client)) !== false && $line !== "\r\n") {
    if (preg_match('/^Content-Length:\s*(\d+)/i', $line, $matches)) {
        $length = (int) $matches[1];
    }
}
while ($length > 0 && ($chunk = fread($client, $length)) !== false && $chunk !== '') {
    $length -= strlen($chunk);
}
fwrite(STDOUT, "request-received\n");
fflush(STDOUT);
if ($argv[1] === 'headers') {
    usleep(6000000);
}
fwrite($client, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\n");
if ($argv[1] === 'chunks') {
    // Frequent progress defeats per-read timeouts but must not reset the total.
    for ($i = 0; $i < 24; $i++) {
        if (@fwrite($client, "1\r\n \r\n") === false) {
            break;
        }
        if ($i === 0) {
            fwrite(STDOUT, "first-chunk-sent\n");
            fflush(STDOUT);
        }
        usleep(250000);
    }
}
if ($argv[1] === 'limit' || $argv[1] === 'oversized') {
    $body = str_pad($body, $argv[1] === 'limit' ? 262144 : 262145, ' ');
}
foreach (str_split($body, 8192) as $chunk) {
    if (@fwrite($client, dechex(strlen($chunk))."\r\n".$chunk."\r\n") === false) {
        break;
    }
}
@fwrite($client, "0\r\n\r\n");
fclose($client);
fclose($server);
PHP;
    $process = new Process([PHP_BINARY, '-r', $source, $mode]);
    $process->setInput(json_encode($fixture, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    $process->setTimeout(10);
    $process->start();

    return $process;
}

beforeEach(function () {
    config([
        'services.sector_comparison.enabled' => true,
        'services.characterization.api.base_url' => 'http://127.0.0.1:8001',
        'services.characterization.api.token' => 'synthetic-test-token',
    ]);
    $this->sectorFixtureJson = file_get_contents(base_path('tests/Fixtures/sector-comparison-response.json'));
    $this->sectorFixture = json_decode($this->sectorFixtureJson, true, 64, JSON_THROW_ON_ERROR);
});

it('serves the CLI synthetic counts and transmits only codes and topics', function () {
    Http::swap(new HttpFactory);
    Http::fake(['*' => Http::response($this->sectorFixture)]);
    $c = new Characterization(['nace_code' => 'K', 'form_data' => ['company_profile' => ['company_name' => 'Never sent', 'products_services' => ['finance_insurance']]]]);
    $result = app(SectorComparisonGateway::class)->compare($c, range(1, 89));
    expect($result['comparisons'][0]['topics'][0]['sector'])->toBe(['n_scored' => 5, 'n_material' => 3]);
    expect($result['comparisons'][0]['topics'][1]['source'])->toBe('global_fallback');
    expect($result['comparisons'][0]['topics'][2]['proportion'])->toBeNull();
    Http::assertSent(fn ($request) => $request->url() === 'http://127.0.0.1:8001/sector-comparison'
        && $request->data() === ['version' => 'sector-comparison-v1', 'sections' => ['K'], 'topics' => array_map('strval', range(1, 89))]
        && $request->hasHeader('Authorization', 'Bearer synthetic-test-token'));
});

it('preserves NACE sections J K L M and V independently of products', function () {
    foreach (['J', 'K', 'L', 'M', 'V'] as $code) {
        $c = new Characterization(['nace_code' => $code.'64', 'form_data' => ['company_profile' => ['products_services' => ['L']]]]);
        expect(SectorComparisonGateway::sections($c))->toBe([$code]);
    }
    expect(SectorComparisonGateway::sections(new Characterization(['nace_code' => null])))->toBe([]);
    expect(SectorComparisonGateway::sections(new Characterization(['nace_code' => 'W'])))->toBe([]);
});

it('serves identical counts on repeated reads with independent response streams', function () {
    Http::swap(new HttpFactory);
    // Preserve the original JSON number types (including 0.0) in each fresh stream.
    Http::fake(fn () => Http::response($this->sectorFixtureJson, 200, ['Content-Type' => 'application/json']));
    $gateway = app(SectorComparisonGateway::class);
    $characterization = new Characterization(['nace_code' => 'K']);
    foreach (range(1, 3) as $read) {
        expect($gateway->compare($characterization, range(1, 89)))->toBe($this->sectorFixture);
    }
    Http::assertSentCount(3);
});

it('rejects bad hashes schemas counts support sources and ratios', function () {
    $mutations = [
        fn (&$r) => $r['hashes']['encoding'] = str_repeat('0', 64),
        fn (&$r) => $r['hashes']['topic_mapping'] = str_repeat('0', 64),
        fn (&$r) => $r['artifact_sha256'] = 'invalid',
        fn (&$r) => $r['extra'] = true,
        fn (&$r) => $r['comparisons'][0]['topics'][0]['sector']['n_material'] = 6,
        fn (&$r) => $r['comparisons'][0]['topics'][0]['sector']['n_scored'] = 6,
        fn (&$r) => $r['comparisons'][0]['population_companies'] = 7,
        fn (&$r) => $r['comparisons'][0]['topics'][0]['sector']['n_scored'] = '5',
        fn (&$r) => $r['comparisons'][0]['topics'][0]['proportion'] = 0.5,
        fn (&$r) => $r['comparisons'][0]['topics'][1]['source'] = 'sector',
        fn (&$r) => $r['comparisons'][0]['topics'][2]['proportion'] = 0,
        fn (&$r) => $r['comparisons'][0]['topics'][0]['low_confidence'] = true,
        fn (&$r) => $r['comparisons'][0]['section'] = 'L',
        fn (&$r) => $r['comparisons'][0]['topics'][0]['topic_id'] = '2',
    ];
    foreach ($mutations as $mutate) {
        $r = $this->sectorFixture;
        $mutate($r);
        Http::swap(new HttpFactory);
        Http::fake(['*' => Http::response($r)]);
        expect(fn () => app(SectorComparisonGateway::class)->compare(new Characterization(['nace_code' => 'K']), range(1, 89)))->toThrow(RuntimeException::class);
    }
});

it('rejects excessive response bodies timeouts and redirects', function () {
    $c = new Characterization(['nace_code' => 'K']);
    Http::swap(new HttpFactory);
    Http::fake(['*' => Http::response(str_repeat('x', 262145))]);
    expect(fn () => app(SectorComparisonGateway::class)->compare($c, range(1, 89)))->toThrow(RuntimeException::class);
    Http::swap(new HttpFactory);
    Http::fake(fn () => throw new ConnectionException('synthetic timeout'));
    expect(fn () => app(SectorComparisonGateway::class)->compare($c, range(1, 89)))->toThrow(ConnectionException::class);
    Http::swap(new HttpFactory);
    Http::fake(['*' => Http::response('', 302, ['Location' => 'http://elsewhere.invalid'])]);
    expect(fn () => app(SectorComparisonGateway::class)->compare($c, range(1, 89)))->toThrow(RuntimeException::class);
});

it('enforces the total transport deadline for delayed headers and slow chunks', function (string $mode) {
    Http::swap(new HttpFactory); // Real cURL transport, no mocked timeout.
    $server = sectorComparisonUpstream($mode, $this->sectorFixture);
    try {
        expect($server->waitUntil(fn ($type, $output) => $type === Process::OUT && str_contains($output, "\n")))->toBeTrue();
        config(['services.characterization.api.base_url' => strtok($server->getOutput(), "\n")]);
        $start = hrtime(true);
        expect(fn () => app(SectorComparisonGateway::class)->compare(new Characterization(['nace_code' => 'K']), range(1, 89)))
            ->toThrow(ConnectionException::class);
        $elapsed = (hrtime(true) - $start) / 1e9;
        expect($elapsed)->toBeGreaterThan(2.5)->toBeLessThan(4.5);
        expect($server->getOutput())->toContain('request-received');
        if ($mode === 'chunks') {
            expect($server->getOutput())->toContain('first-chunk-sent');
        }
    } finally {
        $server->stop(0);
    }
})->with(['headers', 'chunks']);

it('bounds the actual chunked response sink at the inclusive byte limit', function (string $mode) {
    Http::swap(new HttpFactory);
    $server = sectorComparisonUpstream($mode, $this->sectorFixture);
    $optionsSeen = null;
    // Inspect the deadline and redirect options on an actual transport request.
    Http::globalMiddleware(function ($handler) use (&$optionsSeen) {
        return function ($request, $options) use ($handler, &$optionsSeen) {
            $optionsSeen = $options;

            return $handler($request, $options);
        };
    });
    try {
        expect($server->waitUntil(fn ($type, $output) => $type === Process::OUT && str_contains($output, "\n")))->toBeTrue();
        config(['services.characterization.api.base_url' => strtok($server->getOutput(), "\n")]);
        if ($mode === 'limit') {
            $result = app(SectorComparisonGateway::class)->compare(new Characterization(['nace_code' => 'K']), range(1, 89));
            expect($result)->toBe($this->sectorFixture);
        } else {
            expect(fn () => app(SectorComparisonGateway::class)->compare(new Characterization(['nace_code' => 'K']), range(1, 89)))
                ->toThrow(RuntimeException::class);
        }
        expect($optionsSeen['connect_timeout'])->toBe(1);
        expect($optionsSeen['timeout'])->toBe(3);
        expect($optionsSeen['stream'])->toBeFalse();
        expect($optionsSeen['allow_redirects'])->toBeFalse();
    } finally {
        $server->stop(0);
    }
})->with(['limit', 'oversized']);
