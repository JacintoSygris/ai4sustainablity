<?php

use App\Models\Characterization;
use App\Models\ReportingFact;
use App\Models\User;
use App\Models\XbrlIdentificationRun;
use App\Models\XbrlIdentificationReview;
use App\Services\Report\XbrlRuntimeContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

require_once __DIR__.'/../../Support/xbrl-runtime-fixture.php';

beforeEach(function () {
    config(['services.private_dev.auto_login' => false, 'xbrl_suggestions.runtime_enabled' => true,
        'services.characterization.api.base_url' => 'http://127.0.0.1:8001', 'services.characterization.api.token' => 'synthetic-token']);
    $this->user = User::factory()->create();
    $this->company = Characterization::factory()->create(['user_id' => $this->user->id, 'esrs_topic_ids' => [], 'form_data' => [], 'result_data' => []]);
    $this->mock(XbrlRuntimeContext::class, function ($mock) { $mock->shouldReceive('capture')->andReturnUsing(fn () => xbrlRuntimeTestContext()); });
    $this->runtimeResponse = fn ($request) => xbrlRuntimeTestResponse($request->data());
    Http::fake(fn ($request) => Http::response(($this->runtimeResponse)($request)));
    $this->payload = ['indicator' => xbrlRuntimeTestInput()['indicator']];
});

function xbrlApiReview(array $run, array $overrides = []): array
{
    return array_replace(['candidate_qname' => $run['response_json']['candidates'][0]['qname'],
        'decision' => 'accepted', 'reason' => 'Synthetic review with explicit human decision.', 'expected_sequence' => 0], $overrides);
}

function xbrlApiNumericFact(Characterization $company): ReportingFact
{
    return ReportingFact::create(['characterization_id' => $company->id, 'fact_id' => 'rf_synthetic_numeric',
        'schema_version' => ReportingFact::SCHEMA_VERSION, 'profile_id' => ReportingFact::PROFILE_ID,
        'datapoint_id' => 'BP-1_01', 'applicability' => 'applicable', 'value_type' => 'number',
        'value' => ['number' => '12'], 'unit' => 'pure', 'dimensions' => [], 'nil' => false, 'evidence_refs' => [],
        'provenance' => 'api', 'approval_status' => 'review_required', 'blocking_reasons' => []]);
}

it('rejects contradictory linked fact type and unit before inference', function (array $override) {
    $fact = xbrlApiNumericFact($this->company);
    $payload = [...$this->payload, 'reporting_fact_id' => $fact->id,
        'indicator' => [...$this->payload['indicator'], ...$override]];
    $this->actingAs($this->user)->postJson('/api/report/xbrl-identifications', $payload)->assertUnprocessable();
    Http::assertNothingSent();
    expect(XbrlIdentificationRun::count())->toBe(0);
})->with([[['value_type' => 'text']], [['unit' => 'EUR']]]);

it('uses linked fact metadata through independent validation and acceptance', function (string $type, bool $eligible) {
    $fact = xbrlApiNumericFact($this->company);
    $context = xbrlRuntimeTestContext();
    $qname = xbrlRuntimeTestResponse()['candidates'][0]['qname'];
    $context['verified_taxonomy'] = true;
    $context['validation_package_sha256'] = str_repeat('e', 64);
    $context['metadata'][$qname] = ['type' => '{http://www.xbrl.org/2003/instance}'.$type,
        'period_type' => 'duration', 'abstract' => false];
    $this->instance(XbrlRuntimeContext::class, new class($context) extends XbrlRuntimeContext {
        public function __construct(private array $context) {}
        public function capture(): array { return $this->context; }
    });
    $payload = [...$this->payload, 'reporting_fact_id' => $fact->id,
        'indicator' => [...$this->payload['indicator'], 'period_type' => 'duration']];
    $run = $this->actingAs($this->user)->postJson('/api/report/xbrl-identifications', $payload)->assertCreated()->json('data');
    expect($run['input_json']['indicator']['value_type'])->toBe('number')
        ->and($run['input_json']['indicator']['unit'])->toBe('pure')
        ->and($run['response_json']['abstained'])->toBe(! $eligible)
        ->and($run['response_json']['service_response']['abstained'])->toBeFalse();
    Http::assertSent(fn ($request) => $request['indicator']['value_type'] === 'number'
        && $request['indicator']['unit'] === 'pure' && ! isset($request['indicator']['value']));
    $review = $this->postJson('/api/report/xbrl-identifications/'.$run['id'].'/reviews', xbrlApiReview($run));
    if ($eligible) $review->assertOk();
    else $review->assertUnprocessable();
    expect(XbrlIdentificationReview::count())->toBe($eligible ? 1 : 0)
        ->and($fact->fresh()->approval_status)->toBe('review_required');
})->with([['stringItemType', false], ['decimalItemType', true]]);

it('rejects changed linked fact compatibility after inference before persistence', function () {
    $fact = xbrlApiNumericFact($this->company);
    $this->runtimeResponse = function ($request) use ($fact) {
        $fact->update(['unit' => 'EUR']);
        return xbrlRuntimeTestResponse($request->data());
    };
    $this->actingAs($this->user)->postJson('/api/report/xbrl-identifications',
        [...$this->payload, 'reporting_fact_id' => $fact->id])->assertConflict();
    expect(XbrlIdentificationRun::count())->toBe(0);
});

it('persists partial filtering abstention and blocks acceptance of a compatible survivor', function (array $scores, string $reason) {
    [$context] = xbrlRuntimePartialFilteringFixture($scores);
    $this->instance(XbrlRuntimeContext::class, new class($context) extends XbrlRuntimeContext {
        public function __construct(private array $context) {}
        public function capture(): array { return $this->context; }
    });
    $this->runtimeResponse = fn ($request) => xbrlRuntimePartialFilteringFixture($scores, $request->data())[1];
    $payload = ['indicator' => [...$this->payload['indicator'], 'value_type' => 'text', 'period_type' => 'duration']];
    $run = $this->actingAs($this->user)->postJson('/api/report/xbrl-identifications', $payload)->assertCreated()->json('data');
    $stored = XbrlIdentificationRun::findOrFail($run['id'])->response_json;
    expect($stored['abstained'])->toBeTrue()
        ->and($stored['reason_codes'])->toBe([$reason])
        ->and($stored['service_response']['abstained'])->toBeFalse()
        ->and($stored['candidates'][1]['validation_status'])->toBe('verified_taxonomy');
    $this->postJson('/api/report/xbrl-identifications/'.$run['id'].'/reviews', xbrlApiReview($run,
        ['candidate_qname' => $stored['candidates'][1]['qname']]))->assertUnprocessable();
    expect(XbrlIdentificationReview::count())->toBe(0);
})->with([
    'score' => [[0.90, 0.40], 'below_threshold'],
    'margin' => [[0.90, 0.70, 0.68], 'insufficient_margin'],
]);

it('requires auth and the independent default off flag', function () {
    $this->getJson('/api/report/xbrl-identifications')->assertUnauthorized();
    $this->postJson('/api/report/xbrl-identifications', $this->payload)->assertUnauthorized();
    config(['xbrl_suggestions.runtime_enabled' => false]);
    $this->actingAs($this->user)->getJson('/api/report/xbrl-identifications')->assertNotFound();
    $this->postJson('/api/report/xbrl-identifications', $this->payload)->assertNotFound();
    $this->postJson('/api/report/xbrl-identifications/1/reviews', [])->assertNotFound();
    $this->getJson('/api/auth/session')->assertJsonPath('data.xbrl_runtime_identification_enabled', false);
    Http::assertNothingSent();
});

it('keeps web session csrf verification and separate rate limits on every route', function () {
    foreach (['index' => 'throttle:30,1,xbrl-runtime-read', 'store' => 'throttle:5,1,xbrl-runtime-identify', 'review' => 'throttle:20,1,xbrl-runtime-review'] as $action => $throttle) {
        expect(Route::getRoutes()->getByName('api.report.xbrl-identifications.'.$action)->gatherMiddleware())
            ->toContain('web', 'private-dev-user', 'auth', 'verified.required', $throttle);
    }
    config(['services.auth_hardening.require_email_verification' => true]);
    $this->user->forceFill(['email_verified_at' => null])->save();
    $this->actingAs($this->user)->postJson('/api/report/xbrl-identifications', $this->payload)->assertStatus(409);
    Http::assertNothingSent();
});

it('enforces the csrf middleware outside the test bypass', function () {
    $this->actingAs($this->user);
    $this->app->instance('env', 'local');
    $this->postJson('/api/report/xbrl-identifications', $this->payload)->assertStatus(419);
    Http::assertNothingSent();
});

it('bounds validation before inference and rejects browser identity and replacement results', function (array $change) {
    $this->actingAs($this->user)->postJson('/api/report/xbrl-identifications', array_replace($this->payload, $change))->assertUnprocessable();
    Http::assertNothingSent();
})->with([
    [['user_id' => 1]], [['response_json' => []]], [['subject_key' => 'custom:forged']], [['taxonomy_profile' => 'forged']],
    [['indicator' => ['label' => ' ', 'description' => '', 'language' => 'en']]],
    [['indicator' => ['label' => 'x', 'description' => '', 'language' => 'en', 'value' => 12]]],
    [['indicator' => ['label' => 'x', 'description' => '', 'language' => 'en', 'dr' => 'foreign']]],
    [['indicator' => ['label' => 'water consumption', 'description' => '', 'language' => 'en', 'standard' => 'E1', 'dr' => 'E1-99']]],
    [['indicator' => ['label' => 'water consumption', 'description' => '', 'language' => 'en', 'dr' => 'S2-6']]],
    [['indicator' => ['label' => 'water consumption', 'description' => '', 'language' => 'en', 'standard' => 'E1', 'dr' => 'E3-4']]],
]);

it('persists verbatim inputs authenticated reviewer utc time and append only sequence', function () {
    $this->travelTo(\Carbon\Carbon::parse('2026-10-09T16:00:00Z'));
    $before = $this->company->form_data;
    $run = $this->actingAs($this->user)->postJson('/api/report/xbrl-identifications', $this->payload)->assertCreated()->json('data');
    expect($run['subject_key'])->toStartWith('custom:')
        ->and($run['input_json']['indicator']['label'])->toBe($this->payload['indicator']['label']);
    $reviewed = $this->postJson('/api/report/xbrl-identifications/'.$run['id'].'/reviews', xbrlApiReview($run))->assertOk()->json('data');
    expect($reviewed['reviews'][0]['reviewer_id'])->toBe($this->user->id)
        ->and($reviewed['reviews'][0]['created_at'])->toBe('2026-10-09T16:00:00.000000Z')
        ->and($this->company->fresh()->form_data)->toBe($before)->and(ReportingFact::count())->toBe(0);
    $this->postJson('/api/report/xbrl-identifications/'.$run['id'].'/reviews', xbrlApiReview($run, ['reviewer_id' => 1]))->assertUnprocessable();
    expect(fn () => XbrlIdentificationReview::first()->update(['reason' => 'rewrite']))->toThrow(DomainException::class);
    $this->assertDatabaseHas('report_audit_events', ['event_type' => 'xbrl_identification_reviewed']);
});

it('scopes runs facts and paginated history to the authenticated characterization', function () {
    $run = $this->actingAs($this->user)->postJson('/api/report/xbrl-identifications', $this->payload)->assertCreated()->json('data');
    $other = User::factory()->create();
    Characterization::factory()->create(['user_id' => $other->id]);
    $this->actingAs($other)->postJson('/api/report/xbrl-identifications/'.$run['id'].'/reviews', xbrlApiReview($run))->assertNotFound();
    $this->getJson('/api/report/xbrl-identifications')->assertJsonPath('data.runs', []);
    $this->getJson('/api/report/xbrl-identifications?per_page=51')->assertUnprocessable();
    $this->postJson('/api/report/xbrl-identifications', [...$this->payload, 'reporting_fact_id' => 987654321])->assertNotFound();
    $this->postJson('/api/report/xbrl-identifications', [...$this->payload, 'datapoint_id' => 'UNKNOWN'])->assertUnprocessable();
});

it('rejects forged candidates and abstained acceptances', function () {
    $this->runtimeResponse = fn ($request) => [...xbrlRuntimeTestResponse($request->data()), 'abstained' => true, 'reason_codes' => ['below_threshold']];
    $run = $this->actingAs($this->user)->postJson('/api/report/xbrl-identifications', $this->payload)->assertCreated()->json('data');
    expect(XbrlIdentificationRun::findOrFail($run['id'])->response_json['abstained'])->toBeTrue();
    $this->postJson('/api/report/xbrl-identifications/'.$run['id'].'/reviews', xbrlApiReview($run))->assertUnprocessable();
    $this->postJson('/api/report/xbrl-identifications/'.$run['id'].'/reviews', xbrlApiReview($run, ['candidate_qname' => 'esrs:Forged']))->assertUnprocessable();
    expect(XbrlIdentificationReview::count())->toBe(0);
});

it('serializes decisions across runs for one subject and requires previous rejection', function () {
    $payload = [...$this->payload, 'datapoint_id' => 'BP-1_01'];
    $a = $this->actingAs($this->user)->postJson('/api/report/xbrl-identifications', $payload)->assertCreated()->json('data');
    $b = $this->postJson('/api/report/xbrl-identifications', $payload)->assertCreated()->json('data');
    $this->postJson('/api/report/xbrl-identifications/'.$a['id'].'/reviews', xbrlApiReview($a))->assertOk();
    $this->postJson('/api/report/xbrl-identifications/'.$b['id'].'/reviews', xbrlApiReview($b))->assertConflict();
    $this->postJson('/api/report/xbrl-identifications/'.$a['id'].'/reviews', xbrlApiReview($a, ['decision' => 'rejected']))->assertConflict();
    $this->postJson('/api/report/xbrl-identifications/'.$a['id'].'/reviews', xbrlApiReview($a, ['decision' => 'rejected', 'expected_sequence' => 1]))->assertOk();
    $this->postJson('/api/report/xbrl-identifications/'.$b['id'].'/reviews', xbrlApiReview($b))->assertOk();
});

it('rejects stale context after inference without persisting a run', function () {
    $this->runtimeResponse = function ($request) {
        $this->company->update(['nace_code' => 'C10']);
        return xbrlRuntimeTestResponse($request->data());
    };
    $this->actingAs($this->user)->postJson('/api/report/xbrl-identifications', $this->payload)->assertConflict();
    expect(XbrlIdentificationRun::count())->toBe(0);
});

it('rejects stale acceptance after context or model replacement', function () {
    $run = $this->actingAs($this->user)->postJson('/api/report/xbrl-identifications', $this->payload)->assertCreated()->json('data');
    $this->company->update(['nace_code' => 'C10']);
    $this->postJson('/api/report/xbrl-identifications/'.$run['id'].'/reviews', xbrlApiReview($run))->assertConflict();
    expect(XbrlIdentificationReview::count())->toBe(0);
});

it('throttles inference separately from history', function () {
    $this->actingAs($this->user);
    for ($i = 0; $i < 5; $i++) $this->postJson('/api/report/xbrl-identifications', $this->payload)->assertCreated();
    $this->postJson('/api/report/xbrl-identifications', $this->payload)->assertStatus(429);
    $this->getJson('/api/report/xbrl-identifications')->assertOk();
});

it('cascades private records on account deletion', function () {
    $run = $this->actingAs($this->user)->postJson('/api/report/xbrl-identifications', $this->payload)->assertCreated()->json('data');
    $this->postJson('/api/report/xbrl-identifications/'.$run['id'].'/reviews', xbrlApiReview($run))->assertOk();
    $this->user->delete();
    expect(XbrlIdentificationRun::count())->toBe(0)->and(XbrlIdentificationReview::count())->toBe(0);
});

it('rejects changed model identity at acceptance', function () {
    $run = $this->actingAs($this->user)->postJson('/api/report/xbrl-identifications', $this->payload)->assertCreated()->json('data');
    $replacement = xbrlRuntimeTestContext();
    $replacement['identity']['artifact_sha256'] = str_repeat('d', 64);
    $this->instance(XbrlRuntimeContext::class, new class($replacement) extends XbrlRuntimeContext {
        public function __construct(private array $replacement) {}
        public function capture(): array { return $this->replacement; }
    });
    $this->postJson('/api/report/xbrl-identifications/'.$run['id'].'/reviews', xbrlApiReview($run))->assertConflict();
});

it('rejects a foreign existing fact rather than accepting its id as a custom indicator', function () {
    $owner = User::factory()->create();
    $other = Characterization::factory()->create(['user_id' => $owner->id]);
    $fact = ReportingFact::create(['characterization_id' => $other->id, 'fact_id' => 'rf_synthetic_foreign',
        'schema_version' => ReportingFact::SCHEMA_VERSION, 'profile_id' => ReportingFact::PROFILE_ID,
        'datapoint_id' => 'BP-1_01', 'applicability' => 'applicable', 'value_type' => 'text',
        'value' => ['text' => 'Synthetic'], 'dimensions' => [], 'nil' => false, 'evidence_refs' => [],
        'provenance' => 'api', 'approval_status' => 'review_required', 'blocking_reasons' => []]);
    $this->actingAs($this->user)->postJson('/api/report/xbrl-identifications', [...$this->payload, 'reporting_fact_id' => $fact->id])->assertNotFound();
    Http::assertNothingSent();
});

it('bounds raw input bodies before inference', function () {
    $this->actingAs($this->user)->call('POST', '/api/report/xbrl-identifications', [], [], [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode(['indicator' => ['label' => str_repeat('x', 33000)]]))->assertStatus(413);
    Http::assertNothingSent();
});
