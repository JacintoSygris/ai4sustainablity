<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('requires authentication for the double materiality guide', function () {
    $this->getJson('/api/double-materiality-guide')
        ->assertUnauthorized();
});

it('requires authentication for double materiality template csv downloads', function () {
    $this->getJson('/api/double-materiality-guide/templates/iro_register.csv')
        ->assertUnauthorized();
});

it('returns a structured P7 double materiality guide for the separate frontend', function () {
    $expectedTemplateColumns = [
        'iro_register' => [
            'ar16_topic_id',
            'ar16_topic_label',
            'iro_description',
            'iro_type',
            'value_chain_location',
            'stakeholder_or_financial_channel',
            'impact_materiality_score',
            'financial_materiality_score',
            'threshold_result',
            'evidence_reference',
        ],
        'stakeholder_consultation_log' => [
            'date',
            'stakeholder_group',
            'source',
            'matter_or_iro',
            'input_summary',
            'decision_effect',
            'evidence_reference',
        ],
    ];

    $response = $this->actingAs(User::factory()->create())
        ->getJson('/api/double-materiality-guide')
        ->assertOk()
        ->assertJsonPath('data.type', 'double_materiality_guide')
        ->assertJsonPath('data.phase', 'P7')
        ->assertJsonPath('data.content_format', 'structured_json')
        ->assertJsonPath('data.warning.es', 'La guia acelera la ADM externa; no decide la materialidad.')
        ->assertJsonPath('data.handoff.next_api', '/api/materiality-confirmation')
        ->assertJsonPath('data.handoff.next_phase', 'P8')
        ->assertJsonPath('data.templates.0.key', 'iro_register')
        ->assertJsonPath('data.templates.1.key', 'stakeholder_consultation_log');

    expect(collect($response->json('data.sections'))->pluck('key')->all())
        ->toBe([
            'prepare_scope',
            'identify_iros',
            'assess_materiality',
            'document_decision',
            'return_to_p8',
        ]);

    expect($response->json('data.sections.0.steps.0.title.es'))
        ->toBe('Revisar la caracterizacion P5 y la propuesta P6');

    $templatesByKey = collect($response->json('data.templates'))->keyBy('key');

    foreach ($expectedTemplateColumns as $templateKey => $columns) {
        expect($templatesByKey->get($templateKey)['columns'])->toBe($columns);
    }

    expect(json_encode($response->json('data')))->not->toContain('**');
});

it('downloads every advertised ADM template header as csv', function () {
    $user = User::factory()->create();

    $guide = $this->actingAs($user)
        ->getJson('/api/double-materiality-guide')
        ->assertOk()
        ->json('data');

    foreach ($guide['templates'] as $template) {
        $expectedFilename = str_replace('_', '-', $template['key']).'-template.csv';

        $response = $this->actingAs($user)
            ->get('/api/double-materiality-guide/templates/'.$template['key'].'.csv')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertHeader('content-disposition', 'attachment; filename='.$expectedFilename);

        expect(str_getcsv(trim($response->getContent())))->toBe($template['columns']);
    }
});

it('returns not found for unsupported ADM template csv keys', function () {
    $this->actingAs(User::factory()->create())
        ->getJson('/api/double-materiality-guide/templates/unsupported.csv')
        ->assertNotFound()
        ->assertJsonPath('message', 'Template not found.');
});
