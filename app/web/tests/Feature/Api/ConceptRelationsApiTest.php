<?php

use App\Models\Characterization;
use App\Models\User;
use App\Repositories\ConceptRelationsRepository;

beforeEach(function () {
    config(['services.private_dev.auto_login' => false, 'services.concept_relations.enabled' => false]);
    $this->seed(\Database\Seeders\EsrsTopicSeeder::class);
    $this->owner = User::factory()->create();
    $this->viewer = User::factory()->create();
    $source = (new ConceptRelationsRepository())->load()['topic_relations'][0]['source'];
    $this->characterization = Characterization::factory()->create([
        'user_id' => $this->owner->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$source],
        'form_data' => ['materiality_confirmation' => ['confirmed_topic_ids' => [$source]]],
    ]);
});

it('preserves authentication and current-user scope when enabled', function () {
    config(['services.concept_relations.enabled' => true]);
    $this->getJson('/api/materiality-confirmation')->assertUnauthorized();
    $this->getJson('/api/esrs-datapoints')->assertUnauthorized();
    $this->actingAs($this->viewer)->getJson('/api/materiality-confirmation')->assertOk()->assertJsonPath('data', null);
    $this->actingAs($this->viewer)->getJson('/api/esrs-datapoints')->assertOk()->assertJsonPath('data', null);
});

it('adds localized P8 hints only when enabled without altering decisions', function () {
    $this->withSession(['app_locale' => 'es']);
    $off = $this->actingAs($this->owner)->getJson('/api/materiality-confirmation')->assertOk()->json('data');
    expect($off['topics'][0])->not->toHaveKey('often_reported_with');
    config(['services.concept_relations.enabled' => true]);
    $on = $this->getJson('/api/materiality-confirmation')->assertOk()->json('data');
    expect($on['confirmed_topic_ids'])->toBe($off['confirmed_topic_ids']);
    expect($on['confirmation'])->toBe($off['confirmation']);
    expect($on['topics'][0]['often_reported_with'])->not->toBeEmpty();
    foreach ($on['topics'][0]['often_reported_with'] as $hint) {
        $target = \App\Models\EsrsTopic::findOrFail($hint['id']);
        expect($hint['label'])->toBe($target->subtopic_es ?: $target->subtheme_es ?: $target->theme_es);
    }
    $this->withSession(['app_locale' => 'en']);
    $english = $this->getJson('/api/materiality-confirmation')->assertOk()->json('data.topics.0.often_reported_with');
    foreach ($english as $hint) {
        $target = \App\Models\EsrsTopic::findOrFail($hint['id']);
        expect($hint['label'])->toBe($target->subtopic_en ?: $target->subtheme_en ?: $target->theme_en);
    }
});

it('keeps P9 authority and responses unchanged and confines localized siblings to scope', function () {
    $this->withSession(['app_locale' => 'es']);
    $off = $this->actingAs($this->owner)->getJson('/api/esrs-datapoints')->assertOk()->json();
    $offRows = collect($off['data']['blocks'])->flatMap(fn ($block) => $block['datapoints'] ?? []);
    foreach ($offRows as $row) expect($row)->not->toHaveKey('related_datapoints');
    config(['services.concept_relations.enabled' => true]);
    $on = $this->getJson('/api/esrs-datapoints')->assertOk()->json();
    expect($on['data']['learning_authority_digest'])->toBe($off['data']['learning_authority_digest']);
    expect($on['data']['mapping_snapshot_digest'])->toBe($off['data']['mapping_snapshot_digest']);
    expect($on['response_state'])->toBe($off['response_state']);
    $rows = collect($on['data']['blocks'])->flatMap(fn ($block) => $block['datapoints'] ?? []);
    $allowed = $rows->pluck('id')->all();
    $count = 0;
    foreach ($rows as $row) {
        expect($row)->toHaveKey('related_datapoints');
        expect(count($row['related_datapoints']))->toBeLessThanOrEqual(5);
        foreach ($row['related_datapoints'] as $hint) {
            $count++;
            expect($allowed)->toContain($hint['id']);
            expect($hint['id'])->not->toBe($row['id']);
            expect($hint['label'])->toBe(app(\App\Support\EsrsDisplayCatalogue::class)->claimLabel($hint['id'], 'es'));
        }
    }
    expect($count)->toBeGreaterThan(0);
});

it('leaves the workflow available with empty hints if the artifact is unavailable', function () {
    config(['services.concept_relations.enabled' => true]);
    app()->instance(ConceptRelationsRepository::class, new ConceptRelationsRepository('/nonexistent/concept-relations.json'));
    $state = $this->actingAs($this->owner)->getJson('/api/materiality-confirmation')->assertOk()->json('data');
    expect($state['topics'][0]['often_reported_with'])->toBe([]);
    $corpus = $this->getJson('/api/esrs-datapoints')->assertOk()->json('data');
    foreach ($corpus['blocks'] as $block) {
        foreach ($block['datapoints'] ?? [] as $row) expect($row['related_datapoints'])->toBe([]);
    }
});


it('returns P8 hints for a saved user-added topic outside the P6 proposal', function () {
    $this->characterization->update(['esrs_topic_ids' => []]);
    $saved = $this->characterization->form_data['materiality_confirmation']['confirmed_topic_ids'];
    config(['services.concept_relations.enabled' => true]);
    $state = $this->actingAs($this->owner)->getJson('/api/materiality-confirmation')->assertOk()->json('data');
    expect($state['p6_topic_ids'])->toBe([]);
    expect($state['confirmed_topic_ids'])->toBe($saved);
    $topic = collect($state['topics'])->firstWhere('id', $saved[0]);
    expect($topic['often_reported_with'])->not->toBeEmpty();
});
