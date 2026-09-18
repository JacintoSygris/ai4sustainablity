<?php

use App\Models\Characterization;
use App\Models\EsrsTopic;
use App\Models\ReportingFact;
use App\Models\User;
use App\Services\Report\ReportingFactProjector;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\EsrsTopicSeeder::class);

    $this->user = User::factory()->create();
    $this->e2Topic = EsrsTopic::where('esrs_code', 'E2')->firstOrFail();
});

it('projects completed legacy P9 responses as review-required facts and never approved', function () {
    $characterization = reportingFactProjectorCharacterization($this->user, $this->e2Topic, [
        'BP-1_01' => [
            'datapoint_id' => 'BP-1_01',
            'status' => 'completed',
            'value' => 'Prepared on a consolidated basis.',
            'evidence_reference' => 'Finance pack 2025',
            'note' => 'Controller review note.',
        ],
    ]);

    $facts = app(ReportingFactProjector::class)->projectLegacy($characterization);

    expect($facts)->toHaveCount(1)
        ->and($facts[0]['datapoint_id'])->toBe('BP-1_01')
        ->and($facts[0]['fact_id'])->toBe(ReportingFact::factId(
            $characterization->id,
            ReportingFact::PROFILE_ID,
            'BP-1_01',
            [],
            'text',
            'und',
        ))
        ->and($facts[0]['applicability'])->toBe('applicable')
        ->and($facts[0]['value_type'])->toBe('text')
        ->and($facts[0]['value'])->toBe(['text' => 'Prepared on a consolidated basis.'])
        ->and($facts[0]['approval_status'])->toBe('review_required')
        ->and($facts[0]['provenance'])->toBe('p9_legacy_review_required');

    expect($facts[0]['approval_status'])->not->toBe('approved');
});

it('projects draft and not applicable legacy responses with safe review semantics', function () {
    $characterization = reportingFactProjectorCharacterization($this->user, $this->e2Topic, [
        'BP-1_01' => [
            'datapoint_id' => 'BP-1_01',
            'status' => 'draft',
            'value' => 'Still being drafted.',
        ],
        'BP-1_02' => [
            'datapoint_id' => 'BP-1_02',
            'status' => 'not_applicable',
            'note' => 'Not applicable according to preparer.',
        ],
    ]);

    $facts = collect(app(ReportingFactProjector::class)->projectLegacy($characterization))
        ->keyBy('datapoint_id');

    expect($facts)->toHaveCount(2)
        ->and($facts['BP-1_01']['applicability'])->toBe('pending')
        ->and($facts['BP-1_01']['approval_status'])->toBe('review_required')
        ->and($facts['BP-1_02']['applicability'])->toBe('not_applicable')
        ->and($facts['BP-1_02']['approval_status'])->toBe('review_required')
        ->and($facts['BP-1_02']['evidence_refs'])->toHaveCount(1)
        ->and($facts['BP-1_02']['blocking_reasons'])->toBe([]);
});

it('adds a blocking reason for not applicable legacy responses without evidence', function () {
    $characterization = reportingFactProjectorCharacterization($this->user, $this->e2Topic, [
        'BP-1_01' => [
            'datapoint_id' => 'BP-1_01',
            'status' => 'not_applicable',
        ],
    ]);

    $facts = app(ReportingFactProjector::class)->projectLegacy($characterization);

    expect($facts)->toHaveCount(1)
        ->and($facts[0]['applicability'])->toBe('not_applicable')
        ->and($facts[0]['blocking_reasons'])->toContain('legacy_not_applicable_missing_evidence');
});

it('ignores malformed and orphaned legacy responses safely', function () {
    $characterization = reportingFactProjectorCharacterization($this->user, $this->e2Topic, [
        'BP-1_01' => [
            'datapoint_id' => 'BP-1_01',
            'status' => 'completed',
            'value' => 'Prepared on a consolidated basis.',
        ],
        'BP-1_02' => 'malformed row',
        'ORPHAN_01' => [
            'datapoint_id' => 'ORPHAN_01',
            'status' => 'completed',
            'value' => 'This datapoint is not in the current corpus.',
        ],
        'BP-1_03' => [
            'status' => 'completed',
            'value' => 'Missing datapoint id.',
        ],
        'BP-1_04' => [
            'datapoint_id' => 'BP-1_04',
            'status' => 'unexpected',
        ],
    ]);

    $facts = app(ReportingFactProjector::class)->projectLegacy($characterization);

    expect($facts)->toHaveCount(1)
        ->and($facts[0]['datapoint_id'])->toBe('BP-1_01');
});

function reportingFactProjectorCharacterization(User $user, EsrsTopic $topic, array $responses): Characterization
{
    return Characterization::factory()->create([
        'user_id' => $user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$topic->id],
        'form_data' => [
            'operations' => [
                'employee_count_range' => '50_249',
            ],
            'materiality_confirmation' => [
                'confirmed_topic_ids' => [$topic->id],
            ],
            'esrs_datapoint_responses' => [
                'schema_version' => 'v0',
                'responses' => $responses,
            ],
        ],
    ]);
}
