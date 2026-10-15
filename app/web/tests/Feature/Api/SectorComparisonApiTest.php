<?php

use App\Models\Characterization;
use App\Models\User;
use App\Services\SectorComparisonGateway;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Factory as HttpFactory;

beforeEach(function () {
    config([
        'services.private_dev.auto_login' => false,
        'services.sector_comparison.enabled' => true,
        'services.characterization.api.base_url' => 'http://127.0.0.1:8001',
        'services.characterization.api.token' => 'synthetic-test-token',
    ]);
    $this->seed(\Database\Seeders\EsrsTopicSeeder::class);
    $this->seed(\Database\Seeders\NaceCodeSeeder::class);
    $this->user = User::factory()->create();
    $this->characterization = Characterization::factory()->create([
        'user_id' => $this->user->id, 'nace_code' => 'K',
        'status' => Characterization::STATUS_COMPLETED, 'esrs_topic_ids' => [1, 2],
        'form_data' => ['materiality_confirmation' => ['revision' => 2, 'confirmed_topic_ids' => [1], 'reviewed_topic_ids' => [1, 2]]],
    ]);
    $this->url = '/api/characterizations/'.$this->characterization->id.'/sector-comparison';
    $this->sectorFixture = json_decode(file_get_contents(base_path('tests/Fixtures/sector-comparison-response.json')), true, 64, JSON_THROW_ON_ERROR);
    Http::swap(new HttpFactory);
    // The gateway consumes and closes each response stream. Give every request
    // its own response, as the real transport does (including repeated reads).
    Http::fake(fn () => Http::response($this->sectorFixture));
});

it('requires authentication verification ownership and bounded IDs', function () {
    $this->get($this->url, ['Accept' => 'application/json'])->assertUnauthorized();
    config(['services.auth_hardening.require_email_verification' => true]);
    $unverified = User::factory()->unverified()->create();
    $this->actingAs($unverified)->get($this->url, ['Accept' => 'application/json'])->assertStatus(409)->assertJsonPath('code', 'email_unverified');
    $this->actingAs(User::factory()->create())->get($this->url, ['Accept' => 'application/json'])->assertNotFound();
    $this->actingAs($this->user)->get('/api/characterizations/9999999999999999/sector-comparison', ['Accept' => 'application/json'])->assertNotFound();
    $this->get('/api/characterizations/0/sector-comparison', ['Accept' => 'application/json'])->assertNotFound();
    Http::assertNothingSent();
});

it('does nothing when disabled and preserves the old P6 P8 contracts', function () {
    config(['services.sector_comparison.enabled' => false]);
    $this->actingAs($this->user)->get($this->url, ['Accept' => 'application/json'])->assertNotFound();
    $this->getJson('/api/materiality-proposal')->assertOk()->assertJsonMissingPath('data.sector_comparison_enabled');
    $this->getJson('/api/materiality-confirmation')->assertOk()->assertJsonMissingPath('data.sector_comparison_enabled');
    Http::assertNothingSent();
});

it('rejects all browser parameters without upstream requests', function () {
    foreach (['sections[]=L', 'owner=2', 'url=http://elsewhere.invalid', 'locale=en', 'topics[]=1'] as $query) {
        $this->actingAs($this->user)->get($this->url.'?'.$query, ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('code', 'sector_comparison_invalid_request');
    }
    Http::assertNothingSent();
});

it('rejects every nonempty GET body independently of query validation', function () {
    $this->actingAs($this->user);
    foreach (['[]', '{}', '{"sections":["L"]}', ' '] as $body) {
        $this->call('GET', $this->url, [], [], [], [
            'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
        ], $body)->assertStatus(422)->assertExactJson(['code' => 'sector_comparison_invalid_request']);
    }
    Http::assertNothingSent();
});

it('serves exact CLI counts without changing decisions revisions or P9 scope', function () {
    $this->actingAs($this->user);
    $attributes = $this->characterization->fresh()->getRawOriginal();
    $p6 = $this->getJson('/api/materiality-proposal')->assertOk()->json('data');
    $p8 = $this->getJson('/api/materiality-confirmation')->assertOk()->json('data');
    expect($p6['sector_comparison_enabled'])->toBeTrue();
    expect($p8['sector_comparison_enabled'])->toBeTrue();
    Http::assertNothingSent(); // Capabilities never query Python.
    $response = $this->get($this->url, ['Accept' => 'application/json'])->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.characterization_id', $this->characterization->id)
        ->assertJsonPath('data.input_fingerprint', SectorComparisonGateway::fingerprint($this->characterization))
        ->assertJsonPath('data.comparisons.0.population_companies', 5)
        ->assertJsonPath('data.global_population_companies', 6)
        ->assertJsonPath('data.comparisons.0.topics.0.sector.n_material', 3)
        ->assertJsonPath('data.comparisons.0.topics.1.source', 'global_fallback')
        ->assertJsonPath('data.comparisons.0.topics.2.proportion', null);
    expect($this->characterization->fresh()->getRawOriginal())->toBe($attributes);
    expect($this->getJson('/api/materiality-proposal')->json('data'))->toBe($p6);
    expect($this->getJson('/api/materiality-confirmation')->json('data'))->toBe($p8); // Includes P9 preview/scope.
    $english = $this->withSession(['app_locale' => 'en'])->get($this->url, ['Accept' => 'application/json'])->assertOk();
    expect($english->json('data'))->toBe($response->json('data'));
    expect($this->characterization->fresh()->getRawOriginal())->toBe($attributes);
    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => array_keys($request->data()) === ['version', 'sections', 'topics'] && $request['sections'] === ['K']);
});

it('hides upstream failure details and fails closed on oversized responses', function () {
    Http::swap(new HttpFactory);
    Http::fake(['*' => Http::response(str_repeat('x', 262145))]);
    $this->actingAs($this->user)->get($this->url, ['Accept' => 'application/json'])->assertStatus(503)->assertJsonPath('code', 'sector_comparison_unavailable')->assertHeader('Retry-After', '60');
    Http::swap(new HttpFactory);
    Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('private detail'));
    $this->get($this->url, ['Accept' => 'application/json'])->assertStatus(503)->assertExactJson(['code' => 'sector_comparison_unavailable']);
});

it('rechecks inputs and ownership after the HTTP call without holding a transaction', function () {
    Http::swap(new HttpFactory);
    $transactionLevel = \Illuminate\Support\Facades\DB::transactionLevel();
    Http::fake(function () use ($transactionLevel) {
        expect(\Illuminate\Support\Facades\DB::transactionLevel())->toBe($transactionLevel);
        $this->characterization->update(['nace_code' => 'L']);
        return Http::response($this->sectorFixture);
    });
    $this->actingAs($this->user)->get($this->url, ['Accept' => 'application/json'])->assertStatus(409)->assertJsonPath('code', 'sector_comparison_inputs_changed');
    Http::swap(new HttpFactory);
    Http::fake(function () {
        $this->characterization->update(['user_id' => User::factory()->create()->id]);
        return Http::response($this->sectorFixture);
    });
    $this->get($this->url, ['Accept' => 'application/json'])->assertNotFound();
});

it('limits reads per authenticated user', function () {
    $this->actingAs($this->user);
    for ($i = 0; $i < 30; $i++) {
        $this->get($this->url, ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.comparisons.0.topics.0.sector', ['n_scored' => 5, 'n_material' => 3]);
    }
    $this->get($this->url, ['Accept' => 'application/json'])->assertStatus(429);
    Http::assertSentCount(30);

    // Exhausting one account's bucket must not throttle another account.
    $other = User::factory()->create();
    $otherCharacterization = Characterization::factory()->create([
        'user_id' => $other->id, 'nace_code' => 'K',
    ]);
    $this->actingAs($other)->get('/api/characterizations/'.$otherCharacterization->id.'/sector-comparison', [
        'Accept' => 'application/json',
    ])->assertOk()->assertJsonPath('data.characterization_id', $otherCharacterization->id);
    $this->actingAs($this->user)->get($this->url, ['Accept' => 'application/json'])->assertStatus(429);
    Http::assertSentCount(31);
});
