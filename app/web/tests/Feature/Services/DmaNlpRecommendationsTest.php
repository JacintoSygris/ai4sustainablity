<?php
use App\Services\Dma\DmaNlpRecommendations;
use App\Services\Report\ReportSnapshotBuilder;
use Tests\Support\DmaNlpFixture as F;

it('acceptance creates IRO annotations while rejection preserves all obligations and saved answers', function () {
    $f = F::make($this, 'hospital_waste_es'); $before = F::confirm($this, $f, [26, 27]); F::fake();
    $this->putJson('/api/esrs-datapoints/responses', ['expected_revision' => 0, 'expected_authority_digest' => $before['p9_authority_digest'],
        'responses' => [['datapoint_id' => 'BP-1_01', 'status' => 'draft', 'value' => 'Synthetic saved answer']]])->assertOk();
    $run = F::analyse($this, $f); F::review($this, $f, $run, 'E5-5_15')->assertOk();
    $after = $this->getJson('/api/esrs-datapoints')->assertOk()->json('data');
    expect($after['blocks'])->toBe($before['blocks'])->and($after['p9_authority_digest'])->toBe($before['p9_authority_digest'])
        ->and($after['iro_context_v1']['dma_nlp']['by_datapoint']['E5-5_15'][0]['source']['iro_description'])->toBe($f['case']['description']);
    $other = collect($run['candidates'])->firstWhere('level', 'topic');
    F::review($this, $f, $run, $other['id'], 'rejected')->assertOk();
    $this->getJson('/api/esrs-datapoints')->assertJsonPath('data.blocks', $before['blocks']);
    expect($f['record']->fresh()->form_data['esrs_datapoint_responses']['responses']['BP-1_01']['value'])->toBe('Synthetic saved answer');
});

it('inferred topics absent from original suggestions resolve separately through reviewed IRO provenance', function () {
    $f = F::make($this, 'laundry_discharge_es'); F::fake();
    $run = F::analyse($this, $f, 'p8');
    $candidate = collect($run['candidates'])->firstWhere('id', '13');
    expect($f['import']['suggestions'][0]['topic_id'])->toBe(11);
    F::review($this, $f, $run, '13')->assertOk();
    expect($f['record']->fresh()->form_data)->not->toHaveKey('materiality_confirmation');
    $payload = F::payload($this, $f, [11, 13]);
    $payload['dma_nlp_refs'] = [$run['id'].'|'.$candidate['candidate_id']];
    $this->putJson('/api/materiality-confirmation', $payload)->assertOk();
    $p8 = $f['record']->fresh()->form_data['materiality_confirmation'];
    expect($p8['dma_nlp_evidence'][0]['candidate']['id'])->toBe('13')
        ->and($p8['dma_nlp_evidence'][0]['reviewed_context']['iro_id'])->toBe($f['import']['iro_rows'][0]['iro_id'])
        ->and($p8['dma_import_evidence'][0]['suggestions'][0]['topic_id'])->toBe(11);
    F::review($this, $f, $run, '13')->assertConflict();
});

it('carries the actual imported source and reviewed context into a newly bound P9 run', function () {
    $f = F::make($this, 'recovered_water_es'); F::fake(); $before = F::confirm($this, $f);
    $run = F::analyse($this, $f); F::review($this, $f, $run, 'E3-4_03')->assertOk();
    $evidence = $this->getJson('/api/esrs-datapoints')->assertOk()->json('data.iro_context_v1.dma_nlp.shortlist.0');
    expect($evidence['import_id'])->toBe($f['import']['id'])
        ->and($evidence['candidate']['iro_id'])->toBe($f['import']['iro_rows'][0]['iro_id'])
        ->and($evidence['source_sha256'])->toBe($f['import']['sources']['iro_file']['sha256'])
        ->and($evidence['reviewed_context']['description'])->toBe($f['case']['description'])
        ->and($evidence['context_digest'])->toBe($before['p9_authority_digest']);
});

it('keeps emissions informational for an energy IRO until its inferred mitigation association is confirmed in P8', function () {
    $f = F::make($this, 'boiler_scope1_en'); F::fake();
    $before = F::confirm($this, $f, [2, 3]);
    $run = F::analyse($this, $f);
    expect(collect($run['candidates'])->firstWhere('id', 'E1-6_07')['in_scope'])->toBeFalse();
    F::review($this, $f, $run, 'E1-6_07')->assertUnprocessable();
    $this->assertDatabaseCount('dma_nlp_reviews', 0);
    $this->getJson('/api/esrs-datapoints')->assertJsonPath('data.iro_context_v1.dma_nlp.shortlist', []);

    $draft = F::analyse($this, $f, 'p8');
    F::review($this, $f, $draft, '2')->assertOk();
    // A draft association cannot expand the current confirmed IRO scope.
    F::review($this, $f, $run, 'E1-6_07')->assertUnprocessable();
    $candidate = collect($draft['candidates'])->firstWhere('id', '2');
    $payload = F::payload($this, $f, [2, 3]);
    $payload['dma_nlp_refs'] = [$draft['id'].'|'.$candidate['candidate_id']];
    $this->putJson('/api/materiality-confirmation', $payload)->assertOk();
    F::review($this, $f, $run, 'E1-6_07')->assertConflict();
    $current = F::analyse($this, $f);
    expect(collect($current['candidates'])->firstWhere('id', 'E1-6_07')['in_scope'])->toBeTrue();
    F::review($this, $f, $current, 'E1-6_07')->assertOk();
    $after = $this->getJson('/api/esrs-datapoints')->assertOk()->json('data');
    expect($after['blocks'])->toBe($before['blocks'])
        ->and($after['iro_context_v1']['dma_nlp']['shortlist'][0]['reviewed_context']['associated_topic_ids'])->toBe([2, 3]);
});

it('a confirmed inferred topic replaces an incorrect CSV association without declaring the source topic material', function (bool $retainSourceReference) {
    $f = F::make($this, 'laboratory_waste_es', ['declared_topic' => '26']); F::fake();
    $run = F::analyse($this, $f, 'p8');
    F::review($this, $f, $run, '27')->assertOk();
    $candidate = collect($run['candidates'])->firstWhere('id', '27');
    $payload = F::payload($this, $f, [26, 27]);
    $payload['confirmed_topic_ids'] = [27];
    $payload['iro_reviews'][0]['focus_datapoint_ids'] = ['E5-5_16'];
    // Merely supplying an IRO review cannot reassign its source topic.
    $this->putJson('/api/materiality-confirmation', $payload)->assertUnprocessable();
    $payload['dma_nlp_refs'] = [$run['id'].'|'.$candidate['candidate_id']];
    if (! $retainSourceReference) { $payload['dma_import_refs'] = []; }
    $this->putJson('/api/materiality-confirmation', $payload)->assertOk();
    $p8 = $f['record']->fresh()->form_data['materiality_confirmation'];
    $review = $p8['dma_import_evidence'][0]['iro_reviews'][0];
    expect($p8['confirmed_topic_ids'])->toBe([27])
        ->and($p8['dma_import_evidence'][0]['iro_rows'][0]['topic_id'])->toBe(26)
        ->and($review['source_topic_id'])->toBe(26)->and($review['topic_id'])->toBe(27)
        ->and($review['associated_topic_ids'])->toBe([27])
        ->and($p8['dma_nlp_evidence'][0]['reviewed_context'])->toBe($review);
    $p9run = F::analyse($this, $f);
    expect(collect($p9run['candidates'])->firstWhere('id', 'E5-5_16')['in_scope'])->toBeTrue();
    F::review($this, $f, $p9run, 'E5-5_16')->assertOk();
    $corpus = $this->getJson('/api/esrs-datapoints')->assertOk()->json('data');
    expect($corpus['iro_context_v1']['dma_nlp']['shortlist'][0]['reviewed_context']['associated_topic_ids'])->toBe([27])
        ->and($corpus['iro_context_v1']['by_datapoint']['E5-5_16'][0]['topic_id'])->toBe(27);
})->with(['source reference retained' => [true], 'inferred reference only' => [false]]);


it('accepting and rejecting NLP preserves the loaded learning authority for the next edited P9 save', function (string $decision) {
    $f = F::make($this, 'hospital_waste_es'); $loaded = F::confirm($this, $f, [26, 27]); F::fake();
    $run = F::analyse($this, $f); F::review($this, $f, $run, 'E5-5_15', $decision)->assertOk();
    $after = $this->getJson('/api/esrs-datapoints')->assertOk()->json('data');
    expect($after['learning_authority_digest'])->toBe($loaded['learning_authority_digest'])
        ->and($after['p9_authority_digest'])->toBe($loaded['p9_authority_digest']);
    $this->putJson('/api/esrs-datapoints/responses', ['expected_revision' => 0,
        'expected_authority_digest' => $loaded['p9_authority_digest'],
        'learning_feedback' => ['schema_version' => 'datapoint-feedback-v1', 'authority_digest' => $loaded['learning_authority_digest'],
            'reviewed_datapoint_ids' => ['BP-1_01'], 'decisions' => [['datapoint_id' => 'BP-1_01', 'relevant' => true,
                'selected_to_answer' => true, 'reason_codes' => [], 'note' => null]]],
        'responses' => [['datapoint_id' => 'BP-1_01', 'status' => 'completed', 'value' => 'Edited after NLP review']]])
        ->assertOk()->assertJsonPath('data.responses.BP-1_01.value', 'Edited after NLP review');
    // Excluding advisory evidence must not exclude the actual questionnaire.
    $changed = $after; $changed['blocks']['mandatory']['synthetic_integrity_probe'] = true;
    expect(app(\App\Services\EsrsDatapointResponseState::class)->learningAuthorityDigest($changed))
        ->not->toBe($loaded['learning_authority_digest']);
})->with(['accepted', 'rejected']);

it('retains persisted base IRO response authority with NLP off and still detects real P8 changes', function () {
    $f = F::make($this); config(['services.dma_nlp.enabled' => false]);
    $corpus = F::confirm($this, $f);
    // The base projector asset seal and its rules/digest algorithms stay unchanged.
    $baseProjectorHash = 'f05e0e4849fae15da400140325f5ace4183a97365f6199795f93e088e63b5471';
    expect(hash_file('sha256', app_path('Services/Dma/IroContextProjector.php')))->toBe($baseProjectorHash);
    $assets = $corpus['p9_assets'];
    $assets['rules'] = \App\Services\Dma\P8Authority::hash(['iro_context_v1', $baseProjectorHash, $corpus['iro_context_v1']['guidance_sha256']]);
    $baseDigest = app(\App\Services\Dma\P8Authority::class)->digest($f['record']->fresh(), $assets);
    expect($corpus['p9_authority_digest'])->toBe($baseDigest);
    $raw = app(\App\Services\EsrsDatapointCorpusBuilder::class)->build($f['record']->fresh());
    $baseLearningDigest = hash('sha256', json_encode(['namespace' => 'p9-workspace-v1', 'corpus' => $raw],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    expect(app(\App\Services\EsrsDatapointResponseState::class)->learningAuthorityDigest($raw))->toBe($baseLearningDigest);
    config(['services.dma_nlp.enabled' => true]);
    $enabled = app(\App\Services\EsrsDatapointCorpusBuilder::class)->build($f['record']->fresh());
    expect($enabled['p9_authority_digest'])->toBe($baseDigest)
        ->and(app(\App\Services\EsrsDatapointResponseState::class)->learningAuthorityDigest($enabled))->toBe($baseLearningDigest);
    config(['services.dma_nlp.enabled' => false]);
    $data = $f['record']->fresh()->form_data;
    $data['esrs_datapoint_responses'] = ['revision' => 1, 'p9_authority_digest' => $baseDigest,
        'responses' => ['BP-1_01' => ['datapoint_id' => 'BP-1_01', 'status' => 'completed', 'value' => 'Persisted IRO answer']]];
    $f['record']->update(['form_data' => $data]);
    $this->getJson('/api/esrs-datapoints/responses')->assertOk()
        ->assertJsonPath('data.authority_review_required', false)->assertJsonPath('data.responses.BP-1_01.status', 'completed');
    $data['materiality_confirmation']['revision']++;
    foreach ($data['materiality_confirmation']['dma_import_evidence'] as &$entry) {
        foreach ($entry['iro_reviews'] as &$review) { $review['p8_revision']++; }
        unset($review);
    }
    unset($entry);
    $f['record']->update(['form_data' => $data]);
    $this->getJson('/api/esrs-datapoints/responses')->assertOk()->assertJsonPath('data.authority_review_required', true);
});


it('confirms replacement register B with its NLP provenance while retaining register A immutable history', function () {
    $a = F::make($this, 'laboratory_waste_es', ['declared_topic' => '26']); F::fake();
    $runA = F::analyse($this, $a, 'p8'); F::review($this, $a, $runA, '27')->assertOk();
    $b = $a; $b['case'] = array_replace(F::case('hospital_waste_es'), ['declared_topic' => '26']);
    $b['import'] = F::upload($this, $b['record'], $b['case']);
    $runB = F::analyse($this, $b, 'p8'); F::review($this, $b, $runB, '27')->assertOk();
    $candidate = collect($runB['candidates'])->firstWhere('id', '27');
    $payload = F::payload($this, $b, [26, 27]);
    $payload['dma_nlp_refs'] = [$runB['id'].'|'.$candidate['candidate_id']];
    $this->putJson('/api/materiality-confirmation', $payload)->assertOk();
    $p8 = $b['record']->fresh()->form_data['materiality_confirmation'];
    expect(array_column($p8['dma_import_evidence'], 'import_id'))->toBe([$b['import']['id']])
        ->and($p8['dma_nlp_evidence'][0]['run_id'])->toBe($runB['id']);
    $this->assertDatabaseHas('dma_nlp_reviews', ['dma_nlp_run_id' => $runA['id'], 'decision' => 'accepted']);
    $this->assertDatabaseHas('dma_imports', ['id' => $a['import']['id']]);
});
