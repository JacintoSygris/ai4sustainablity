<?php
use App\Models\DmaNlpRun;
use App\Models\DmaImport;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\Support\DmaNlpFixture as F;

it('hides disabled NLP and foreign nested resources and requires authentication', function () {
    $f = F::make($this); $url = '/api/characterizations/'.$f['record']->id.'/dma-imports/'.$f['import']['id'].'/nlp-runs';
    $input = F::input($f, 'p8');
    config(['services.dma_nlp.enabled' => false]); $this->postJson($url, $input)->assertNotFound();
    config(['services.dma_nlp.enabled' => true]);
    $this->actingAs(User::factory()->create())->postJson($url, $input)->assertNotFound();
    $this->app['auth']->forgetGuards(); $this->postJson($url, $input)->assertUnauthorized();
    Http::assertNothingSent();
});

it('requires IRO prerequisites and rejects descriptions target IDs and duplicate IDs from clients', function () {
    $f = F::make($this); $url = '/api/characterizations/'.$f['record']->id.'/dma-imports/'.$f['import']['id'].'/nlp-runs'; $input = F::input($f, 'p8');
    $this->postJson($url, $input + ['description' => 'untrusted'])->assertUnprocessable();
    $this->postJson($url, array_replace($input, ['iro_ids' => [...$input['iro_ids'], ...$input['iro_ids']]]))->assertUnprocessable();
    config(['services.dma_import.iro_traceability_enabled' => false]); $this->postJson($url, $input)->assertNotFound();
});

it('uses user throttles for the NLP write read and review routes', function () {
    $f = F::make($this); $url = '/api/characterizations/'.$f['record']->id.'/dma-imports/'.$f['import']['id'].'/nlp-runs';
    for ($i = 0; $i < 5; $i++) { $this->postJson($url, [])->assertUnprocessable(); }
    $this->postJson($url, [])->assertStatus(429);
    $base = '/api/characterizations/'.$f['record']->id.'/dma-nlp-runs/99999';
    for ($i = 0; $i < 30; $i++) { $this->getJson($base)->assertNotFound(); }
    $this->getJson($base)->assertStatus(429);
    for ($i = 0; $i < 20; $i++) { $this->postJson($base.'/reviews', [])->assertNotFound(); }
    $this->postJson($base.'/reviews', [])->assertStatus(429);
});

it('rejects malformed and oversized service responses without persisting a successful abstention', function (string $defect) {
    $f = F::make($this); F::fake(function ($result) use ($defect) {
        if ($defect === 'oversize') { $result['padding'] = str_repeat('x', 262145); }
        if ($defect === 'hash') { $result['model_sha256'] = str_repeat('0', 64); }
        if ($defect === 'span') { $result['iros'][0]['levels']['datapoint']['candidates'][0]['spans'][0]['start'] = 500; }
        if ($defect === 'id') { $result['iros'][0]['levels']['datapoint']['candidates'][0]['id'] = 'not-a-datapoint'; }
        if ($defect === 'score') { $result['iros'][0]['levels']['datapoint']['candidates'][0]['score'] = 1.1; }
        return $result;
    });
    $before = $f['record']->fresh()->form_data;
    $this->postJson('/api/characterizations/'.$f['record']->id.'/dma-imports/'.$f['import']['id'].'/nlp-runs', F::input($f, 'p8'))->assertStatus(503);
    expect(DmaNlpRun::count())->toBe(0)->and($f['record']->fresh()->form_data)->toBe($before);
})->with(['oversize', 'hash', 'span', 'id', 'score']);

it('does not persist a timeout and rejects a changed context or deletion during inference', function (string $case) {
    $f = F::make($this);
    if ($case === 'timeout') { Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('synthetic timeout')); }
    else { F::fake(function ($result) use ($f, $case) {
        if ($case === 'context') { $f['record']->update(['submission_generation' => 2]); }
        else { $f['record']->delete(); }
        return $result;
    }); }
    $input = F::input($f, 'p8');
    $this->postJson('/api/characterizations/'.$f['record']->id.'/dma-imports/'.$f['import']['id'].'/nlp-runs', $input)
        ->assertStatus($case === 'timeout' ? 503 : ($case === 'context' ? 409 : 404));
    expect(DmaNlpRun::count())->toBe(0);
})->with(['timeout', 'context', 'deletion']);

it('reuses immutable successful runs and makes identical review submissions idempotent', function () {
    $f = F::make($this); F::confirm($this, $f); F::fake();
    $run = F::analyse($this, $f); $same = F::analyse($this, $f, 'p9', 200);
    expect($same['id'])->toBe($run['id']); Http::assertSentCount(1);
    expect(DmaNlpRun::count())->toBe(1)->and($same)->toBe($run);
    F::review($this, $f, $run, 'E5-5_03')->assertOk(); F::review($this, $f, $run, 'E5-5_03')->assertOk();
    F::review($this, $f, $run, 'E5-5_03', 'rejected')->assertConflict(); $this->assertDatabaseCount('dma_nlp_reviews', 1);
    $this->actingAs(User::factory()->create())->getJson('/api/characterizations/'.$f['record']->id.'/dma-nlp-runs/'.$run['id'])->assertNotFound();
});

it('rejects a review after source changes or stale P8 and cascades supported deletion', function () {
    $f = F::make($this); F::confirm($this, $f); F::fake(); $run = F::analyse($this, $f);
    $f['record']->update(['submission_generation' => 2]); F::review($this, $f, $run, 'E5-5_03')->assertConflict();
    expect(DmaNlpRun::count())->toBe(1); $f['record']->delete(); $this->assertDatabaseCount('dma_nlp_runs', 0);
});

it('change imported descriptions with profile topic and scores held fixed', function () {
    $f = F::make($this, 'orchard_irrigation_es'); F::confirm($this, $f); F::fake(); $one = F::analyse($this, $f);
    F::review($this, $f, $one, 'E3-4_01')->assertOk();
    $before = $this->getJson('/api/esrs-datapoints')->assertOk()->json('data.iro_context_v1.dma_nlp.shortlist');
    $f['case'] = F::case('laundry_discharge_es'); $f['import'] = F::upload($this, $f['record'], $f['case']);
    F::confirm($this, $f); $two = F::analyse($this, $f); F::review($this, $f, $two, 'E3-4_12')->assertOk();
    $after = $this->getJson('/api/esrs-datapoints')->assertOk()->json('data.iro_context_v1.dma_nlp.shortlist');
    expect($before[0]['candidate']['id'])->toBe('E3-4_01')->and($after)->toHaveCount(1)
        ->and($after[0]['candidate']['id'])->toBe('E3-4_12')->and($after[0]['import_id'])->toBe($f['import']['id']);
});

it('carries different indicators within unchanged E1 scope', function () {
    // Mitigation maps to both E1-5 and E1-6. Energy alone does not map to E1-6,
    // even when another confirmed topic makes it part of the overall P9 corpus.
    $f = F::make($this, 'warehouse_power_en', ['declared_topic' => '2']);
    $before = F::confirm($this, $f, [2, 3]); F::fake(); $one = F::analyse($this, $f);
    expect(collect($one['candidates'])->firstWhere('id', 'E1-5_01')['in_scope'])->toBeTrue();
    F::review($this, $f, $one, 'E1-5_01')->assertOk();
    $this->getJson('/api/esrs-datapoints')->assertJsonPath('data.iro_context_v1.dma_nlp.shortlist.0.candidate.id', 'E1-5_01');
    $f['case'] = array_replace(F::case('boiler_scope1_en'), ['declared_topic' => '2']);
    $f['import'] = F::upload($this, $f['record'], $f['case']); $after = F::confirm($this, $f, [2, 3]);
    expect($after['blocks'])->toBe($before['blocks'])->and($after['material_topic_ids'])->toBe($before['material_topic_ids'])
        ->and($f['import']['iro_rows'][0]['topic_id'])->toBe(2);
    $two = F::analyse($this, $f); F::review($this, $f, $two, 'E1-6_07')->assertOk();
    $shortlist = $this->getJson('/api/esrs-datapoints')->assertOk()->json('data.iro_context_v1.dma_nlp.shortlist');
    expect($shortlist)->toHaveCount(1)->and($shortlist[0]['candidate']['id'])->toBe('E1-6_07')
        ->and($shortlist[0]['import_id'])->toBe($f['import']['id']);
});

it('displays verifiable ES and EN spans from synthetic service responses', function (string $case) {
    $f = F::make($this, $case); F::confirm($this, $f); F::fake(); $run = F::analyse($this, $f);
    foreach ($run['candidates'] as $candidate) { foreach ($candidate['spans'] as $span) {
        expect(mb_substr($f['case']['description'], $span['start'], $span['end'] - $span['start']))->toBe($span['quote']);
    } }
})->with(['consumer_repair_es', 'consumer_repair_en']);

it('distinguishes E5-5 and changes the actual P9 shortlist', function (string $case, string $point) {
    $f = F::make($this, $case); F::confirm($this, $f, [26, 27]); F::fake(); $run = F::analyse($this, $f);
    F::review($this, $f, $run, $point)->assertOk();
    $this->getJson('/api/esrs-datapoints')->assertOk()->assertJsonPath('data.iro_context_v1.dma_nlp.shortlist.0.candidate.id', $point);
})->with([['consumer_repair_es', 'E5-5_03'], ['hospital_waste_es', 'E5-5_15'], ['laboratory_waste_es', 'E5-5_16']]);

it('enforces CSRF outside the test bypass and email verification', function () {
    $f = F::make($this); $url = '/api/characterizations/'.$f['record']->id.'/dma-imports/'.$f['import']['id'].'/nlp-runs';
    $input = F::input($f, 'p8');
    config(['services.auth_hardening.require_email_verification' => true]);
    $f['user']->forceFill(['email_verified_at' => null])->save(); $this->actingAs($f['user'])->postJson($url, $input)->assertStatus(409);
    config(['services.auth_hardening.require_email_verification' => false]);
    $this->app->instance('env', 'local'); $this->postJson($url, $input)->assertStatus(419);
    Http::assertNothingSent();
});

it('bounds successful batches per import without silently truncating IRO IDs', function () {
    $f = F::make($this); F::fake(); $input = F::input($f, 'p8');
    $url = '/api/characterizations/'.$f['record']->id.'/dma-imports/'.$f['import']['id'].'/nlp-runs';
    $this->postJson($url, array_replace($input, ['iro_ids' => array_map(fn ($i) => hash('sha256', (string) $i), range(1, 21))]))->assertUnprocessable();
    $run = F::analyse($this, $f, 'p8'); $original = DmaNlpRun::findOrFail($run['id']);
    for ($i = 1; $i < 200; $i++) {
        $clone = $original->replicate(); $clone->input_digest = hash('sha256', 'synthetic-'.$i);
        $clone->created_at = $original->created_at; $clone->save();
    }
    $this->postJson($url, array_replace($input, ['locale' => 'en']))->assertUnprocessable();
    $this->postJson($url, $input)->assertOk()->assertJsonPath('data.id', $run['id']);
    expect(DmaNlpRun::count())->toBe(200); Http::assertSentCount(1);
});

it('supported account deletion cascades imports NLP runs and reviews', function () {
    $f = F::make($this); F::confirm($this, $f); F::fake(); $run = F::analyse($this, $f);
    F::review($this, $f, $run, 'E5-5_03')->assertOk();
    $this->delete('/profile', ['password' => 'password'])->assertRedirect('/')->assertSessionHasNoErrors();
    $this->assertDatabaseCount('dma_nlp_runs', 0); $this->assertDatabaseCount('dma_nlp_reviews', 0); $this->assertDatabaseCount('dma_imports', 0);
});

it('does not accept duplicated JSON properties returned by the private service', function () {
    $f = F::make($this); Http::fake(['*' => Http::response('{"version":"bad","version":"dma-processing-v1"}', 200)]);
    $this->postJson('/api/characterizations/'.$f['record']->id.'/dma-imports/'.$f['import']['id'].'/nlp-runs', F::input($f, 'p8'))->assertStatus(503);
    $this->assertDatabaseCount('dma_nlp_runs', 0);
});

it('executes the real local Laravel Python review P9 and frozen-evidence chain when explicitly provisioned', function () {
    $f = F::make($this, 'laboratory_waste_es', ['declared_topic' => '26']);
    config(['services.characterization.api.token' => env('DMA_NLP_SYNTHETIC_TEST_TOKEN')]);
    // Deliberately no Http::fake: this lane requires the private synthetic service.
    $draft = F::analyse($this, $f, 'p8'); F::review($this, $f, $draft, '27')->assertOk();
    $candidate = collect($draft['candidates'])->firstWhere('id', '27');
    $payload = F::payload($this, $f, [26, 27]); $payload['confirmed_topic_ids'] = [27];
    $payload['iro_reviews'][0]['focus_datapoint_ids'] = ['E5-5_16'];
    $payload['dma_nlp_refs'] = [$draft['id'].'|'.$candidate['candidate_id']];
    $this->putJson('/api/materiality-confirmation', $payload)->assertOk();
    $run = F::analyse($this, $f); F::review($this, $f, $run, 'E5-5_16')->assertOk();
    $this->getJson('/api/esrs-datapoints')->assertOk()->assertJsonPath('data.iro_context_v1.dma_nlp.shortlist.0.candidate.id', 'E5-5_16');
    F::prepareReport($this, $f);
    $id = $this->postJson('/api/report/snapshot')->assertCreated()->json('data.id');
    $this->postJson('/api/report/snapshots/'.$id.'/approve', ['single_person_declaration' => 'Synthetic local NLP integration declaration'])->assertSuccessful();
    $snapshot = \App\Models\ReportSnapshot::findOrFail($id);
    $frozen = $snapshot->source_manifest['p9_context_v1']['corpus']['iro_context_v1'];
    expect($frozen['dma_nlp']['shortlist'][0]['candidate']['id'])->toBe('E5-5_16')
        ->and($frozen['dma_nlp']['shortlist'][0]['source']['topic_id'])->toBe(26)
        ->and($frozen['dma_nlp']['shortlist'][0]['reviewed_context']['associated_topic_ids'])->toBe([27]);
    config(['services.dma_nlp.enabled' => false, 'services.dma_import.iro_traceability_enabled' => false]);
    $data = $f['record']->fresh()->form_data; $data['dma_nlp_evidence'] = []; $f['record']->update(['form_data' => $data]);
    $ir = app(\App\Services\Report\ReportIrBuilder::class)->buildFromApprovedSnapshot($snapshot);
    expect($ir['iro_context_v1'])->toBe($frozen);
    $bundle = app(\App\Services\Report\EvidenceBundleBuilder::class)->build($ir);
    expect($bundle['iro_context_v1'])->toBe($frozen);
})->skip(fn () => env('DMA_NLP_SYNTHETIC_RUNTIME_TEST') !== '1', 'Requires an explicitly provisioned local synthetic Python service; never a deployed host.');
