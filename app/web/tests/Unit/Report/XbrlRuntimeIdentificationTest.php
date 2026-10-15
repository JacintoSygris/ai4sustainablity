<?php

use App\Services\Report\XbrlRuntimeContext;
use App\Services\Report\XbrlRuntimeClient;
use App\Services\Report\TaxonomyConceptMetadata;
use App\Services\Report\ExternalTaxonomyManifestRepository;

uses(Tests\TestCase::class);
require_once __DIR__.'/../../Support/xbrl-runtime-fixture.php';

beforeEach(function () {
    $this->manifest = tempnam(sys_get_temp_dir(), 'xbrl-runtime-test-');
    $map = app(\App\Services\Report\XbrlConceptMap::class)->capture(false)->frozen();
    file_put_contents($this->manifest, json_encode(['schema_version' => 'xbrl-runtime-identification-v1',
        'model_version' => 'synthetic-test-v1', 'taxonomy_profile' => 'esrs_set1_2023-12-22',
        'catalog_sha256' => $map['sha256'], 'inputs' => ['concept_map_sha256' => $map['sha256'], 'ig3_sha256' => hash_file('sha256', base_path('data/esrs_datapoints_ig3.json'))],
        'languages' => ['en'], 'thresholds' => ['min_score' => 0.5, 'min_margin' => 0.05], 'package_sha256' => null]));
    config(['xbrl_suggestions.runtime_manifest_path' => $this->manifest, 'xbrl_suggestions.runtime_catalog_path' => null,
        'services.report.external_taxonomy_manifest_path' => null]);
});
afterEach(function () { @unlink($this->manifest); });

it('distinguishes unconfigured taxonomy from configured corrupt taxonomy', function () {
    expect(app(XbrlRuntimeContext::class)->capture()['verified_taxonomy'])->toBeFalse();
    config(['services.report.external_taxonomy_manifest_path' => '/missing-private-package']);
    expect(fn () => app(XbrlRuntimeContext::class)->capture())->toThrow(RuntimeException::class);
});

it('binds exact map bytes and refuses altered source hashes', function () {
    $manifest = json_decode(file_get_contents($this->manifest), true);
    $manifest['inputs']['concept_map_sha256'] = str_repeat('a', 64);
    file_put_contents($this->manifest, json_encode($manifest));
    expect(fn () => app(XbrlRuntimeContext::class)->capture())->toThrow(RuntimeException::class);
});

it('validates abstract period type and unit metadata independently', function () {
    $metadata = ['type' => '{http://www.xbrl.org/2003/instance}monetaryItemType', 'period_type' => 'duration', 'abstract' => false];
    $input = ['value_type' => 'monetary', 'period_type' => 'duration', 'unit' => 'EUR'];
    expect(XbrlRuntimeContext::compatibility(null, $input)[0])->toBe('map_only')
        ->and(XbrlRuntimeContext::compatibility($metadata, $input)[0])->toBe('verified_taxonomy')
        ->and(XbrlRuntimeContext::compatibility([...$metadata, 'abstract' => true], $input)[0])->toBe('incompatible')
        ->and(XbrlRuntimeContext::compatibility($metadata, [...$input, 'period_type' => 'instant'])[0])->toBe('incompatible')
        ->and(XbrlRuntimeContext::compatibility($metadata, [...$input, 'value_type' => 'text'])[0])->toBe('incompatible')
        ->and(XbrlRuntimeContext::compatibility([...$metadata, 'type' => '{unknown}custom'], $input)[0])->toBe('unverified');
});

it('overrides an invalid private service metadata claim without allowing acceptance', function () {
    $context = xbrlRuntimeTestContext(); $response = xbrlRuntimeTestResponse();
    $qname = $response['candidates'][0]['qname'];
    $context['verified_taxonomy'] = true;
    $context['metadata'][$qname] = ['type' => '{http://www.xbrl.org/2003/instance}stringItemType', 'period_type' => 'duration', 'abstract' => true];
    $validated = app(XbrlRuntimeClient::class)->validateResponse($response, xbrlRuntimeTestInput(), $context);
    expect($validated['candidates'][0]['validation_status'])->toBe('incompatible')
        ->and($validated['abstained'])->toBeTrue()
        ->and($validated['reason_codes'])->toBe(['no_compatible_candidates'])
        ->and($validated['service_response'])->toBe($response);
});

it('reconciles map only inference when verified local metadata rejects all candidates', function () {
    $context = xbrlRuntimeTestContext();
    $response = xbrlRuntimeTestResponse();
    $qname = $response['candidates'][0]['qname'];
    $context['verified_taxonomy'] = true;
    $context['metadata'][$qname] = ['type' => '{http://www.xbrl.org/2003/instance}decimalItemType', 'period_type' => 'duration', 'abstract' => false];
    $input = xbrlRuntimeTestInput();
    $input['indicator'] += ['value_type' => 'text', 'period_type' => 'duration'];
    $validated = app(XbrlRuntimeClient::class)->validateResponse($response, $input, $context);
    expect($validated['abstained'])->toBeTrue()->and($validated['reason_codes'])->toBe(['no_compatible_candidates'])
        ->and($validated['candidates'][0]['validation_reasons'])->toBe(['type_mismatch'])
        ->and($validated['service_response'])->toBe($response);
    $response['abstained'] = true;
    $response['reason_codes'] = ['below_threshold'];
    expect(app(XbrlRuntimeClient::class)->validateResponse($response, $input, $context)['reason_codes'])->toBe(['below_threshold']);
});

it('validates canonical DR membership and relationships independently of model priors', function () {
    foreach (['E1' => 9, 'E2' => 6, 'E3' => 5, 'E4' => 6, 'E5' => 6, 'S1' => 17, 'S2' => 5, 'S3' => 5, 'S4' => 5, 'G1' => 6] as $standard => $last) {
        expect(XbrlRuntimeContext::supportedContext(['standard' => $standard, 'dr' => $standard.'-'.$last]))->toBeTrue()
            ->and(XbrlRuntimeContext::supportedContext(['dr' => $standard.'-'.($last + 1)]))->toBeFalse();
    }
    expect(XbrlRuntimeContext::supportedContext(['dr' => 'E1-99']))->toBeFalse()
        ->and(XbrlRuntimeContext::supportedContext(['standard' => 'E1', 'dr' => 'E3-4']))->toBeFalse()
        ->and(XbrlRuntimeContext::supportedContext(['standard' => 'ESRS 2', 'dr' => 'MDR-P']))->toBeTrue()
        ->and(XbrlRuntimeContext::supportedContext(['standard' => 'E1', 'dr' => 'MDR-P']))->toBeFalse();
});

it('rejects a provisioned catalog when its external package is unconfigured', function () {
    config(['xbrl_suggestions.runtime_catalog_path' => $this->manifest]);
    expect(fn () => app(XbrlRuntimeContext::class)->capture())->toThrow(RuntimeException::class, 'xbrl_runtime_catalog_requires_taxonomy');
});
