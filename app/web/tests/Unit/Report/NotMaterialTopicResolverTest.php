<?php

use App\Models\Characterization;
use App\Models\EsrsTopic;
use App\Models\User;
use App\Services\Report\NotMaterialTopicResolver;

uses(Tests\TestCase::class, \Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\EsrsTopicSeeder::class);
    $this->user = User::factory()->create();
    $this->e2 = EsrsTopic::where('esrs_code', 'E2')->firstOrFail();
    $this->e3 = EsrsTopic::where('esrs_code', 'E3')->firstOrFail();
    $this->e4 = EsrsTopic::where('esrs_code', 'E4')->firstOrFail();
});

function resolverCharacterization(User $user, array $liveTopicIds, array $confirmation): Characterization
{
    return Characterization::factory()->create([
        'user_id' => $user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'nace_code' => 'A',
        'esrs_topic_ids' => $liveTopicIds,
        'form_data' => ['materiality_confirmation' => $confirmation],
    ]);
}

it('infers omissions from the stored P6 snapshot delta, never from the live proposal', function () {
    // Live proposal has drifted to include E4; the user never saw it.
    $c = resolverCharacterization($this->user, [$this->e2->id, $this->e3->id, $this->e4->id], [
        'confirmed_topic_ids' => [$this->e2->id],
        'p6_snapshot' => ['topic_ids' => [$this->e2->id, $this->e3->id], 'captured_at' => '2026-01-01T00:00:00Z'],
        'confirmed_at' => '2026-01-01T00:00:00Z',
    ]);

    $result = app(NotMaterialTopicResolver::class)->resolve($c);

    expect(collect($result['omitted_topics'])->pluck('topic_id')->all())->toBe([$this->e3->id]);
    expect($result['omitted_topics'][0]['evidence_grade'])->toBe(NotMaterialTopicResolver::GRADE_INFERRED);
    expect($result['is_stale'])->toBeTrue();
    expect($result['inferred_omissions_status'])->toBe(NotMaterialTopicResolver::STATUS_DETERMINABLE);
});

it('asserts direct guided verdicts even for topics outside the snapshot, and direct beats inferred', function () {
    $c = resolverCharacterization($this->user, [$this->e2->id], [
        'confirmed_topic_ids' => [$this->e2->id],
        'p6_snapshot' => ['topic_ids' => [$this->e2->id, $this->e3->id], 'captured_at' => '2026-01-01T00:00:00Z'],
        'guided_answers' => [
            // e3 is BOTH in the snapshot delta AND directly judged -> direct wins
            (string) $this->e3->id => ['final_result' => 'no_material'],
            // e4 was never proposed by P6 at all -> still a stored human verdict
            (string) $this->e4->id => ['final_result' => 'no_material'],
        ],
    ]);

    $result = app(NotMaterialTopicResolver::class)->resolve($c);
    $byId = collect($result['omitted_topics'])->keyBy('topic_id');

    expect($byId->keys()->sort()->values()->all())->toBe(collect([$this->e3->id, $this->e4->id])->sort()->values()->all());
    expect($byId[$this->e3->id]['evidence_grade'])->toBe(NotMaterialTopicResolver::GRADE_DIRECT);
    expect($byId[$this->e4->id]['evidence_grade'])->toBe(NotMaterialTopicResolver::GRADE_DIRECT);
});

it('excludes contradictory topics and records them', function () {
    $c = resolverCharacterization($this->user, [$this->e2->id], [
        'confirmed_topic_ids' => [$this->e2->id],
        'p6_snapshot' => ['topic_ids' => [$this->e2->id], 'captured_at' => '2026-01-01T00:00:00Z'],
        'guided_answers' => [(string) $this->e2->id => ['final_result' => 'no_material']],
    ]);

    $result = app(NotMaterialTopicResolver::class)->resolve($c);

    expect($result['omitted_topics'])->toBe([]);
    expect($result['contradictory_topic_ids'])->toBe([$this->e2->id]);
});

it('fails closed when the snapshot is absent: no inferred omissions, status declared', function () {
    $c = resolverCharacterization($this->user, [$this->e2->id, $this->e3->id], [
        'confirmed_topic_ids' => [$this->e2->id],
    ]);

    $result = app(NotMaterialTopicResolver::class)->resolve($c);

    expect($result['omitted_topics'])->toBe([]);
    expect($result['inferred_omissions_status'])->toBe(NotMaterialTopicResolver::STATUS_NOT_DETERMINABLE);
    expect($result['is_stale'])->toBeFalse();
});

it('records unresolvable topic ids instead of dropping them silently', function () {
    $c = resolverCharacterization($this->user, [$this->e2->id], [
        'confirmed_topic_ids' => [$this->e2->id],
        'p6_snapshot' => ['topic_ids' => [$this->e2->id, 999999], 'captured_at' => '2026-01-01T00:00:00Z'],
    ]);

    $result = app(NotMaterialTopicResolver::class)->resolve($c);

    expect($result['omitted_topics'])->toBe([]);
    expect($result['unresolved_omitted_topic_ids'])->toBe([999999]);
});

it('carries change reasons and the free-text note for the evidence bundle', function () {
    $c = resolverCharacterization($this->user, [$this->e2->id, $this->e3->id], [
        'confirmed_topic_ids' => [$this->e2->id],
        'p6_snapshot' => ['topic_ids' => [$this->e2->id, $this->e3->id], 'captured_at' => '2026-01-01T00:00:00Z'],
        'change_reasons' => [(string) $this->e3->id => ['threshold', 'new_data']],
        'change_reason_notes' => [(string) $this->e3->id => 'Nota interna del equipo'],
    ]);

    $topic = app(NotMaterialTopicResolver::class)->resolve($c)['omitted_topics'][0];

    expect($topic['change_reasons'])->toBe(['threshold', 'new_data']);
    expect($topic['change_reason_note'])->toBe('Nota interna del equipo');
});

it('reports not_confirmed when no confirmation exists', function () {
    $c = resolverCharacterization($this->user, [$this->e2->id], []);

    expect(app(NotMaterialTopicResolver::class)->resolve($c)['inferred_omissions_status'])
        ->toBe(NotMaterialTopicResolver::STATUS_NOT_CONFIRMED);
});

it('degrades a present-but-null confirmed_topic_ids to empty instead of crashing', function () {
    $c = resolverCharacterization($this->user, [$this->e2->id], [
        'confirmed_topic_ids' => null,
        'p6_snapshot' => ['topic_ids' => [$this->e2->id], 'captured_at' => '2026-01-01T00:00:00Z'],
        'confirmed_at' => '2026-01-01T00:00:00Z',
    ]);

    $result = app(NotMaterialTopicResolver::class)->resolve($c);

    expect(collect($result['omitted_topics'])->pluck('topic_id')->all())->toBe([$this->e2->id]);
    expect($result['inferred_omissions_status'])->not->toBe(NotMaterialTopicResolver::STATUS_NOT_CONFIRMED);
});

it('degrades a non-array esrs_topic_ids to empty instead of crashing', function () {
    $c = resolverCharacterization($this->user, [$this->e2->id], [
        'confirmed_topic_ids' => [$this->e2->id],
    ]);

    \Illuminate\Support\Facades\DB::table('characterizations')
        ->where('id', $c->id)
        ->update(['esrs_topic_ids' => json_encode('not-an-array')]);

    $result = app(NotMaterialTopicResolver::class)->resolve($c->fresh());

    expect($result['is_stale'])->toBeFalse();
});

it('degrades a non-array guided_answers to empty instead of crashing, without changing the declared status', function () {
    $confirmed = resolverCharacterization($this->user, [$this->e2->id, $this->e3->id], [
        'confirmed_topic_ids' => [$this->e2->id],
        'p6_snapshot' => ['topic_ids' => [$this->e2->id, $this->e3->id], 'captured_at' => '2026-01-01T00:00:00Z'],
        'confirmed_at' => '2026-01-01T00:00:00Z',
        'guided_answers' => [],
    ]);
    $baseline = app(NotMaterialTopicResolver::class)->resolve($confirmed);

    $corrupt = resolverCharacterization(User::factory()->create(), [$this->e2->id, $this->e3->id], [
        'confirmed_topic_ids' => [$this->e2->id],
        'p6_snapshot' => ['topic_ids' => [$this->e2->id, $this->e3->id], 'captured_at' => '2026-01-01T00:00:00Z'],
        'confirmed_at' => '2026-01-01T00:00:00Z',
        'guided_answers' => 'corrupt',
    ]);

    $result = app(NotMaterialTopicResolver::class)->resolve($corrupt);

    expect(collect($result['omitted_topics'])->pluck('topic_id')->all())->toBe([$this->e3->id]);
    expect($result['omitted_topics'][0]['evidence_grade'])->toBe(NotMaterialTopicResolver::GRADE_INFERRED);
    expect($result['inferred_omissions_status'])->toBe(NotMaterialTopicResolver::STATUS_DETERMINABLE);
    expect($result['inferred_omissions_status'])->toBe($baseline['inferred_omissions_status']);
});

it('labels topics distinguishably, because theme_es repeats across a standard', function () {
    // All 5 E3 topics share theme_es "Agua y recursos marinos"; 4 also share subtheme "Agua".
    $waterTopics = EsrsTopic::where('esrs_code', 'E3')->orderBy('id')->take(2)->get();
    expect($waterTopics[0]->theme_es)->toBe($waterTopics[1]->theme_es);

    $c = resolverCharacterization($this->user, [$this->e2->id], [
        'confirmed_topic_ids' => [$this->e2->id],
        'p6_snapshot' => [
            'topic_ids' => [$this->e2->id, $waterTopics[0]->id, $waterTopics[1]->id],
            'captured_at' => '2026-01-01T00:00:00Z',
        ],
    ]);

    $labels = collect(app(NotMaterialTopicResolver::class)->resolve($c)['omitted_topics'])->pluck('label');

    expect($labels)->toHaveCount(2);
    expect($labels->unique())->toHaveCount(2);          // never the same sentence twice
    expect($labels[0])->toStartWith('Agua y recursos marinos: ');
});
