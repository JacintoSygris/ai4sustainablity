<?php

use App\Services\Report\EsrsConceptResolver;
use App\Services\Report\IxbrlCandidateBuilder;
use DOMXPath;

uses(Tests\TestCase::class);

it('builds deterministic parseable XHTML iXBRL for compatible text monetary and percent facts', function () {
    $builder = app(IxbrlCandidateBuilder::class);
    $state = ixbrlCandidateState([
        'BP-1_01' => ixbrlCandidateResponse('BP-1_01', [
            ixbrlCandidateFact(['value' => 'Consolidated basis <script>alert(1)</script>']),
        ]),
        'E1-6_07' => ixbrlCandidateResponse('E1-6_07', [
            ixbrlCandidateFact([
                'fact_id' => '11111111-1111-4111-8111-111111111111',
                'value_kind' => 'monetary',
                'value' => '1234.50',
                'decimals' => 2,
                'unit' => ['measure' => 'iso4217:EUR'],
                'concept' => ixbrlConcept('esrs:Revenue'),
            ]),
            ixbrlCandidateFact([
                'fact_id' => '22222222-2222-4222-8222-222222222222',
                'value_kind' => 'percent',
                'value' => '0.25',
                'decimals' => 4,
                'unit' => ['measure' => 'pure'],
                'concept' => ixbrlConcept('esrs:PercentageOfScope1GreenhouseGasEmissionsReductionInTotalGreenhouseGasEmissionsReduction'),
            ]),
        ]),
    ]);

    $first = $builder->build($state);
    $second = $builder->build($state);

    expect($first->bytes)->toBe($second->bytes)
        ->and($first->filename)->toBe('ixbrl-candidate-characterization-123.xhtml')
        ->and($first->bytes)->toContain('Candidato tecnico iXBRL; no presentacion oficial')
        ->and($first->bytes)->toContain('taxonomies/esrs-set1-2024/xbrl.efrag.org/taxonomy/esrs/2023-12-22/esrs_all.xsd')
        ->and($first->bytes)->toContain('ix:nonNumeric')
        ->and($first->bytes)->toContain('ix:nonFraction')
        ->and($first->bytes)->toContain('unitRef=')
        ->and($first->bytes)->toContain('decimals="2"')
        ->and($first->bytes)->toContain('decimals="4"')
        ->and($first->bytes)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->and($first->bytes)->not->toContain('<script>alert(1)</script>')
        ->and($first->bytes)->not->toContain('file://')
        ->and($first->bytes)->not->toContain(base_path());

    $document = new DOMDocument();
    expect($document->loadXML($first->bytes, LIBXML_NONET))->toBeTrue();

    $xpath = new DOMXPath($document);
    $xpath->registerNamespace('xhtml', 'http://www.w3.org/1999/xhtml');
    $xpath->registerNamespace('ix', 'http://www.xbrl.org/2013/inlineXBRL');
    $xpath->registerNamespace('xml', 'http://www.w3.org/XML/1998/namespace');

    expect($xpath->query('/xhtml:html[@xml:lang="es"]'))->toHaveCount(1)
        ->and($xpath->query('/xhtml:html[@lang]'))->toHaveCount(0)
        ->and($xpath->query('/xhtml:html/xhtml:head/xhtml:meta'))->toHaveCount(0)
        ->and($xpath->query('//*[@data-datapoint-id]'))->toHaveCount(0)
        ->and($xpath->query('/xhtml:html/xhtml:head/ix:header'))->toHaveCount(0)
        ->and($xpath->query('/xhtml:html/xhtml:body/xhtml:div[@style="display:none"]/ix:header'))->toHaveCount(1);
});

it('orders facts and generated ids deterministically by datapoint and fact id', function () {
    $builder = app(IxbrlCandidateBuilder::class);
    $state = ixbrlCandidateState([
        'E1-6_07' => ixbrlCandidateResponse('E1-6_07', [
            ixbrlCandidateFact([
                'fact_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
                'concept' => ixbrlConcept('esrs:DescriptionOfBusinessModelAndValueChainExplanatory'),
            ]),
        ]),
        'BP-1_01' => ixbrlCandidateResponse('BP-1_01', [
            ixbrlCandidateFact(['fact_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']),
        ]),
    ]);

    $bytes = $builder->build($state)->bytes;
    $firstIdPosition = strpos($bytes, 'id="f_aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa"');
    $secondIdPosition = strpos($bytes, 'id="f_bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb"');

    expect($firstIdPosition)->not->toBeFalse()
        ->and($secondIdPosition)->not->toBeFalse()
        ->and($firstIdPosition)->toBeLessThan($secondIdPosition);
});

it('fails closed when an exportable fact is missing its id', function () {
    $builder = app(IxbrlCandidateBuilder::class);
    $fact = ixbrlCandidateFact();
    unset($fact['fact_id']);

    $preflight = $builder->preflight(ixbrlCandidateState([
        'BP-1_01' => ixbrlCandidateResponse('BP-1_01', [$fact]),
    ]));

    expect($preflight['status'])->toBe('blocked')
        ->and($preflight['reason_codes'])->toContain('fact_id_missing')
        ->and($preflight['blocking_datapoint_ids'])->toContain('BP-1_01');
});

it('fails closed for unknown taxonomy concepts and non mapped facts', function () {
    $builder = app(IxbrlCandidateBuilder::class);
    $state = ixbrlCandidateState([
        'BP-1_01' => ixbrlCandidateResponse('BP-1_01', [
            ixbrlCandidateFact(['concept' => ['concept_id' => 'esrs:InjectedMissing', 'taggable_state' => 'mapped']]),
        ]),
        'ZZ-9_99' => ixbrlCandidateResponse('ZZ-9_99', [
            ixbrlCandidateFact(['concept' => ['concept_id' => null, 'taggable_state' => 'not_taggable', 'reason_code' => 'unknown_datapoint']]),
        ]),
    ]);

    $preflight = $builder->preflight($state);

    expect($preflight['status'])->toBe('blocked')
        ->and($preflight['reason_codes'])->toContain('taxonomy_concept_unknown')
        ->and($preflight['reason_codes'])->toContain('fact_concept_not_mapped')
        ->and($preflight['blocking_datapoint_ids'])->toContain('BP-1_01')
        ->and($preflight['blocking_datapoint_ids'])->toContain('ZZ-9_99');
});

it('fails closed for unsupported or unresolved dimensions and XML control characters', function () {
    $builder = app(IxbrlCandidateBuilder::class);
    $state = ixbrlCandidateState([
        'BP-1_01' => ixbrlCandidateResponse('BP-1_01', [
            ixbrlCandidateFact([
                'value' => "bad\x01value",
                'context' => [
                    'period_type' => 'duration',
                    'start_date' => '2025-01-01',
                    'end_date' => '2025-12-31',
                    'instant_date' => null,
                    'dimensions' => [
                        ['axis' => 'bad:Axis', 'member' => 'esrs:BasisForPreparationOfSustainabilityStatementMember'],
                        ['axis' => 'esrs:BasisForPreparationOfSustainabilityStatementAxis', 'member' => 'esrs:MissingMember'],
                    ],
                ],
            ]),
        ]),
    ]);

    $preflight = $builder->preflight($state);

    expect($preflight['status'])->toBe('blocked')
        ->and($preflight['reason_codes'])->toContain('unsupported_dimension_namespace')
        ->and($preflight['reason_codes'])->toContain('dimension_member_unknown')
        ->and($preflight['reason_codes'])->toContain('xml_control_character');
});

it('fails closed for legacy or factless states', function () {
    $builder = app(IxbrlCandidateBuilder::class);

    expect($builder->preflight(ixbrlCandidateState([], ['stored_schema_version' => 'v0']))['reason_codes'])
        ->toContain('p9_schema_version_unsupported');

    expect($builder->preflight(ixbrlCandidateState([]))['reason_codes'])
        ->toContain('no_exportable_facts');
});

it('fails closed for incompatible or unsupported taxonomy value types before render', function () {
    $builder = app(IxbrlCandidateBuilder::class);

    $enumPreflight = $builder->preflight(ixbrlCandidateState([
        'BP-1_01' => ixbrlCandidateResponse('BP-1_01', [
            ixbrlCandidateFact([
                'concept' => ixbrlConcept('esrs:BasisForPreparationOfSustainabilityStatement'),
                'value_kind' => 'narrative',
            ]),
        ]),
    ]));

    $ghgPreflight = $builder->preflight(ixbrlCandidateState([
        'E1-6_07' => ixbrlCandidateResponse('E1-6_07', [
            ixbrlCandidateFact([
                'concept' => ixbrlConcept('esrs:GrossScope1GreenhouseGasEmissions'),
                'value_kind' => 'decimal',
                'value' => '123.4',
                'decimals' => 1,
                'unit' => ['measure' => 'pure'],
            ]),
        ]),
    ]));

    expect($enumPreflight['status'])->toBe('blocked')
        ->and($enumPreflight['reason_codes'])->toContain('concept_type_unsupported')
        ->and($enumPreflight['blocking_datapoint_ids'])->toContain('BP-1_01')
        ->and($ghgPreflight['status'])->toBe('blocked')
        ->and($ghgPreflight['reason_codes'])->toContain('concept_type_unsupported')
        ->and($ghgPreflight['blocking_datapoint_ids'])->toContain('E1-6_07');
});

it('fails closed when fact period type differs from taxonomy period type', function () {
    $builder = app(IxbrlCandidateBuilder::class);

    $preflight = $builder->preflight(ixbrlCandidateState([
        'S1-6_01' => ixbrlCandidateResponse('S1-6_01', [
            ixbrlCandidateFact([
                'concept' => ixbrlConcept('esrs:NumberOfEmployeesHeadcountAtEndOfPeriod'),
                'value_kind' => 'integer',
                'value' => '42',
                'decimals' => 0,
                'unit' => null,
            ]),
        ]),
    ]));

    expect($preflight['status'])->toBe('blocked')
        ->and($preflight['reason_codes'])->toContain('fact_period_type_incompatible')
        ->and($preflight['blocking_datapoint_ids'])->toContain('S1-6_01');
});

it('resolves only esrs global elements from the vendored core schema', function () {
    $resolver = app(EsrsConceptResolver::class);

    expect($resolver->namespaceUri())->toBe('https://xbrl.efrag.org/taxonomy/esrs/2023-12-22')
        ->and($resolver->resolveConcept('esrs:BasisForPreparationOfSustainabilityStatement'))->toMatchArray([
            'type' => 'enum2:enumerationItemType',
            'period_type' => 'duration',
        ])
        ->and($resolver->resolveConcept('esrs:DescriptionOfBusinessModelAndValueChainExplanatory'))->toMatchArray([
            'type' => 'dtr-types:textBlockItemType',
            'period_type' => 'duration',
        ])
        ->and($resolver->resolveConcept('esrs:MissingConcept'))->toBeNull()
        ->and($resolver->resolveConcept('bad:BasisForPreparationOfSustainabilityStatement'))->toBeNull();
});

function ixbrlConcept(string $conceptId): array
{
    return [
        'concept_id' => $conceptId,
        'taggable_state' => 'mapped',
        'reason_code' => null,
    ];
}

function ixbrlCandidateState(array $responses, array $overrides = []): array
{
    return array_replace_recursive([
        'characterization_id' => 123,
        'schema_version' => 'v1',
        'stored_schema_version' => 'v1',
        'reporting_entity' => [
            'identifier_scheme' => 'https://example.test/entity',
            'identifier' => 'demo-entity',
            'name' => 'Entidad Demo',
        ],
        'responses' => $responses,
    ], $overrides);
}

function ixbrlCandidateResponse(string $datapointId, array $facts, array $overrides = []): array
{
    $facts = array_map(function (array $fact) use ($datapointId): array {
        if (isset($fact['concept'])) {
            return $fact;
        }

        $fact['concept'] = [
            'concept_id' => $datapointId === 'E1-6_07'
                ? 'esrs:GrossScope1GreenhouseGasEmissions'
                : 'esrs:DescriptionOfBusinessModelAndValueChainExplanatory',
            'taggable_state' => 'mapped',
            'reason_code' => null,
        ];

        return $fact;
    }, $facts);

    return array_replace_recursive([
        'datapoint_id' => $datapointId,
        'status' => 'completed',
        'facts' => $facts,
        'fact_readiness' => ['state' => 'valid_completed', 'fact_count' => count($facts)],
    ], $overrides);
}

function ixbrlCandidateFact(array $overrides = []): array
{
    return array_replace_recursive([
        'fact_id' => '33333333-3333-4333-8333-333333333333',
        'value_kind' => 'narrative',
        'value' => 'Prepared response.',
        'decimals' => null,
        'unit' => null,
        'context' => [
            'period_type' => 'duration',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
            'instant_date' => null,
            'dimensions' => [],
        ],
        'evidence_reference' => 'Evidence pack 2025',
    ], $overrides);
}
