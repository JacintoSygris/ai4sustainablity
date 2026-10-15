<?php

use App\Services\Report\XbrlRuntimeClient;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);
require_once __DIR__.'/../../Support/xbrl-runtime-fixture.php';

beforeEach(function () {
    config(['services.characterization.api.base_url' => 'http://127.0.0.1:8001', 'services.characterization.api.token' => 'synthetic-test-token']);
});

it('uses private bounded no redirect transport and independently validates map membership', function () {
    Http::fake(['*' => Http::response(xbrlRuntimeTestResponse())]);
    $response = app(XbrlRuntimeClient::class)->identify(xbrlRuntimeTestInput(), xbrlRuntimeTestContext());
    expect($response['candidates'][0]['validation_status'])->toBe('map_only');
    Http::assertSent(function ($request) {
        return $request->url() === 'http://127.0.0.1:8001/xbrl/concept-candidates'
            && $request->hasHeader('Authorization', 'Bearer synthetic-test-token')
            && ! isset($request['user_id']) && ! isset($request['indicator']['value']);
    });
});

it('rejects redirects errors timeouts malformed and oversized bodies', function (string $mode) {
    if ($mode === 'timeout') Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('private details'));
    else Http::fake(['*' => match ($mode) {
        'redirect' => Http::response('', 302, ['Location' => 'https://example.invalid']),
        'oversize' => Http::response(str_repeat('x', 32769)),
        'malformed' => Http::response('{'),
        default => Http::response([], 500),
    }]);
    expect(fn () => app(XbrlRuntimeClient::class)->identify(xbrlRuntimeTestInput(), xbrlRuntimeTestContext()))->toThrow(RuntimeException::class, 'xbrl_runtime_unavailable');
    if ($mode !== 'timeout') Http::assertSentCount(1);
})->with(['redirect', 'oversize', 'malformed', 'timeout', 'server']);

it('rejects wrong identity scores ranks and foreign concepts', function (string $mode) {
    $response = xbrlRuntimeTestResponse();
    if ($mode === 'hash') $response['artifact_sha256'] = str_repeat('d', 64);
    if ($mode === 'request') $response['request_id'] = 'other';
    if ($mode === 'duplicate') $response['candidates'][] = $response['candidates'][0];
    if ($mode === 'rank') $response['candidates'][0]['rank'] = 2;
    if ($mode === 'score') $response['candidates'][0]['score'] = 1.5;
    if ($mode === 'nan') $response['candidates'][0]['score'] = NAN;
    if ($mode === 'foreign') $response['candidates'][0]['qname'] = 'esrs:Invented';
    expect(fn () => app(XbrlRuntimeClient::class)->validateResponse($response, xbrlRuntimeTestInput(), xbrlRuntimeTestContext()))->toThrow(RuntimeException::class);
})->with(['hash', 'request', 'duplicate', 'rank', 'score', 'nan', 'foreign']);

it('refuses non loopback ambiguous urls and missing bearer config', function (string $base) {
    config(['services.characterization.api.base_url' => $base]);
    Http::fake();
    expect(fn () => app(XbrlRuntimeClient::class)->identify(xbrlRuntimeTestInput(), xbrlRuntimeTestContext()))->toThrow(RuntimeException::class);
    Http::assertNothingSent();
})->with(['https://example.invalid', 'http://localhost:8001', 'http://127.0.0.1:8001@evil.invalid', 'http://user@127.0.0.1:8001', 'http://127.0.0.1:8001?x=y']);

it('rejects service claims that bypass the manifest abstention thresholds', function () {
    $response = xbrlRuntimeTestResponse();
    $response['candidates'][0]['score'] = 0.1;
    expect(fn () => app(XbrlRuntimeClient::class)->validateResponse($response, xbrlRuntimeTestInput(), xbrlRuntimeTestContext()))
        ->toThrow(RuntimeException::class, 'xbrl_runtime_abstention_mismatch');
});

it('rejects a service success for unknown or mismatched DR context', function () {
    foreach ([['standard' => 'E1', 'dr' => 'E1-99'], ['standard' => 'E1', 'dr' => 'E3-4']] as $context) {
        $input = xbrlRuntimeTestInput();
        $input['indicator'] += $context;
        expect(fn () => app(XbrlRuntimeClient::class)->validateResponse(xbrlRuntimeTestResponse(), $input, xbrlRuntimeTestContext()))
            ->toThrow(RuntimeException::class, 'xbrl_runtime_abstention_mismatch');
    }
});

it('reapplies score and margin thresholds to locally compatible candidates', function (array $scores, ?string $reason) {
    $input = xbrlRuntimeTestInput();
    $input['indicator'] += ['value_type' => 'text', 'period_type' => 'duration'];
    [$context, $response] = xbrlRuntimePartialFilteringFixture($scores, $input);
    $validated = app(XbrlRuntimeClient::class)->validateResponse($response, $input, $context);

    expect($validated['abstained'])->toBe($reason !== null)
        ->and($validated['reason_codes'])->toBe($reason === null ? [] : [$reason])
        ->and($validated['service_response'])->toBe($response)
        ->and($validated['candidates'][0]['validation_status'])->toBe('incompatible')
        ->and(array_column(array_slice($validated['candidates'], 1), 'validation_status'))
        ->toBe(array_fill(0, count($scores) - 1, 'verified_taxonomy'))
        ->and(array_column($validated['candidates'], 'rank'))->toBe(array_column($response['candidates'], 'rank'))
        ->and(array_column($validated['candidates'], 'score'))->toBe($scores);
})->with([
    'remaining score below threshold' => [[0.90, 0.40], 'below_threshold'],
    'remaining top two margin too small' => [[0.90, 0.70, 0.68], 'insufficient_margin'],
    'single survivor at threshold' => [[0.90, 0.50], null],
    'remaining ranking meets both thresholds' => [[0.90, 0.70, 0.60], null],
]);
