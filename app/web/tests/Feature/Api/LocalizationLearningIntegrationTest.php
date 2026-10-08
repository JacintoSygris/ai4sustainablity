<?php

use App\Models\{Characterization, EsrsTopic, User};
use App\Services\{DenyLearningAuthorizationAuthority, EsrsDatapointCorpusBuilder, EsrsDatapointResponseState, LearningAuthorizationAuthority, LearningP8SourceRevisionClock};
use Illuminate\Support\Facades\{DB, Http};

beforeEach(function () {
    config(['services.private_dev.auto_login' => false]);
    Http::preventStrayRequests();
    $this->seed(\Database\Seeders\EsrsTopicSeeder::class);
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('projects both P9 locales over the same canonical atomic authority and retains orphaned responses', function () {
    $row = Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [],
        'form_data' => ['esrs_datapoint_responses' => [
            'revision' => 0,
            'responses' => ['retired-synthetic-row' => ['status' => 'draft', 'value' => 'Retained orphan']],
        ]],
    ]);
    $canonical = app(EsrsDatapointCorpusBuilder::class)->build($row->fresh());
    $authority = app(EsrsDatapointResponseState::class)->learningAuthorityDigest($canonical);
    $original = $row->getRawOriginal('form_data');
    $priorState = null;
    foreach (['es', 'en'] as $locale) {
        $this->putJson('/api/locale', ['locale' => $locale])->assertOk();
        $response = $this->getJson('/api/esrs-datapoints')->assertOk()
            ->assertJsonPath('snapshot_version', 'p9-workspace-v1')
            ->assertJsonPath('data.locale', $locale)
            ->assertJsonPath('data.learning_authority_digest', $authority)
            ->assertJsonPath('response_state.learning_authority_digest', $authority)
            ->assertJsonPath('response_state.orphaned.count', 1);
        $state = $response->json('response_state');
        if ($priorState !== null) {
            expect($state)->toBe($priorState);
        }
        $priorState = $state;
        expect($row->fresh()->getRawOriginal('form_data'))->toBe($original);
        expect(app(LearningAuthorizationAuthority::class))->toBeInstanceOf(DenyLearningAuthorizationAuthority::class);
        expect(app(LearningAuthorizationAuthority::class)->references('synthetic:learning'))->toBeNull();
    }

    $packet = ['schema_version' => 'datapoint-feedback-v1', 'authority_digest' => $authority,
        'reviewed_datapoint_ids' => ['BP-1_01'], 'decisions' => [[
            'datapoint_id' => 'BP-1_01', 'relevant' => false, 'selected_to_answer' => true,
            'reason_codes' => ['scope'], 'note' => 'Texto libre unchanged',
        ]]];
    $this->putJson('/api/esrs-datapoints/responses', [
        'expected_revision' => 0,
        'responses' => [['datapoint_id' => 'BP-1_01', 'status' => 'draft', 'value' => '=1+1']],
        'learning_feedback' => $packet,
    ])->assertOk()->assertJsonPath('data.learning_feedback', $packet)
        ->assertJsonPath('data.orphaned.count', 1)->assertJsonPath('data.revision', 1);
    $saved = $row->fresh()->getRawOriginal('form_data');
    $this->putJson('/api/esrs-datapoints/responses', [
        'expected_revision' => 0, 'responses' => [], 'learning_feedback' => $packet,
    ])->assertConflict();
    expect($row->fresh()->getRawOriginal('form_data'))->toBe($saved);
});

it('retains P8 reviewed authority and source clocks across locale changes and rejects shrinking or duplicated evidence', function () {
    expect(DB::connection()->getDatabaseName())->toBe(':memory:');
    config(['services.learning_p8_source_clock.enabled' => true]);
    $topics = [EsrsTopic::where('esrs_code', 'E2')->firstOrFail()->id,
        EsrsTopic::where('esrs_code', 'S1')->firstOrFail()->id];
    expect($topics)->toHaveCount(2);
    $row = Characterization::factory()->create([
        'user_id' => $this->user->id, 'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => $topics,
    ]);
    $request = [
        'expected_revision' => 0, 'confirmed_topic_ids' => [], 'reviewed_topic_ids' => $topics,
        'universe_attestation' => ['version' => 1, 'reviewed_universe' => true, 'mode' => 'direct'],
        'change_reasons' => [$topics[0] => ['scope_change'], $topics[1] => ['scope_change']],
    ];
    $this->withSession(['app_locale' => 'en'])->putJson('/api/materiality-confirmation', $request)
        ->assertOk()->assertJsonPath('data.confirmation.reviewed_topic_ids', $topics)
        ->assertJsonPath('data.confirmation.universe_attestation', $request['universe_attestation']);
    $clock = new LearningP8SourceRevisionClock;
    $header = $clock->current($row->id);
    expect($header)->not->toBeNull();
    $saved = $row->fresh()->getRawOriginal('form_data');
    $this->putJson('/api/locale', ['locale' => 'es'])->assertOk();
    $this->getJson('/api/materiality-confirmation')->assertOk()
        ->assertJsonPath('data.confirmation.reviewed_topic_ids', $topics);
    expect($clock->current($row->id))->toBe($header);
    $request['expected_revision'] = 1;
    $request['reviewed_topic_ids'] = [$topics[0]];
    $this->putJson('/api/materiality-confirmation', $request)->assertUnprocessable();
    $request['reviewed_topic_ids'] = $topics;
    $request['change_reasons'][$topics[0]] = ['scope_change', 'scope_change'];
    $response = $this->putJson('/api/materiality-confirmation', $request)->assertUnprocessable();
    expect($response->json('errors')['change_reasons.'.$topics[0].'.0'][0])
        ->toBe('Un tema no puede contener motivos de cambio duplicados.');
    expect($row->fresh()->getRawOriginal('form_data'))->toBe($saved);
    expect($clock->current($row->id))->toBe($header);
});
