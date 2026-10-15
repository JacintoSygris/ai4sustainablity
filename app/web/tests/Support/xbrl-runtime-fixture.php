<?php

use App\Models\XbrlIdentificationRun;
use App\Models\XbrlIdentificationReview;
use App\Services\Report\XbrlRuntimeIdentificationService;
use App\Services\Report\XbrlConceptMap;

function xbrlRuntimeTestInput(): array
{
    return ['schema_version' => 'xbrl-runtime-identification-v1', 'request_id' => '67a0e4ba-0936-4e41-81a0-383521754912',
        'taxonomy_profile' => 'esrs_set1_2023-12-22', 'catalog_sha256' => str_repeat('b', 64),
        'indicator' => ['label' => '  Synthetic water indicator  ', 'description' => 'Previously unseen synthetic description.', 'language' => 'en']];
}

function xbrlRuntimeTestContext(): array
{
    $map = app(XbrlConceptMap::class)->capture(false)->frozen();
    $inventory = [];
    foreach ($map['concepts'] as $entry) if (is_string($entry['concept_id'] ?? null)) $inventory[$entry['concept_id']] = true;
    return ['identity' => ['model_version' => 'synthetic-test-v1', 'artifact_sha256' => str_repeat('a', 64),
        'catalog_sha256' => str_repeat('b', 64), 'concept_map_sha256' => $map['sha256'],
        'taxonomy_profile' => 'esrs_set1_2023-12-22', 'package_sha256' => null],
        'inventory' => $inventory, 'metadata' => [], 'verified_taxonomy' => false, 'validation_package_sha256' => null, 'languages' => ['en'], 'thresholds' => ['min_score' => 0.5, 'min_margin' => 0.05]];
}

function xbrlRuntimeTestResponse(?array $input = null): array
{
    $context = xbrlRuntimeTestContext();
    $input ??= xbrlRuntimeTestInput();
    return ['schema_version' => $input['schema_version'], 'request_id' => $input['request_id'], ...$context['identity'],
        'candidates' => [['qname' => array_key_first($context['inventory']), 'rank' => 1, 'score' => 0.85,
            'validation_status' => 'map_only', 'validation_reasons' => ['taxonomy_unconfigured']]],
        'abstained' => false, 'reason_codes' => []];
}

/** Synthetic service ranking whose leading concept is abstract in local metadata. */
function xbrlRuntimePartialFilteringFixture(array $scores, ?array $input = null): array
{
    $context = xbrlRuntimeTestContext();
    $context['verified_taxonomy'] = true;
    $context['validation_package_sha256'] = str_repeat('e', 64);
    $response = xbrlRuntimeTestResponse($input);
    $response['candidates'] = [];
    $qnames = array_keys($context['inventory']);
    foreach ($scores as $i => $score) {
        $qname = $qnames[$i];
        $context['metadata'][$qname] = ['type' => '{http://www.xbrl.org/2003/instance}stringItemType',
            'period_type' => 'duration', 'abstract' => $i === 0];
        $response['candidates'][] = ['qname' => $qname, 'rank' => $i + 1, 'score' => $score,
            'validation_status' => 'map_only', 'validation_reasons' => ['taxonomy_unconfigured']];
    }
    return [$context, $response];
}

function xbrlRuntimeReviewedFixture(\App\Models\Characterization $company): XbrlIdentificationRun
{
    $input = xbrlRuntimeTestInput(); $response = xbrlRuntimeTestResponse($input);
    $run = XbrlIdentificationRun::create(['characterization_id' => $company->id, 'creator_id' => $company->user_id,
        'subject_key' => 'custom:'.\Illuminate\Support\Str::uuid(), 'input_json' => $input,
        'input_sha256' => XbrlRuntimeIdentificationService::hash($input), 'context_sha256' => str_repeat('c', 64),
        'response_json' => $response, 'model_version' => $response['model_version'], 'artifact_sha256' => $response['artifact_sha256'],
        'catalog_sha256' => $response['catalog_sha256'], 'created_at' => now('UTC')]);
    XbrlIdentificationReview::create(['run_id' => $run->id, 'candidate_qname' => $response['candidates'][0]['qname'],
        'decision' => 'accepted', 'reviewer_id' => $company->user_id, 'created_at' => now('UTC'), 'sequence' => 1, 'reason' => 'Synthetic test review only.']);
    return $run;
}
